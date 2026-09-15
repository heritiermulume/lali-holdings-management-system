import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Select } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import api from '../lib/api'
import { roleLabel } from '../lib/format'

type RoleOption = { id: number; name: string; slug?: string; module_slug?: string | null; full_access?: boolean }
type Company = {
  id: number
  name: string
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
  is_active: boolean
  roles: RoleOption[]
  assignments?: Assignment[]
  primary_company?: { name: string } | null
}
type Membership = { company_id: string; role_id: string; office_ids: number[] }

const emptyForm = () => ({
  first_name: '',
  last_name: '',
  email: '',
  platform_role_id: '',
  memberships: [{ company_id: '', role_id: '', office_ids: [] }] as Membership[],
})

export function UsersPage() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission('users.create')
  const canUpdate = hasPermission('users.update')
  const localeFr = i18n.language.startsWith('fr')
  const [rows, setRows] = useState<UserRow[]>([])
  const [roles, setRoles] = useState<RoleOption[]>([])
  const [companies, setCompanies] = useState<Company[]>([])
  const [editing, setEditing] = useState<UserRow | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(emptyForm())

  const platformRoles = useMemo(() => roles.filter((role) => role.full_access), [roles])
  const moduleRoles = useMemo(() => roles.filter((role) => !role.full_access && role.module_slug), [roles])

  const load = () => {
    api.get('/users').then((res) => setRows(res.data.data))
    api.get('/roles').then((res) => setRoles(res.data.data))
    api.get('/companies', { params: { business: 1, per_page: 50 } }).then((res) => setCompanies(res.data.data))
  }

  useEffect(() => { load() }, [])

  const rolesForCompany = (companyId: string) => {
    const company = companies.find((entry) => String(entry.id) === companyId)
    return moduleRoles.filter((role) => role.module_slug === company?.module?.slug)
  }

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm())
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
    setForm({
      first_name: row.first_name || row.name.split(' ')[0] || '',
      last_name: row.last_name || row.name.split(' ').slice(1).join(' '),
      email: row.email,
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

  const save = async () => {
    const payload = {
      first_name: form.first_name,
      last_name: form.last_name,
      email: form.email,
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
    if (editing) {
      await api.put(`/users/${editing.id}`, payload)
    } else {
      await api.post('/users', payload)
    }
    setOpen(false)
    setForm(emptyForm())
    setEditing(null)
    load()
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

  return (
    <div>
      {canCreate ? (
        <div className="mb-4 flex justify-end">
          <Button onClick={openCreate}>{t('users.create')}</Button>
        </div>
      ) : null}
      <Card className="overflow-hidden">
        <div className="hidden md:block">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-muted">
              <tr>
                <th className="px-5 py-3">{t('users.firstName')}</th>
                <th className="px-5 py-3">Email</th>
                <th className="px-5 py-3">{t('users.role')}</th>
                <th className="px-5 py-3">{t('users.company')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id} className={canUpdate ? 'cursor-pointer border-t border-line hover:bg-surface/70' : 'border-t border-line'} onClick={canUpdate ? () => openEdit(row) : undefined}>
                  <td className="px-5 py-3 font-semibold">{row.name}</td>
                  <td className="px-5 py-3">{row.email}</td>
                  <td className="px-5 py-3">
                    <div className="flex flex-wrap gap-1">
                      {assignmentLabels(row).map((label) => <Badge key={label}>{label}</Badge>)}
                    </div>
                  </td>
                  <td className="px-5 py-3">{row.primary_company?.name || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="space-y-3 p-4 md:hidden">
          {rows.map((row) => (
            <div
              key={row.id}
              role={canUpdate ? 'button' : undefined}
              tabIndex={canUpdate ? 0 : undefined}
              className="w-full rounded-xl border border-line p-4 text-left"
              onClick={canUpdate ? () => openEdit(row) : undefined}
            >
              <div className="font-semibold">{row.name}</div>
              <div className="text-sm text-muted">{row.email}</div>
              <div className="mt-2 flex flex-wrap gap-1">
                {assignmentLabels(row).map((label) => <Badge key={label}>{label}</Badge>)}
              </div>
            </div>
          ))}
        </div>
      </Card>
      <Modal open={open} title={editing ? t('users.edit') : t('users.create')} onClose={() => setOpen(false)}>
        <div className="space-y-3">
          <Field label={t('users.firstName')} value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} />
          <Field label={t('users.lastName')} value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
          <Field label="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
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
          <Button className="w-full" onClick={() => void save()}>{t('common.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
