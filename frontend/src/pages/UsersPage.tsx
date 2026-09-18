import { Download, Eye, ListFilter, Pencil, Plus, Printer, Search, Trash2, Upload } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PrintPreviewModal } from '../components/PrintPreviewModal'
import { Badge, Button, Card, Field, Modal, Select } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import api from '../lib/api'
import { cn } from '../lib/cn'
import { dateTime, initials, roleLabel } from '../lib/format'
import { buildBrandedTableHtml, printHtml } from '../lib/printDocument'

type RoleOption = { id: number; name: string; slug?: string; module_slug?: string | null; full_access?: boolean }
type Company = {
  id: number
  name: string
  code?: string
  module?: { slug: string; name: string; name_fr: string } | null
  offices?: Array<{ id: number; name: string; code: string }>
}
type Assignment = { company_id: number | null; role: RoleOption; office_ids: number[] }
type UserRow = {
  id: number
  first_name?: string
  last_name?: string
  name: string
  email: string
  phone?: string | null
  avatar_url?: string | null
  is_active: boolean
  last_login_at?: string | null
  roles: RoleOption[]
  assignments?: Assignment[]
  primary_company?: { name: string } | null
  primary_office?: { name: string } | null
}
type Membership = { company_id: string; role_id: string; office_ids: number[] }

const emptyForm = () => ({
  first_name: '',
  last_name: '',
  email: '',
  phone: '',
  is_active: true,
  platform_role_id: '',
  memberships: [{ company_id: '', role_id: '', office_ids: [] }] as Membership[],
})

const USER_IMPORT_HEADERS = [
  'first_name',
  'last_name',
  'email',
  'phone',
  'platform_role',
  'company',
  'role',
  'offices',
  'is_active',
] as const

function csvCell(value: unknown) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`
}

function downloadCsv(filename: string, lines: string[][]) {
  const body = lines.map((line) => line.map(csvCell).join(',')).join('\n')
  const blob = new Blob([`\uFEFF${body}`], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function parseTable(text: string): Array<Record<string, string>> {
  const trimmed = text.trim()
  if (!trimmed) return []
  if (trimmed.startsWith('[')) {
    const parsed = JSON.parse(trimmed) as unknown
    return Array.isArray(parsed) ? parsed as Array<Record<string, string>> : []
  }
  const lines = trimmed.split(/\r?\n/).filter(Boolean)
  const delimiter = lines[0].includes('\t') ? '\t' : ','
  const headers = lines[0].split(delimiter).map((header) => header.trim())
  return lines.slice(1).map((line) => {
    const cols = line.split(delimiter)
    const row: Record<string, string> = {}
    headers.forEach((header, index) => { row[header] = (cols[index] || '').trim() })
    return row
  })
}

function PersonPhoto({
  name,
  url,
  className = 'h-12 w-12 text-sm',
}: {
  name: string
  url?: string | null
  className?: string
}) {
  return (
    <span className={cn('grid shrink-0 overflow-hidden place-items-center rounded-full bg-lhms font-bold text-white', className)}>
      {url ? <img src={url} alt={name} className="h-full w-full object-cover" /> : initials(name)}
    </span>
  )
}

export function UsersPage() {
  const { t, i18n } = useTranslation()
  const { user, hasPermission } = useAuth()
  const canCreate = hasPermission('users.create')
  const canUpdate = hasPermission('users.update')
  const canDelete = hasPermission('users.delete')
  const localeFr = i18n.language.startsWith('fr')
  const [rows, setRows] = useState<UserRow[]>([])
  const [roles, setRoles] = useState<RoleOption[]>([])
  const [companies, setCompanies] = useState<Company[]>([])
  const [editing, setEditing] = useState<UserRow | null>(null)
  const [viewing, setViewing] = useState<UserRow | null>(null)
  const [deleting, setDeleting] = useState<UserRow | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyForm())
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [status, setStatus] = useState('')
  const [companyId, setCompanyId] = useState('')
  const [roleId, setRoleId] = useState('')
  const [filterOpen, setFilterOpen] = useState(false)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [importOpen, setImportOpen] = useState(false)
  const [importCsv, setImportCsv] = useState('')
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importError, setImportError] = useState('')
  const [importing, setImporting] = useState(false)
  const [importResult, setImportResult] = useState<{ imported: number; skipped: number; errors: Array<{ row: number; email?: string | null; message: string }> } | null>(null)
  const [deleteError, setDeleteError] = useState('')
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)

  const platformRoles = useMemo(() => roles.filter((role) => {
    if (!role.full_access) return false
    if (role.slug === 'super_administrator' && !user?.is_super_admin && form.platform_role_id !== String(role.id)) {
      return false
    }
    return true
  }), [roles, user, form.platform_role_id])
  const moduleRoles = useMemo(() => roles.filter((role) => !role.full_access && role.module_slug), [roles])
  const filtersActive = Boolean(status || companyId || roleId)

  const listParams = (term = query, extra: Record<string, string | number> = {}) => ({
    per_page: extra.per_page ?? 50,
    ...(term ? { search: term } : {}),
    ...(status ? { is_active: status } : {}),
    ...(companyId ? { company_id: companyId } : {}),
    ...(roleId ? { role_id: roleId } : {}),
    ...extra,
  })

  const load = (term = query) => api.get('/users', { params: listParams(term) }).then((res) => setRows(res.data.data))

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!filterRef.current?.contains(event.target as Node)) setFilterOpen(false)
      if (!printMenuRef.current?.contains(event.target as Node)) setPrintMenuOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  useEffect(() => {
    void load()
  }, [query, status, companyId, roleId])

  useEffect(() => {
    api.get('/roles').then((res) => setRoles(res.data.data))
    api.get('/companies', { params: { business: 1, per_page: 50 } }).then((res) => setCompanies(res.data.data))
  }, [])

  const rolesForCompany = (id: string) => {
    const company = companies.find((entry) => String(entry.id) === id)
    return moduleRoles.filter((role) => role.module_slug === company?.module?.slug)
  }

  const assignmentLabels = (row: UserRow) => {
    const items = row.assignments ?? []
    if (!items.length) return row.roles?.[0] ? [roleLabel(t, row.roles[0])] : []
    return items.map((item) => {
      const company = companies.find((entry) => entry.id === item.company_id)
      const moduleName = company?.module
        ? (localeFr ? company.module.name_fr : company.module.name)
        : t('settings.rolesAllModules')
      return `${roleLabel(t, item.role)} · ${moduleName}`
    })
  }

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm())
    setFormError('')
    setOpen(true)
  }

  const openEdit = (row: UserRow) => {
    const platform = row.assignments?.find((item) => !item.company_id)?.role
    const memberships = (row.assignments ?? [])
      .filter((item) => item.company_id)
      .map((item) => ({
        company_id: String(item.company_id),
        role_id: String(item.role.id),
        office_ids: item.office_ids ?? [],
      }))
    setEditing(row)
    setFormError('')
    setForm({
      first_name: row.first_name || row.name.split(' ')[0] || '',
      last_name: row.last_name || row.name.split(' ').slice(1).join(' '),
      email: row.email,
      phone: row.phone || '',
      is_active: row.is_active,
      platform_role_id: platform ? String(platform.id) : '',
      memberships: memberships.length ? memberships : emptyForm().memberships,
    })
    setOpen(true)
  }

  const updateMembership = (index: number, patch: Partial<Membership>) => {
    setForm((current) => ({
      ...current,
      memberships: current.memberships.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        const next = { ...item, ...patch }
        if (patch.company_id && patch.company_id !== item.company_id) {
          next.role_id = ''
          next.office_ids = []
        }
        return next
      }),
    }))
  }

  const toggleOffice = (index: number, officeId: number) => {
    setForm((current) => ({
      ...current,
      memberships: current.memberships.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        const office_ids = item.office_ids.includes(officeId)
          ? item.office_ids.filter((id) => id !== officeId)
          : [...item.office_ids, officeId]
        return { ...item, office_ids }
      }),
    }))
  }

  const save = async (event: FormEvent) => {
    event.preventDefault()
    const payload = {
      first_name: form.first_name,
      last_name: form.last_name,
      email: form.email,
      phone: form.phone || null,
      is_active: form.is_active,
      platform_role_id: form.platform_role_id ? Number(form.platform_role_id) : null,
      assignments: form.platform_role_id
        ? []
        : form.memberships
          .filter((item) => item.company_id && item.role_id)
          .map((item) => ({
            company_id: Number(item.company_id),
            role_id: Number(item.role_id),
            office_ids: item.office_ids,
          })),
    }
    setSaving(true)
    setFormError('')
    try {
      if (editing) {
        await api.put(`/users/${editing.id}`, payload)
      } else {
        await api.post('/users', payload)
      }
      setOpen(false)
      setForm(emptyForm())
      setEditing(null)
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
      const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
      setFormError(firstError || response?.message || t('users.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const removeUser = async () => {
    if (!deleting) return
    setDeleteError('')
    try {
      await api.delete(`/users/${deleting.id}`)
      setDeleting(null)
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string } } }).response?.data
      setDeleteError(response?.message || t('users.saveError'))
    }
  }

  const officeNames = (row: UserRow) => {
    const ids = (row.assignments ?? []).flatMap((item) => item.office_ids)
    if (!ids.length) return row.primary_office?.name || ''
    return companies
      .flatMap((company) => company.offices ?? [])
      .filter((office) => ids.includes(office.id))
      .map((office) => office.name)
      .join(', ') || row.primary_office?.name || ''
  }

  const platformRoleSlug = (row: UserRow) => row.assignments?.find((item) => !item.company_id)?.role.slug || ''
  const membershipRole = (row: UserRow) => row.assignments?.find((item) => item.company_id)?.role
  const companyNameFor = (row: UserRow) => {
    const assignment = row.assignments?.find((item) => item.company_id)
    return companies.find((entry) => entry.id === assignment?.company_id)?.name || row.primary_company?.name || ''
  }

  const userColumns = (row: UserRow) => [
    row.first_name || '',
    row.last_name || '',
    row.email,
    row.phone || '',
    platformRoleSlug(row),
    companyNameFor(row),
    membershipRole(row) ? roleLabel(t, membershipRole(row)) : assignmentLabels(row).join(' / '),
    officeNames(row),
    row.is_active ? t('users.active') : t('users.inactive'),
  ]

  const userHeaders = () => [
    t('users.firstName'),
    t('users.lastName'),
    t('users.email'),
    t('users.phone'),
    t('users.platformRole'),
    t('users.company'),
    t('users.role'),
    t('users.offices'),
    t('common.status'),
  ]

  const printOptions = (list: UserRow[]) => ({
    title: t('nav.users'),
    module: t('nav.administration'),
    subtitle: t('users.subtitle'),
    meta: [
      { label: t('common.filter'), value: [
        status === '1' ? t('users.active') : status === '0' ? t('users.inactive') : t('common.all'),
        companyId ? (companies.find((entry) => String(entry.id) === companyId)?.name || '') : '',
        roleId ? roleLabel(t, roles.find((role) => String(role.id) === roleId) || null) : '',
      ].filter(Boolean).join(' · ') },
      { label: t('common.records'), value: String(list.length) },
      { label: t('common.printedOn'), value: new Date().toLocaleString() },
    ],
    headers: userHeaders(),
    rows: list.map(userColumns),
    emptyLabel: t('common.empty'),
    footerNote: t('common.confidential'),
    orientation: 'landscape' as const,
  })

  const loadUsersForPrint = async () => {
    const res = await api.get('/users', { params: listParams(query, { per_page: 1000 }) })
    return (res.data.data as UserRow[]) || []
  }

  const exportUsers = async () => {
    const list = await loadUsersForPrint()
    downloadCsv(`users-${new Date().toISOString().slice(0, 10)}.csv`, [
      [...USER_IMPORT_HEADERS],
      ...list.map((row) => [
        row.first_name || '',
        row.last_name || '',
        row.email,
        row.phone || '',
        platformRoleSlug(row),
        companyNameFor(row),
        membershipRole(row)?.slug || '',
        officeNames(row),
        row.is_active ? '1' : '0',
      ]),
    ])
  }

  const printUsers = async () => {
    const list = await loadUsersForPrint()
    printHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const previewUsers = async () => {
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(rows)))
    const list = await loadUsersForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const resetImport = () => {
    setImportCsv('')
    setImportFile(null)
    setImportError('')
  }

  const submitImport = async (event: FormEvent) => {
    event.preventDefault()
    const pasted = importCsv.trim() ? parseTable(importCsv) : []
    if (!importFile && pasted.length === 0) {
      setImportError(t('users.importNeedRows'))
      return
    }
    setImporting(true)
    setImportError('')
    try {
      let res
      if (importFile) {
        const payload = new FormData()
        payload.append('file', importFile)
        res = await api.post('/users/import', payload)
      } else {
        res = await api.post('/users/import', { rows: pasted })
      }
      setImportOpen(false)
      resetImport()
      setImportResult({
        imported: res.data.data?.imported ?? 0,
        skipped: res.data.data?.skipped ?? 0,
        errors: res.data.data?.errors ?? [],
      })
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
      const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
      setImportError(firstError || response?.message || t('users.saveError'))
    } finally {
      setImporting(false)
    }
  }

  const subtitle = (row: UserRow) => [row.email, row.primary_company?.name || companyNameFor(row)].filter(Boolean).join(' · ') || '—'

  return (
    <div>
      <div className="mb-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
        <div className="relative flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-xl border border-line bg-white px-3">
          <Search size={16} className="shrink-0 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('users.search')}
            className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
          <div ref={filterRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
            <button
              type="button"
              aria-label={t('common.filter')}
              aria-expanded={filterOpen}
              className={cn(
                'grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface hover:text-ink',
                filtersActive && 'bg-lhms-soft text-lhms',
              )}
              onClick={() => setFilterOpen((current) => !current)}
            >
              <ListFilter size={16} />
            </button>
            {filterOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-72 space-y-3 rounded-xl border border-line bg-white p-3 shadow-xl">
                <Select label={t('common.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
                  <option value="">{t('common.all')}</option>
                  <option value="1">{t('users.active')}</option>
                  <option value="0">{t('users.inactive')}</option>
                </Select>
                <Select label={t('users.company')} value={companyId} onChange={(e) => setCompanyId(e.target.value)}>
                  <option value="">{t('users.allCompanies')}</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>{company.name}</option>
                  ))}
                </Select>
                <Select label={t('users.role')} value={roleId} onChange={(e) => setRoleId(e.target.value)}>
                  <option value="">{t('users.allRoles')}</option>
                  {roles.map((role) => (
                    <option key={role.id} value={role.id}>{roleLabel(t, role)}</option>
                  ))}
                </Select>
              </div>
            ) : null}
          </div>
        </div>
        <div className="flex shrink-0 flex-nowrap items-center gap-2">
          {canCreate ? (
            <Button className="shrink-0 px-3 sm:px-4" onClick={openCreate}>
              <Plus size={16} /> {t('users.create')}
            </Button>
          ) : null}
          {hasPermission('users.import') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('users.import')} onClick={() => setImportOpen(true)}>
              <Upload size={16} />
              <span className="hidden sm:inline">{t('users.import')}</span>
            </Button>
          ) : null}
          {hasPermission('users.export') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('common.export')} onClick={() => void exportUsers()}>
              <Download size={16} />
              <span className="hidden sm:inline">{t('common.export')}</span>
            </Button>
          ) : null}
          {hasPermission('users.print') || hasPermission('users.preview') ? (
            <div ref={printMenuRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
              <Button
                variant="secondary"
                className="h-11 w-11 px-0 sm:w-auto sm:px-4"
                aria-label={t('common.print')}
                aria-expanded={printMenuOpen}
                onClick={() => setPrintMenuOpen((current) => !current)}
              >
                <Printer size={16} />
                <span className="hidden sm:inline">{t('common.print')}</span>
              </Button>
              {printMenuOpen ? (
                <div className="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                  {hasPermission('users.print') ? (
                    <button
                      type="button"
                      className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                      onClick={(event) => {
                        event.stopPropagation()
                        setPrintMenuOpen(false)
                        void printUsers()
                      }}
                    >
                      <Printer size={16} className="text-lhms" /> {t('common.print')}
                    </button>
                  ) : null}
                  {hasPermission('users.preview') ? (
                    <button
                      type="button"
                      className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                      onClick={(event) => {
                        event.stopPropagation()
                        setPrintMenuOpen(false)
                        void previewUsers()
                      }}
                    >
                      <Eye size={16} className="text-lhms" /> {t('common.preview')}
                    </button>
                  ) : null}
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>

      <Card className="overflow-hidden p-0">
        {rows.length === 0 ? <p className="px-4 py-8 text-center text-sm text-muted">{t('common.empty')}</p> : null}
        {rows.map((row) => (
          <div key={row.id} className="flex items-center gap-3 border-b border-line px-4 py-3 last:border-b-0 hover:bg-surface/70">
            <PersonPhoto name={row.name} url={row.avatar_url} />
            <div className="min-w-0 flex-1">
              <div className="truncate font-semibold">{row.name}</div>
              <div className="truncate text-xs text-muted sm:text-sm">{subtitle(row)}</div>
            </div>
            <div className="hidden min-w-0 shrink items-center gap-2 sm:flex">
              <Badge tone={row.is_active ? 'green' : 'red'}>{row.is_active ? t('users.active') : t('users.inactive')}</Badge>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              <button type="button" aria-label={t('common.view')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => setViewing(row)}>
                <Eye size={16} />
              </button>
              {canUpdate ? (
                <button type="button" aria-label={t('common.edit')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => openEdit(row)}>
                  <Pencil size={16} />
                </button>
              ) : null}
              {canDelete ? (
                <button type="button" aria-label={t('common.delete')} className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-white" onClick={() => setDeleting(row)}>
                  <Trash2 size={16} />
                </button>
              ) : null}
            </div>
          </div>
        ))}
      </Card>

      <Modal open={open} title={editing ? t('users.edit') : t('users.create')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(event) => void save(event)}>
          <Field label={t('users.firstName')} value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
          <Field label={t('users.lastName')} value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />
          <Field label={t('users.email')} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
          <Field label={t('users.phone')} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <Select label={t('common.status')} value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
            <option value="1">{t('users.active')}</option>
            <option value="0">{t('users.inactive')}</option>
          </Select>
          <Select label={t('users.platformRole')} value={form.platform_role_id} onChange={(e) => setForm({ ...form, platform_role_id: e.target.value })}>
            <option value="">{t('users.noPlatformRole')}</option>
            {platformRoles.map((role) => <option key={role.id} value={role.id}>{roleLabel(t, role)}</option>)}
          </Select>
          <p className="text-xs text-muted">{t('users.platformRoleHint')}</p>

          {!form.platform_role_id ? (
          <div className="space-y-3">
            <div className="flex items-center justify-between gap-2">
              <legend className="text-sm font-medium text-ink">{t('users.memberships')}</legend>
              <Button
                type="button"
                variant="secondary"
                onClick={() => setForm((current) => ({ ...current, memberships: [...current.memberships, { company_id: '', role_id: '', office_ids: [] }] }))}
              >
                <Plus size={16} /> {t('users.addMembership')}
              </Button>
            </div>
            <p className="text-xs text-muted">{t('users.membershipsHint')}</p>
            {form.memberships.map((membership, index) => {
              const company = companies.find((entry) => String(entry.id) === membership.company_id)
              const companyRoles = rolesForCompany(membership.company_id)
              const taken = form.memberships.map((item) => item.company_id).filter(Boolean)
              return (
                <div key={index} className="space-y-2 rounded-2xl border border-line p-3">
                  <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0 flex-1">
                    <Select
                      label={t('users.company')}
                      value={membership.company_id}
                      onChange={(e) => updateMembership(index, { company_id: e.target.value })}
                    >
                      <option value="">—</option>
                      {companies.map((entry) => (
                        <option key={entry.id} value={entry.id} disabled={taken.includes(String(entry.id)) && String(entry.id) !== membership.company_id}>
                          {entry.name}{entry.module ? ` · ${localeFr ? entry.module.name_fr : entry.module.name}` : ''}
                        </option>
                      ))}
                    </Select>
                  </div>
                    {form.memberships.length > 1 ? (
                      <button type="button" className="mt-7 grid h-10 w-10 place-items-center rounded-xl text-muted hover:bg-surface" onClick={() => setForm((current) => ({ ...current, memberships: current.memberships.filter((_, itemIndex) => itemIndex !== index) }))}>
                        <Trash2 size={16} />
                      </button>
                    ) : null}
                  </div>
                  <Select
                    label={t('users.membershipRole')}
                    value={membership.role_id}
                    onChange={(e) => updateMembership(index, { role_id: e.target.value })}
                    disabled={!membership.company_id}
                  >
                    <option value="">—</option>
                    {companyRoles.map((role) => <option key={role.id} value={role.id}>{roleLabel(t, role)}</option>)}
                  </Select>
                  {!membership.company_id ? (
                    <p className="text-xs text-muted">{t('users.selectCompanyFirst')}</p>
                  ) : companyRoles.length === 0 ? (
                    <p className="text-xs text-muted">{t('users.noRolesForModule')}</p>
                  ) : null}
                  <fieldset className="space-y-1">
                    <legend className="text-sm font-medium text-ink">{t('users.offices')}</legend>
                    <div className="max-h-32 space-y-1 overflow-auto rounded-xl border border-line p-2">
                      {(company?.offices ?? []).length === 0 ? (
                        <p className="px-2 py-1 text-sm text-muted">{membership.company_id ? t('common.empty') : t('users.selectCompanyFirst')}</p>
                      ) : (company?.offices ?? []).map((office) => (
                        <label key={office.id} className="flex min-h-9 items-center gap-2 rounded-lg px-2 text-sm hover:bg-surface">
                          <input type="checkbox" checked={membership.office_ids.includes(office.id)} onChange={() => toggleOffice(index, office.id)} />
                          {office.name} <span className="text-muted">· {office.code}</span>
                        </label>
                      ))}
                    </div>
                  </fieldset>
                </div>
              )
            })}
          </div>
          ) : null}
          {formError ? <p className="text-sm text-danger">{formError}</p> : null}
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(viewing)} title={viewing?.name || t('users.details')} onClose={() => setViewing(null)}>
        {viewing ? (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              <PersonPhoto name={viewing.name} url={viewing.avatar_url} className="h-20 w-20 text-xl" />
            </div>
            <div>{t('users.email')}: <strong>{viewing.email}</strong></div>
            <div>{t('users.phone')}: <strong>{viewing.phone || '—'}</strong></div>
            <div>{t('users.company')}: <strong>{companyNameFor(viewing) || '—'}</strong></div>
            <div>{t('users.office')}: <strong>{officeNames(viewing) || viewing.primary_office?.name || '—'}</strong></div>
            <div>{t('users.role')}: <strong>{assignmentLabels(viewing).join(' · ') || '—'}</strong></div>
            <div>{t('common.status')}: <Badge tone={viewing.is_active ? 'green' : 'red'}>{viewing.is_active ? t('users.active') : t('users.inactive')}</Badge></div>
            <div>{t('users.lastLogin')}: <strong>{dateTime(viewing.last_login_at, viewing.last_login_at)}</strong></div>
            {canUpdate ? (
              <Button className="w-full" onClick={() => { setViewing(null); openEdit(viewing) }}>{t('common.edit')}</Button>
            ) : null}
          </div>
        ) : null}
      </Modal>

      <Modal open={Boolean(deleting)} title={t('users.delete')} onClose={() => { setDeleting(null); setDeleteError('') }}>
        <p className="text-sm text-muted">{t('users.deleteConfirm', { name: deleting?.name })}</p>
        {deleteError ? <p className="mt-3 text-sm text-danger">{deleteError}</p> : null}
        <div className="mt-4 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => { setDeleting(null); setDeleteError('') }}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" onClick={() => void removeUser()}>{t('common.delete')}</Button>
        </div>
      </Modal>

      <Modal open={importOpen} title={t('users.import')} onClose={() => { setImportOpen(false); resetImport() }}>
        <form className="space-y-3" onSubmit={(event) => void submitImport(event)}>
          <p className="text-sm text-muted">{t('users.importHint')}</p>
          <label className="block space-y-1.5">
            <span className="text-sm font-medium">{t('users.importFile')}</span>
            <input
              type="file"
              accept=".xlsx,.xlsm,.csv,.tsv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
              className="block w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-lhms-soft file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-lhms"
              onChange={(event) => setImportFile(event.target.files?.[0] || null)}
            />
            {importFile ? <p className="text-xs text-muted">{importFile.name}</p> : <p className="text-xs text-muted">{t('users.importFileHint')}</p>}
          </label>
          <label className="block space-y-1.5">
            <span className="text-sm font-medium">{t('users.importPaste')}</span>
            <textarea
              className="min-h-28 w-full rounded-xl border border-line px-3 py-2 font-mono text-xs outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
              placeholder={USER_IMPORT_HEADERS.join(',')}
              value={importCsv}
              onChange={(e) => setImportCsv(e.target.value)}
            />
          </label>
          {importError ? <p className="text-sm text-danger">{importError}</p> : null}
          <Button type="submit" className="w-full" disabled={importing}>{importing ? t('common.loading') : t('users.import')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(importResult)} title={t('users.importResult')} onClose={() => setImportResult(null)}>
        {importResult ? (
          <div className="space-y-3 text-sm">
            <p>{t('users.importedCount', { count: importResult.imported })}</p>
            <p>{t('users.skippedCount', { count: importResult.skipped })}</p>
            {importResult.errors.length ? (
              <ul className="max-h-48 space-y-1 overflow-auto rounded-xl border border-line p-3 text-xs">
                {importResult.errors.map((error) => (
                  <li key={`${error.row}-${error.email || ''}`}>{t('users.importRowError', { row: error.row, email: error.email || '—', message: error.message })}</li>
                ))}
              </ul>
            ) : null}
            <Button className="w-full" onClick={() => setImportResult(null)}>{t('common.close')}</Button>
          </div>
        ) : null}
      </Modal>

      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />
    </div>
  )
}
