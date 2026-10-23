import { BadgeCheck, Camera, Download, Eye, ListFilter, Pencil, Plus, Printer, Search, Trash2, Upload, UserRound, Users as UsersIcon, VenusAndMars } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PhotoSourcePicker } from '../components/PhotoSourcePicker'
import { PrintPreviewModal } from '../components/PrintPreviewModal'
import { PaginationButtons, showListPagination } from '../components/PaginationButtons'
import { Badge, Button, Card, Field, Modal, Select } from '../components/ui'
import { LoadingScreen } from '../components/LoadingScreen'
import { useAuth } from '../context/AuthContext'
import { useLiveData } from '../hooks/useLiveData'
import api from '../lib/api'
import { withBusy } from '../lib/busy'
import { trackLoadSpinner } from '../lib/dataSync'
import { cn } from '../lib/cn'
import { dateTime, initials, roleLabel } from '../lib/format'
import { buildBrandedTableHtml, printHtml } from '../lib/printDocument'

type RoleOption = { id: number; name: string; slug?: string; module_slug?: string | null; full_access?: boolean; office_scoped?: boolean }
type Company = {
  id: number
  name: string
  code?: string
  module?: { slug: string; name: string; name_fr: string } | null
  offices?: Array<{ id: number; name: string; code: string }>
}
type OfficeRole = { office_id: number; role: RoleOption }
type Assignment = { company_id: number | null; role: RoleOption | null; office_ids: number[]; offices?: OfficeRole[] }
type UserRow = {
  id: number
  first_name?: string
  last_name?: string
  gender?: string | null
  name: string
  email: string
  phone?: string | null
  avatar_url?: string | null
  is_active: boolean
  is_certified?: boolean
  can_be_certified?: boolean
  last_login_at?: string | null
  roles: RoleOption[]
  assignments?: Assignment[]
  primary_company?: { name: string } | null
  primary_office?: { name: string } | null
  account?: {
    email?: string | null
    phone?: string | null
    password?: string | null
    is_active?: boolean
  } | null
}

const CERTIFIABLE_ROLE_SLUGS = new Set(['partner', 'vice_president', 'divisionnaire', 'manager', 'assistant_manager'])

type UsersSummary = {
  total: number
  active: number
  male: number
  female: number
  unspecified: number
}
type MembershipOffice = { office_id: number; role_id: string }
type Membership = { company_id: string; role_id: string; offices: MembershipOffice[] }

const emptyMembership = (): Membership => ({ company_id: '', role_id: '', offices: [] })

const emptyForm = () => ({
  first_name: '',
  last_name: '',
  gender: '',
  email: '',
  phone: '',
  is_active: true,
  is_certified: false,
  platform_role_id: '',
  memberships: [emptyMembership()],
})

const USER_IMPORT_HEADERS = [
  'first_name',
  'last_name',
  'gender',
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

function isSuperAdminUser(row: UserRow) {
  return row.roles.some((role) => role.slug === 'super_administrator')
}

function roleIsCertifiable(role?: RoleOption | null) {
  return Boolean(role?.slug && CERTIFIABLE_ROLE_SLUGS.has(role.slug))
}

export function UsersPage() {
  const { t, i18n } = useTranslation()
  const { user, hasPermission, refresh } = useAuth()
  const canCreate = hasPermission('users.create')
  const canUpdate = hasPermission('users.update')
  const canDelete = hasPermission('users.delete')
  const localeFr = i18n.language.startsWith('fr')
  const [rows, setRows] = useState<UserRow[]>([])
  const [roles, setRoles] = useState<RoleOption[]>([])
  const [companies, setCompanies] = useState<Company[]>([])
  const [editing, setEditing] = useState<UserRow | null>(null)
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [passwordForm, setPasswordForm] = useState({ password: '', confirmation: '' })
  const [pendingPassword, setPendingPassword] = useState<string | null>(null)
  const [passwordError, setPasswordError] = useState('')
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
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [summary, setSummary] = useState<UsersSummary>({ total: 0, active: 0, male: 0, female: 0, unspecified: 0 })
  const [importResult, setImportResult] = useState<{ imported: number; skipped: number; errors: Array<{ row: number; email?: string | null; message: string }> } | null>(null)
  const [deleteError, setDeleteError] = useState('')
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [photoRemoved, setPhotoRemoved] = useState(false)
  const [photoError, setPhotoError] = useState('')

  const platformRoles = useMemo(() => roles.filter((role) => {
    if (!role.full_access) return false
    if (role.slug === 'super_administrator' && !user?.is_super_admin && form.platform_role_id !== String(role.id)) {
      return false
    }
    return true
  }), [roles, user, form.platform_role_id])
  const moduleRoles = useMemo(() => roles.filter((role) => !role.full_access && role.module_slug), [roles])
  const formCanBeCertified = useMemo(() => {
    const platform = roles.find((role) => String(role.id) === form.platform_role_id)
    if (roleIsCertifiable(platform)) return true
    for (const membership of form.memberships) {
      const companyRole = roles.find((role) => String(role.id) === membership.role_id)
      if (roleIsCertifiable(companyRole)) return true
      for (const office of membership.offices) {
        const officeRole = roles.find((role) => String(role.id) === office.role_id)
        if (roleIsCertifiable(officeRole)) return true
      }
    }
    return false
  }, [form.memberships, form.platform_role_id, roles])
  const filtersActive = Boolean(status || companyId || roleId)
  const safePage = Math.min(page, lastPage)
  const rangeStart = total === 0 ? 0 : (safePage - 1) * perPage + 1
  const rangeEnd = Math.min(safePage * perPage, total)

  const listParams = (term = query, extra: Record<string, string | number> = {}) => ({
    page: extra.page ?? page,
    per_page: extra.per_page ?? perPage,
    ...(term ? { search: term } : {}),
    ...(status ? { is_active: status } : {}),
    ...(companyId ? { company_id: companyId } : {}),
    ...(roleId ? { role_id: roleId } : {}),
    ...extra,
  })

  const load = (term = query, nextPage = page, nextPerPage = perPage) => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/users', { params: listParams(term, { page: nextPage, per_page: nextPerPage }) })
      .then((res) => {
        const nextLast = Math.max(1, Number(res.data.meta?.last_page || 1))
        const current = Number(res.data.meta?.current_page || nextPage)
        const nextSummary = res.data.meta?.summary
        setRows(res.data.data ?? [])
        setLastPage(nextLast)
        setTotal(Number(res.data.meta?.total || 0))
        if (nextSummary) {
          setSummary({
            total: Number(nextSummary.total || 0),
            active: Number(nextSummary.active || 0),
            male: Number(nextSummary.male || 0),
            female: Number(nextSummary.female || 0),
            unspecified: Number(nextSummary.unspecified || 0),
          })
        }
        if (current > nextLast) setPage(nextLast)
        else if (current !== page) setPage(current)
      })
      .finally(stop)
  }

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
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
    void load(query, page, perPage)
  }, [query, status, companyId, roleId, page, perPage])

  useLiveData(() => load(query, page, perPage))

  useEffect(() => {
    api.get('/roles').then((res) => setRoles(res.data.data))
    api.get('/companies', { params: { business: 1, per_page: 50 } }).then((res) => setCompanies(res.data.data))
  }, [])

  useEffect(() => {
    if (!formCanBeCertified && form.is_certified) {
      setForm((current) => ({ ...current, is_certified: false }))
    }
  }, [formCanBeCertified, form.is_certified])

  const rolesForCompany = (id: string) => {
    const company = companies.find((entry) => String(entry.id) === id)
    return moduleRoles.filter((role) => role.module_slug === company?.module?.slug)
  }

  const assignmentLabels = (row: UserRow) => {
    const items = row.assignments ?? []
    if (!items.length) return row.roles?.[0] ? [roleLabel(t, row.roles[0])] : []
    const officeName = (id: number) => companies.flatMap((company) => company.offices ?? []).find((office) => office.id === id)?.name
    return items.flatMap((item) => {
      if (!item.company_id) return item.role ? [roleLabel(t, item.role)] : []
      const company = companies.find((entry) => entry.id === item.company_id)
      const moduleName = company?.module
        ? (localeFr ? company.module.name_fr : company.module.name)
        : t('settings.rolesAllModules')
      const labels: string[] = []
      if (item.role) {
        labels.push(`${roleLabel(t, item.role)} · ${moduleName}`)
      }
      for (const office of item.offices ?? []) {
        labels.push(`${roleLabel(t, office.role)} · ${officeName(office.office_id) || moduleName}`)
      }
      return labels.length ? labels : (item.role ? [`${roleLabel(t, item.role)} · ${moduleName}`] : [])
    })
  }

  const resetPhoto = (url?: string | null) => {
    setPhoto(null)
    setPhotoRemoved(false)
    setPhotoError('')
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return url || null
    })
  }

  const pickPhoto = (file?: File | null) => {
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setPhotoError(t('users.photoError'))
      return
    }
    setPhotoError('')
    setPhoto(file)
    setPhotoRemoved(false)
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return URL.createObjectURL(file)
    })
  }

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm())
    setFormError('')
    resetPhoto()
    setOpen(true)
  }

  const openEdit = (row: UserRow) => {
    const platform = row.assignments?.find((item) => !item.company_id)?.role
    const memberships = (row.assignments ?? [])
      .filter((item) => item.company_id)
      .map((item) => ({
        company_id: String(item.company_id),
        role_id: item.role && item.role.office_scoped === false ? String(item.role.id) : '',
        offices: item.offices?.length
          ? item.offices.map((office) => ({ office_id: office.office_id, role_id: String(office.role.id) }))
          : (item.office_ids ?? []).map((officeId) => ({ office_id: officeId, role_id: item.role ? String(item.role.id) : '' })),
      }))
    setEditing(row)
    setFormError('')
    resetPhoto(row.avatar_url)
    setForm({
      first_name: row.first_name || row.name.split(' ')[0] || '',
      last_name: row.last_name || row.name.split(' ').slice(1).join(' '),
      gender: row.gender === 'male' || row.gender === 'female' ? row.gender : '',
      email: row.email,
      phone: row.phone || '',
      is_active: row.is_active,
      is_certified: Boolean(row.is_certified),
      platform_role_id: platform ? String(platform.id) : '',
      memberships: memberships.length ? memberships : emptyForm().memberships,
    })
    setOpen(true)
  }

  const openView = async (row: UserRow) => {
    setViewing(row)
    try {
      const { data } = await api.get(`/users/${row.id}`)
      if (data.data) setViewing(data.data)
    } catch {
      // Keep the list snapshot if the detail request fails.
    }
  }

  const updateMembership = (index: number, patch: Partial<Membership>) => {
    setForm((current) => ({
      ...current,
      memberships: current.memberships.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        const next = { ...item, ...patch }
        if (patch.company_id && patch.company_id !== item.company_id) {
          next.role_id = ''
          next.offices = []
        }
        return next
      }),
    }))
  }

  const addOfficeRow = (index: number) => {
    setForm((current) => ({
      ...current,
      memberships: current.memberships.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        if (item.offices.some((office) => !office.office_id)) return item
        return { ...item, offices: [...item.offices, { office_id: 0, role_id: '' }] }
      }),
    }))
  }

  const updateOfficeRow = (index: number, officeIndex: number, patch: Partial<MembershipOffice>) => {
    setForm((current) => ({
      ...current,
      memberships: current.memberships.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        return {
          ...item,
          offices: item.offices.map((office, currentIndex) => (
            currentIndex === officeIndex ? { ...office, ...patch } : office
          )),
        }
      }),
    }))
  }

  const removeOfficeRow = (index: number, officeIndex: number) => {
    setForm((current) => ({
      ...current,
      memberships: current.memberships.map((item, itemIndex) => {
        if (itemIndex !== index) return item
        return { ...item, offices: item.offices.filter((_, currentIndex) => currentIndex !== officeIndex) }
      }),
    }))
  }

  const save = async (event: FormEvent) => {
    event.preventDefault()
    const firstName = form.first_name.trim()
    const lastName = form.last_name.trim()
    const email = form.email.trim()
    if (!firstName || !lastName || !email) {
      setFormError(t('users.requiredFields'))
      return
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      setFormError(t('users.invalidEmail'))
      return
    }
    if (!form.platform_role_id) {
      if (form.memberships.some((item) => item.offices.some((office) => !office.office_id || !office.role_id))) {
        setFormError(t('users.officeRoleRequired'))
        return
      }
      const completeMemberships = form.memberships.filter((item) => (
        item.company_id && (item.role_id || item.offices.some((office) => office.office_id && office.role_id))
      ))
      if (!completeMemberships.length) {
        setFormError(t('users.membershipRequired'))
        return
      }
      if (form.memberships.some((item) => item.company_id && !item.role_id && item.offices.length === 0)) {
        setFormError(t('users.membershipRequired'))
        return
      }
    }
    const assignments = form.platform_role_id
      ? []
      : form.memberships
        .filter((item) => item.company_id && (item.role_id || item.offices.some((office) => office.role_id)))
        .map((item) => ({
          company_id: Number(item.company_id),
          role_id: item.role_id ? Number(item.role_id) : null,
          offices: item.offices
            .filter((office) => office.office_id && office.role_id)
            .map((office) => ({ office_id: office.office_id, role_id: Number(office.role_id) })),
        }))
    const payload = new FormData()
    payload.append('first_name', firstName)
    payload.append('last_name', lastName)
    payload.append('gender', form.gender || '')
    payload.append('email', email)
    payload.append('phone', form.phone.trim())
    payload.append('is_active', form.is_active ? '1' : '0')
    payload.append('is_certified', formCanBeCertified && form.is_certified ? '1' : '0')
    payload.append('platform_role_id', form.platform_role_id || '')
    payload.append('assignments', JSON.stringify(assignments))
    if (photo) payload.append('avatar', photo)
    if (editing && photoRemoved && !photo) payload.append('remove_avatar', '1')
    setSaving(true)
    setFormError('')
    try {
      if (editing) {
        payload.append('_method', 'PUT')
        await api.post(`/users/${editing.id}`, payload)
        if (pendingPassword) {
          await api.post(`/users/${editing.id}/password`, {
            password: pendingPassword,
            password_confirmation: pendingPassword,
          })
        }
        if (editing.id === user?.id) {
          await refresh().catch(() => {})
        }
      } else {
        await api.post('/users', payload)
      }
      setOpen(false)
      setForm(emptyForm())
      setEditing(null)
      setPendingPassword(null)
      setPasswordOpen(false)
      resetPhoto()
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
    const ids = (row.assignments ?? []).flatMap((item) => (
      item.offices?.length
        ? item.offices.map((office) => office.office_id)
        : (item.office_ids ?? [])
    ))
    if (!ids.length) return row.primary_office?.name || ''
    return companies
      .flatMap((company) => company.offices ?? [])
      .filter((office) => ids.includes(office.id))
      .map((office) => office.name)
      .join(', ') || row.primary_office?.name || ''
  }

  const platformRoleSlug = (row: UserRow) => row.assignments?.find((item) => !item.company_id)?.role?.slug || ''
  const membershipRole = (row: UserRow) => row.assignments?.find((item) => item.company_id)?.role
  const companyNameFor = (row: UserRow) => {
    const assignment = row.assignments?.find((item) => item.company_id)
    return companies.find((entry) => entry.id === assignment?.company_id)?.name || row.primary_company?.name || ''
  }

  const genderLabel = (value?: string | null) => {
    if (value === 'male') return t('users.genderMale')
    if (value === 'female') return t('users.genderFemale')
    return t('users.genderUnspecified')
  }

  const userColumns = (row: UserRow) => [
    row.first_name || '',
    row.last_name || '',
    genderLabel(row.gender),
    row.email,
    row.phone || '',
    platformRoleSlug(row),
    companyNameFor(row),
    assignmentLabels(row).join(' / ') || (membershipRole(row) ? roleLabel(t, membershipRole(row)) : ''),
    officeNames(row),
    row.is_active ? t('users.active') : t('users.inactive'),
  ]

  const userHeaders = () => [
    t('users.firstName'),
    t('users.lastName'),
    t('users.gender'),
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
    return withBusy('data', async () => {
      const res = await api.get('/users', { params: listParams(query, { per_page: 1000 }) })
      return (res.data.data as UserRow[]) || []
    })
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
        membershipRole(row)?.slug || assignmentLabels(row).join(' / '),
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
      const res = await withBusy('import', async () => {
        if (importFile) {
          const payload = new FormData()
          payload.append('file', importFile)
          return api.post('/users/import', payload)
        }
        return api.post('/users/import', { rows: pasted })
      })
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
      <div className="mb-4 grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
        <Card className="p-3 sm:p-4">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted sm:text-sm">{t('users.statsTotal')}</span>
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-lhms-soft text-lhms"><UsersIcon size={16} /></span>
          </div>
          <div className="mt-2 text-2xl font-extrabold sm:text-3xl">{summary.total}</div>
        </Card>
        <Card className="p-3 sm:p-4">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted sm:text-sm">{t('users.statsActive')}</span>
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-lhms-soft text-lhms"><UserRound size={16} /></span>
          </div>
          <div className="mt-2 text-2xl font-extrabold text-lhms sm:text-3xl">{summary.active}</div>
        </Card>
        <Card className="p-3 sm:p-4">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted sm:text-sm">{t('users.statsMale')}</span>
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-lhms-soft text-lhms"><VenusAndMars size={16} /></span>
          </div>
          <div className="mt-2 text-2xl font-extrabold sm:text-3xl">{summary.male}</div>
        </Card>
        <Card className="p-3 sm:p-4">
          <div className="flex items-center justify-between gap-2">
            <span className="text-xs text-muted sm:text-sm">{t('users.statsFemale')}</span>
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-lhms-soft text-lhms"><VenusAndMars size={16} /></span>
          </div>
          <div className="mt-2 text-2xl font-extrabold sm:text-3xl">{summary.female}</div>
          {summary.unspecified > 0 ? (
            <div className="mt-1 text-xs text-muted">{t('users.genderUnspecified')}: {summary.unspecified}</div>
          ) : null}
        </Card>
      </div>

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
                <Select label={t('common.status')} value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
                  <option value="">{t('common.all')}</option>
                  <option value="1">{t('users.active')}</option>
                  <option value="0">{t('users.inactive')}</option>
                </Select>
                <Select label={t('users.company')} value={companyId} onChange={(e) => { setCompanyId(e.target.value); setPage(1) }}>
                  <option value="">{t('users.allCompanies')}</option>
                  {companies.map((company) => (
                    <option key={company.id} value={company.id}>{company.name}</option>
                  ))}
                </Select>
                <Select label={t('users.role')} value={roleId} onChange={(e) => { setRoleId(e.target.value); setPage(1) }}>
                  <option value="">{t('users.allRoles')}</option>
                  {roles.map((role) => (
                    <option key={role.id} value={role.id}>{roleLabel(t, role)}</option>
                  ))}
                </Select>
              </div>
            ) : null}
          </div>
        </div>
        <div className="flex w-full shrink-0 flex-nowrap items-center gap-2 sm:w-auto">
          {canCreate ? (
            <Button className="shrink-0 px-3 sm:px-4" onClick={openCreate}>
              <Plus size={16} /> {t('users.create')}
            </Button>
          ) : null}
          <div className="ml-auto flex shrink-0 flex-nowrap items-center gap-2">
          {hasPermission('users.import') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('users.import')} onClick={() => setImportOpen(true)}>
              <Upload size={16} />
              <span className="hidden sm:inline">{t('users.import')}</span>
            </Button>
          ) : null}
          {hasPermission('users.export') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('common.export')} disabled={total === 0} onClick={() => void exportUsers()}>
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
                disabled={total === 0}
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
      </div>

      <Card className="overflow-hidden p-0">
        {loading && rows.length === 0 ? <LoadingScreen /> : (
          <>
            {rows.length === 0 ? <p className="px-4 py-8 text-center text-sm text-muted">{t('common.empty')}</p> : null}
            {rows.map((row) => (
          <div key={row.id} className="flex items-center gap-3 border-b border-line px-4 py-3 last:border-b-0 hover:bg-surface/70">
            <PersonPhoto name={row.name} url={row.avatar_url} />
            <div className="min-w-0 flex-1">
              <div className="truncate font-semibold">{row.name}</div>
              <div className="truncate text-xs text-muted sm:text-sm">{subtitle(row)}</div>
            </div>
            <div className="hidden min-w-0 shrink items-center gap-2 sm:flex">
              {row.is_certified ? (
                <BadgeCheck size={18} className="shrink-0 text-[#1D9BF0]" fill="currentColor" stroke="white" aria-label={t('users.certified')} />
              ) : null}
              <Badge tone={row.is_active ? 'green' : 'red'}>{row.is_active ? t('users.active') : t('users.inactive')}</Badge>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              <button type="button" aria-label={t('common.view')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => void openView(row)}>
                <Eye size={16} />
              </button>
              {canUpdate ? (
                <button type="button" aria-label={t('common.edit')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => openEdit(row)}>
                  <Pencil size={16} />
                </button>
              ) : null}
              {canDelete && !isSuperAdminUser(row) ? (
                <button type="button" aria-label={t('common.delete')} className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-white" onClick={() => setDeleting(row)}>
                  <Trash2 size={16} />
                </button>
              ) : null}
            </div>
          </div>
        ))}
            {showListPagination(total) ? <div className="flex flex-col gap-3 border-t border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-muted">{t('common.pageRange', { from: rangeStart, to: rangeEnd, total })}</p>
              <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2 text-sm text-muted">
                  {t('common.rowsPerPage')}
                  <select
                    value={perPage}
                    onChange={(event) => {
                      setPerPage(Number(event.target.value))
                      setPage(1)
                    }}
                    className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink"
                  >
                    {[10, 20, 50, 100].map((size) => (
                      <option key={size} value={size}>{size}</option>
                    ))}
                  </select>
                </label>
                <PaginationButtons
                  page={safePage}
                  lastPage={lastPage}
                  disabled={loading}
                  onPrev={() => setPage((value) => Math.max(1, value - 1))}
                  onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
                />
              </div>
            </div> : null}
          </>
        )}
      </Card>

      <Modal open={open} title={editing ? t('users.edit') : t('users.create')} onClose={() => { setOpen(false); setPendingPassword(null); setPasswordOpen(false); resetPhoto() }}>
        <form className="space-y-3" onSubmit={(event) => void save(event)}>
          <div className="flex flex-col items-center gap-2 pb-1">
            <PhotoSourcePicker onFile={pickPhoto} title={t('users.photo')}>
              {(openPhotoMenu) => (
                <div className="relative h-24 w-24">
                  <button type="button" onClick={openPhotoMenu} aria-label={t('users.photo')} className="block h-24 w-24 overflow-hidden rounded-full p-0">
                    {photoPreview || form.first_name || form.last_name ? (
                      <PersonPhoto name={`${form.first_name} ${form.last_name}`.trim() || t('users.create')} url={photoPreview} className="h-24 w-24 text-2xl" />
                    ) : (
                      <span className="grid h-24 w-24 place-items-center rounded-full bg-lhms text-white"><Camera size={28} /></span>
                    )}
                  </button>
                  {photoPreview ? (
                    <button
                      type="button"
                      aria-label={t('users.removePhoto')}
                      className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
                      onClick={() => {
                        setPhoto(null)
                        setPhotoRemoved(true)
                        setPhotoError('')
                        setPhotoPreview((current) => {
                          if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
                          return null
                        })
                      }}
                    >
                      <Trash2 size={14} />
                    </button>
                  ) : null}
                  <button type="button" aria-label={t('users.photo')} className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md" onClick={openPhotoMenu}>
                    <Camera size={14} />
                  </button>
                </div>
              )}
            </PhotoSourcePicker>
            <p className="text-center text-xs text-muted">{t('users.photoHint')}</p>
            {photoError ? <p className="text-center text-xs text-danger">{photoError}</p> : null}
          </div>
          <Field label={t('users.firstName')} value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
          <Field label={t('users.lastName')} value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />
          <Select label={t('users.gender')} value={form.gender} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
            <option value="">{t('users.genderNone')}</option>
            <option value="male">{t('users.genderMale')}</option>
            <option value="female">{t('users.genderFemale')}</option>
          </Select>
          <Field label={t('users.email')} type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required />
          <Field label={t('users.phone')} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <div className="flex items-end gap-3">
            <div className="min-w-0 flex-1">
              <Select label={t('common.status')} value={form.is_active ? '1' : '0'} onChange={(e) => setForm({ ...form, is_active: e.target.value === '1' })}>
                <option value="1">{t('users.active')}</option>
                <option value="0">{t('users.inactive')}</option>
              </Select>
            </div>
            {editing ? (
              <Button type="button" variant="secondary" className="shrink-0" onClick={() => { setPasswordForm({ password: pendingPassword || '', confirmation: pendingPassword || '' }); setPasswordError(''); setPasswordOpen(true) }}>
                {t('auth.changePassword')}
              </Button>
            ) : null}
          </div>
          {formCanBeCertified ? (
            <div className="space-y-1.5">
              <Select
                label={t('users.certified')}
                value={form.is_certified ? '1' : '0'}
                onChange={(e) => setForm({ ...form, is_certified: e.target.value === '1' })}
              >
                <option value="1">{t('users.certified')}</option>
                <option value="0">{t('users.notCertified')}</option>
              </Select>
              <p className="text-xs text-muted">{t('users.certifiedHint')}</p>
            </div>
          ) : null}
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
                onClick={() => setForm((current) => ({ ...current, memberships: [...current.memberships, emptyMembership()] }))}
              >
                <Plus size={16} /> {t('users.addMembership')}
              </Button>
            </div>
            <p className="text-xs text-muted">{t('users.membershipsHint')}</p>
            {form.memberships.map((membership, index) => {
              const company = companies.find((entry) => String(entry.id) === membership.company_id)
              const companyRoles = rolesForCompany(membership.company_id)
              const companyWideRoles = companyRoles.filter((role) => role.office_scoped === false)
              const officeList = company?.offices ?? []
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
                  {companyWideRoles.length > 0 ? (
                    <>
                      <Select
                        label={t('users.membershipRole')}
                        value={membership.role_id}
                        onChange={(e) => updateMembership(index, { role_id: e.target.value })}
                        disabled={!membership.company_id}
                      >
                        <option value="">—</option>
                        {companyWideRoles.map((role) => <option key={role.id} value={role.id}>{roleLabel(t, role)}</option>)}
                      </Select>
                      <p className="text-xs text-muted">{t('users.membershipRoleHint')}</p>
                    </>
                  ) : null}
                  {!membership.company_id ? (
                    <p className="text-xs text-muted">{t('users.selectCompanyFirst')}</p>
                  ) : companyRoles.length === 0 ? (
                    <p className="text-xs text-muted">{t('users.noRolesForModule')}</p>
                  ) : null}
                  <fieldset className="space-y-2">
                    <legend className="text-sm font-medium text-ink">{t('users.offices')}</legend>
                    <p className="text-xs text-muted">{t('users.officesHint')}</p>
                    {membership.offices.map((assigned, officeIndex) => {
                      const takenOffices = membership.offices.map((office) => office.office_id).filter(Boolean)
                      return (
                        <div key={officeIndex} className="flex items-start gap-2 rounded-xl border border-line p-2">
                          <div className="min-w-0 flex-1 space-y-2">
                            <Select
                              label={t('users.office')}
                              value={assigned.office_id ? String(assigned.office_id) : ''}
                              onChange={(e) => updateOfficeRow(index, officeIndex, { office_id: Number(e.target.value) || 0 })}
                              disabled={!membership.company_id}
                            >
                              <option value="">—</option>
                              {officeList.map((office) => (
                                <option
                                  key={office.id}
                                  value={office.id}
                                  disabled={takenOffices.includes(office.id) && office.id !== assigned.office_id}
                                >
                                  {office.name} · {office.code}
                                </option>
                              ))}
                            </Select>
                            <Select
                              label={t('users.officeRole')}
                              value={assigned.role_id}
                              onChange={(e) => updateOfficeRow(index, officeIndex, { role_id: e.target.value })}
                              disabled={!assigned.office_id || companyRoles.length === 0}
                            >
                              <option value="">—</option>
                              {companyRoles.map((role) => <option key={role.id} value={role.id}>{roleLabel(t, role)}</option>)}
                            </Select>
                          </div>
                          <button
                            type="button"
                            className="mt-7 grid h-10 w-10 place-items-center rounded-xl text-muted hover:bg-surface"
                            aria-label={t('common.delete')}
                            onClick={() => removeOfficeRow(index, officeIndex)}
                          >
                            <Trash2 size={16} />
                          </button>
                        </div>
                      )
                    })}
                    <Button
                      type="button"
                      variant="secondary"
                      className="w-full"
                      disabled={!membership.company_id || officeList.length === 0 || membership.offices.some((office) => !office.office_id) || membership.offices.length >= officeList.length}
                      onClick={() => addOfficeRow(index)}
                    >
                      <Plus size={16} /> {t('users.addOffice')}
                    </Button>
                    {!membership.company_id ? (
                      <p className="text-xs text-muted">{t('users.selectCompanyFirst')}</p>
                    ) : officeList.length === 0 ? (
                      <p className="text-xs text-muted">{t('common.empty')}</p>
                    ) : membership.offices.length >= officeList.length ? (
                      <p className="text-xs text-muted">{t('users.allOfficesAssigned')}</p>
                    ) : membership.offices.length === 0 ? (
                      <p className="text-xs text-muted">{t('users.noOfficesAdded')}</p>
                    ) : null}
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

      <Modal open={passwordOpen} title={t('auth.changePassword')} onClose={() => setPasswordOpen(false)}>
        <form
          className="space-y-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (passwordForm.password !== passwordForm.confirmation) {
              setPasswordError(t('auth.passwordMismatch'))
              return
            }
            setPendingPassword(passwordForm.password)
            setPasswordError('')
            setPasswordOpen(false)
          }}
        >
          <Field label={t('auth.newPassword')} type="password" required value={passwordForm.password} onChange={(e) => setPasswordForm({ ...passwordForm, password: e.target.value })} autoComplete="new-password" />
          <Field label={t('auth.confirmPassword')} type="password" required value={passwordForm.confirmation} onChange={(e) => setPasswordForm({ ...passwordForm, confirmation: e.target.value })} autoComplete="new-password" />
          {passwordError ? <p className="text-sm text-danger">{passwordError}</p> : null}
          <Button type="submit" className="w-full">{t('common.validate')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(viewing)} title={t('users.user')} onClose={() => setViewing(null)}>
        {viewing ? (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              <PersonPhoto name={viewing.name} url={viewing.avatar_url} className="h-20 w-20 text-xl" />
            </div>
            <div>{t('users.name')}: <strong>{viewing.name}</strong></div>
            <div>{t('users.gender')}: <strong>{genderLabel(viewing.gender)}</strong></div>
            <div>{t('users.email')}: <strong>{viewing.email}</strong></div>
            <div>{t('users.phone')}: <strong>{viewing.phone || '—'}</strong></div>
            <div>{t('users.company')}: <strong>{companyNameFor(viewing) || '—'}</strong></div>
            <div>{t('users.office')}: <strong>{officeNames(viewing) || viewing.primary_office?.name || '—'}</strong></div>
            <div>
              <div className="mb-1">{t('users.role')}</div>
              {assignmentLabels(viewing).length ? (
                <ul className="space-y-1">
                  {assignmentLabels(viewing).map((label, index) => (
                    <li key={`${label}-${index}`}><strong>{label}</strong></li>
                  ))}
                </ul>
              ) : <strong>—</strong>}
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span>{t('common.status')}:</span>
              {viewing.is_certified ? (
                <BadgeCheck size={18} className="shrink-0 text-[#1D9BF0]" fill="currentColor" stroke="white" aria-label={t('users.certified')} />
              ) : null}
              <Badge tone={viewing.is_active ? 'green' : 'red'}>{viewing.is_active ? t('users.active') : t('users.inactive')}</Badge>
            </div>
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
          <Button type="submit" className="w-full" disabled={importing}>{importing ? t('common.importing') : t('users.import')}</Button>
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
