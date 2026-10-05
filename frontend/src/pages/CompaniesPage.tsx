import { Ban, MapPin, Pencil, Plus, Settings, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { SearchCombobox, type SearchComboboxItem } from '../components/SearchCombobox'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../components/ui'
import { LoadingScreen } from '../components/LoadingScreen'
import { useAuth } from '../context/AuthContext'
import { cn } from '../lib/cn'
import api from '../lib/api'
import { businessModuleIcon } from '../modules/registry'

type Geo = { id: number; name: string; name_fr?: string }

type PersonRef = { id: number; name: string }
type Office = {
  id: number
  name: string
  code: string
  type: string
  address?: string | null
  phone?: string | null
  is_active: boolean
  parent_office_id?: number | null
  creator_user_id?: number | null
  manager_user_id?: number | null
  recorded_by_user_id?: number | null
  parent?: { id: number; name: string; code: string } | null
  creator?: PersonRef | null
  manager?: PersonRef | null
  recorded_by?: PersonRef | null
  country?: Geo | null
  province?: Geo | null
  city?: Geo | null
  commune?: Geo | null
  neighborhood?: Geo | null
}

type Company = {
  id: number
  name: string
  legal_name?: string | null
  code: string
  email?: string | null
  is_active: boolean
  offices_count?: number
  module?: { id: number; slug: string; name: string; name_fr: string; status: string; sort_order?: number } | null
  offices?: Office[]
}

const emptyOfficeForm = {
  name: '',
  code: '',
  type: 'office',
  address: '',
  phone: '',
  country_id: '',
  province_id: '',
  city_id: '',
  commune_id: '',
  neighborhood_id: '',
  parent_office_id: '',
  creator_user_id: '',
  manager_user_id: '',
}

function geoName(item: Geo | null | undefined, locale: string) {
  if (!item) return ''
  return locale.startsWith('fr') ? (item.name_fr || item.name) : item.name
}

function geoId(item: Geo | null | undefined) {
  return item?.id ? String(item.id) : ''
}

function officeLocation(office: Office, locale: string) {
  return [office.neighborhood, office.commune, office.city, office.province, office.country]
    .map((item) => geoName(item, locale))
    .filter(Boolean)
    .join(' · ')
}

function idOrNull(value: string) {
  return value ? Number(value) : null
}

function officeRoots(offices: Office[]) {
  return offices.filter((office) => !office.parent_office_id || !offices.some((item) => item.id === office.parent_office_id))
}

function officeChildren(offices: Office[], parentId: number) {
  return offices.filter((office) => office.parent_office_id === parentId)
}

export function CompaniesPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canSettings = hasPermission('settings.manage')
  const canCreate = hasPermission('organization.create')
  const canUpdate = hasPermission('organization.update')
  const canDelete = hasPermission('organization.delete')
  const locale = i18n.language
  const [rows, setRows] = useState<Company[]>([])
  const [countries, setCountries] = useState<Geo[]>([])
  const [provinces, setProvinces] = useState<Geo[]>([])
  const [cities, setCities] = useState<Geo[]>([])
  const [communes, setCommunes] = useState<Geo[]>([])
  const [neighborhoods, setNeighborhoods] = useState<Geo[]>([])
  const [open, setOpen] = useState(false)
  const [company, setCompany] = useState<Company | null>(null)
  const [editing, setEditing] = useState<Office | null>(null)
  const [deleting, setDeleting] = useState<{ company: Company; office: Office } | null>(null)
  const [toggling, setToggling] = useState<{ company: Company; office: Office } | null>(null)
  const [form, setForm] = useState(emptyOfficeForm)
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const [people, setPeople] = useState<SearchComboboxItem[]>([])
  const [parentOptions, setParentOptions] = useState<Array<{ id: number; name: string; code: string }>>([])
  const [officeTypes, setOfficeTypes] = useState<string[]>(['headquarters', 'office', 'agency', 'branch'])

  const load = () => {
    setLoading(true)
    api.get('/companies', { params: { business: 1, per_page: 50 } })
      .then((res) => setRows(res.data.data ?? []))
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    api.get('/countries', { params: { per_page: 50 } }).then((res) => setCountries(res.data.data ?? []))
  }, [])

  useEffect(() => {
    if (!form.country_id) {
      setProvinces([])
      return
    }
    api.get('/provinces', { params: { country_id: form.country_id, per_page: 50 } })
      .then((res) => setProvinces(res.data.data ?? []))
  }, [form.country_id])

  useEffect(() => {
    if (!form.country_id) {
      setCities([])
      return
    }
    api.get('/cities', { params: { country_id: form.country_id, province_id: form.province_id || undefined, per_page: 50 } })
      .then((res) => setCities(res.data.data ?? []))
  }, [form.country_id, form.province_id])

  useEffect(() => {
    if (!form.city_id) {
      setCommunes([])
      return
    }
    api.get('/communes', { params: { city_id: form.city_id, per_page: 50 } })
      .then((res) => setCommunes(res.data.data ?? []))
  }, [form.city_id])

  useEffect(() => {
    if (!form.commune_id) {
      setNeighborhoods([])
      return
    }
    api.get('/neighborhoods', { params: { commune_id: form.commune_id, per_page: 50 } })
      .then((res) => setNeighborhoods(res.data.data ?? []))
  }, [form.commune_id])

  const loadLookups = (companyId: number) => {
    api.get('/offices/lookups', { params: { company_id: companyId } }).then((res) => {
      const users = (res.data.data?.users ?? []) as Array<{ id: number; name: string; email?: string | null }>
      setPeople(users.map((user) => ({
        id: String(user.id),
        label: user.name,
        hint: user.email || undefined,
      })))
      setParentOptions(res.data.data?.offices ?? [])
      if (Array.isArray(res.data.data?.office_types) && res.data.data.office_types.length) {
        setOfficeTypes(res.data.data.office_types)
      }
    })
  }

  const openCreate = (row: Company) => {
    setCompany(row)
    setEditing(null)
    setForm(emptyOfficeForm)
    loadLookups(row.id)
    setOpen(true)
  }

  const openEdit = (row: Company, office: Office) => {
    setCompany(row)
    setEditing(office)
    setForm({
      name: office.name,
      code: office.code,
      type: office.type,
      address: office.address ?? '',
      phone: office.phone ?? '',
      country_id: geoId(office.country),
      province_id: geoId(office.province),
      city_id: geoId(office.city),
      commune_id: geoId(office.commune),
      neighborhood_id: geoId(office.neighborhood),
      parent_office_id: office.parent_office_id ? String(office.parent_office_id) : '',
      creator_user_id: office.creator_user_id || office.creator?.id ? String(office.creator_user_id || office.creator?.id) : '',
      manager_user_id: office.manager_user_id || office.manager?.id ? String(office.manager_user_id || office.manager?.id) : '',
    })
    loadLookups(row.id)
    setOpen(true)
  }

  const officePayload = () => ({
    name: form.name,
    code: form.code,
    type: form.type,
    address: form.address || null,
    phone: form.phone || null,
    country_id: idOrNull(form.country_id),
    province_id: idOrNull(form.province_id),
    city_id: idOrNull(form.city_id),
    commune_id: idOrNull(form.commune_id),
    neighborhood_id: idOrNull(form.neighborhood_id),
    parent_office_id: idOrNull(form.parent_office_id),
    creator_user_id: idOrNull(form.creator_user_id),
    manager_user_id: idOrNull(form.manager_user_id),
  })

  const saveOffice = async () => {
    if (!company) return
    setSaving(true)
    try {
      if (editing) {
        await api.put(`/offices/${editing.id}`, officePayload())
      } else {
        await api.post('/offices', { ...officePayload(), company_id: company.id })
      }
      setOpen(false)
      load()
    } finally {
      setSaving(false)
    }
  }

  const confirmToggle = async () => {
    if (!toggling) return
    setSaving(true)
    try {
      await api.put(`/offices/${toggling.office.id}`, { is_active: !toggling.office.is_active })
      setToggling(null)
      load()
    } finally {
      setSaving(false)
    }
  }

  const confirmDelete = async () => {
    if (!deleting) return
    setSaving(true)
    try {
      await api.delete(`/offices/${deleting.office.id}`)
      setDeleting(null)
      load()
    } finally {
      setSaving(false)
    }
  }

  const ordered = useMemo(
    () => [...rows].sort((a, b) => (a.module?.sort_order ?? 99) - (b.module?.sort_order ?? 99)),
    [rows],
  )

  return (
    <div className="space-y-4">
      <p className="text-sm text-muted">{t('org.companiesIntro')}</p>
      {loading ? <LoadingScreen /> : (
        <>
      {ordered.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}
      {ordered.map((row) => {
        const Icon = businessModuleIcon(row.module?.slug ?? row.code.toLowerCase())
        const moduleName = locale.startsWith('fr') ? (row.module?.name_fr || row.name) : (row.module?.name || row.name)
        return (
          <Card key={row.id} className="p-5">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="flex items-start gap-3">
                <span className="grid h-11 w-11 shrink-0 place-items-center rounded-2xl bg-lhms text-white">
                  <Icon size={20} />
                </span>
                <div>
                  <div className="text-xs font-semibold tracking-widest text-muted">{row.code}</div>
                  <h2 className="mt-1 text-xl font-bold">{row.name}</h2>
                  <p className="mt-1 text-sm text-muted">{moduleName}</p>
                </div>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                {canSettings && row.module ? (
                  <button
                    type="button"
                    aria-label={t('settings.module')}
                    title={t('settings.module')}
                    className="grid h-10 w-10 place-items-center rounded-xl text-ink hover:bg-white"
                    onClick={() => navigate(`/administration/settings/modules/${row.module?.slug}`)}
                  >
                    <Settings size={18} />
                  </button>
                ) : null}
                <Badge>{row.is_active ? t('org.active') : t('org.inactive')}</Badge>
                {row.module ? <Badge tone="slate">{t(`modules.${row.module.status}`)}</Badge> : null}
                {canCreate ? (
                  <Button variant="secondary" onClick={() => openCreate(row)}>
                    <Plus size={16} /> {t('org.addOffice')}
                  </Button>
                ) : null}
              </div>
            </div>
            <div className="mt-5 space-y-2">
              {(row.offices ?? []).length === 0 ? (
                <p className="text-sm text-muted">{t('org.noOffices')}</p>
              ) : (
                <>
                  <div className="mb-1 text-sm font-semibold text-muted">{t('kams.officeHierarchy')}</div>
                  {officeRoots(row.offices ?? []).map((office) => {
                    const renderOffice = (node: Office, depth = 0): ReactNode => (
                      <div key={node.id} className="space-y-2" style={{ marginLeft: depth ? depth * 16 : 0 }}>
                        <div
                          className={cn(
                            'flex flex-col gap-3 rounded-2xl border border-line bg-surface/60 px-4 py-3 sm:flex-row sm:items-start',
                            !node.is_active && 'opacity-60',
                          )}
                        >
                          <MapPin className={cn('mt-0.5 shrink-0', node.is_active ? 'text-lhms' : 'text-muted')} size={16} />
                          <div className="min-w-0 flex-1">
                            <div className="font-semibold">
                              {node.name} <span className="text-xs font-medium text-muted">{node.code}</span>
                            </div>
                            <div className="mt-0.5 text-sm text-muted">{officeLocation(node, locale) || node.address || '—'}</div>
                            {node.parent || node.creator || node.manager ? (
                              <div className="mt-1 text-xs text-muted">
                                {[
                                  node.parent ? `${t('org.parentOffice')}: ${node.parent.name}` : null,
                                  node.creator ? `${t('org.officeCreator')}: ${node.creator.name}` : null,
                                  node.manager ? `${t('org.officeManager')}: ${node.manager.name}` : null,
                                ].filter(Boolean).join(' · ')}
                              </div>
                            ) : null}
                          </div>
                          <div className="flex flex-wrap items-center gap-2">
                            <Badge tone={node.is_active ? 'slate' : 'amber'}>
                              {node.is_active ? t(`org.officeTypes.${node.type}`) : t('org.inactive')}
                            </Badge>
                            {canUpdate ? (
                              <button
                                type="button"
                                className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-ink hover:bg-white"
                                onClick={() => openEdit(row, node)}
                              >
                                <Pencil size={14} /> {t('common.edit')}
                              </button>
                            ) : null}
                            {canUpdate ? (
                              <button
                                type="button"
                                className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-ink hover:bg-white"
                                onClick={() => setToggling({ company: row, office: node })}
                              >
                                <Ban size={14} /> {node.is_active ? t('org.deactivateOffice') : t('org.activateOffice')}
                              </button>
                            ) : null}
                            {canDelete ? (
                              <button
                                type="button"
                                className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-danger hover:bg-white"
                                onClick={() => setDeleting({ company: row, office: node })}
                              >
                                <Trash2 size={14} /> {t('common.delete')}
                              </button>
                            ) : null}
                          </div>
                        </div>
                        {officeChildren(row.offices ?? [], node.id).map((child) => renderOffice(child, depth + 1))}
                      </div>
                    )
                    return renderOffice(office)
                  })}
                </>
              )}
            </div>
          </Card>
        )
      })}
        </>
      )}

      <Modal
        open={open}
        title={`${editing ? t('org.editOffice') : t('org.addOffice')} — ${company?.name ?? ''}`}
        onClose={() => setOpen(false)}
      >
        <div className="space-y-3">
          <Field label={t('org.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('org.code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
            <Select label={t('org.officeType')} value={form.type} onChange={(e) => setForm({ ...form, type: e.target.value })}>
              {officeTypes.map((type) => (
                <option key={type} value={type}>{t(`org.officeTypes.${type}`)}</option>
              ))}
            </Select>
          </div>
          <Select label={t('org.countries')} value={form.country_id} onChange={(e) => setForm({
            ...form,
            country_id: e.target.value,
            province_id: '',
            city_id: '',
            commune_id: '',
            neighborhood_id: '',
          })}>
            <option value="">—</option>
            {countries.map((item) => <option key={item.id} value={item.id}>{geoName(item, locale)}</option>)}
          </Select>
          <Select label={t('org.provinces')} value={form.province_id} disabled={!form.country_id} onChange={(e) => setForm({
            ...form,
            province_id: e.target.value,
            city_id: '',
            commune_id: '',
            neighborhood_id: '',
          })}>
            <option value="">—</option>
            {provinces.map((item) => <option key={item.id} value={item.id}>{geoName(item, locale)}</option>)}
          </Select>
          <Select label={t('org.cities')} value={form.city_id} disabled={!form.country_id} onChange={(e) => setForm({
            ...form,
            city_id: e.target.value,
            commune_id: '',
            neighborhood_id: '',
          })}>
            <option value="">—</option>
            {cities.map((item) => <option key={item.id} value={item.id}>{geoName(item, locale)}</option>)}
          </Select>
          <Select label={t('org.communes')} value={form.commune_id} disabled={!form.city_id} onChange={(e) => setForm({
            ...form,
            commune_id: e.target.value,
            neighborhood_id: '',
          })}>
            <option value="">—</option>
            {communes.map((item) => <option key={item.id} value={item.id}>{geoName(item, locale)}</option>)}
          </Select>
          <Select label={t('org.neighborhoods')} value={form.neighborhood_id} disabled={!form.commune_id} onChange={(e) => setForm({
            ...form,
            neighborhood_id: e.target.value,
          })}>
            <option value="">—</option>
            {neighborhoods.map((item) => <option key={item.id} value={item.id}>{geoName(item, locale)}</option>)}
          </Select>
          <Field label={t('org.address')} value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          <Field label={t('users.phone')} value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          <Select label={t('org.parentOffice')} value={form.parent_office_id} onChange={(e) => setForm({ ...form, parent_office_id: e.target.value })}>
            <option value="">{t('org.noParentOffice')}</option>
            {parentOptions.filter((office) => office.id !== editing?.id).map((office) => (
              <option key={office.id} value={office.id}>{office.name} ({office.code})</option>
            ))}
          </Select>
          <SearchCombobox
            label={t('org.officeCreator')}
            value={people.find((item) => item.id === form.creator_user_id) || null}
            items={people}
            placeholder={t('kams.searchUser')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, creator_user_id: item?.id || '' })}
          />
          <SearchCombobox
            label={t('org.officeManager')}
            value={people.find((item) => item.id === form.manager_user_id) || null}
            items={people}
            placeholder={t('kams.searchUser')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, manager_user_id: item?.id || '' })}
          />
          <Button className="w-full" disabled={saving || !form.name || !form.code || (company?.module?.slug === 'kams' && !editing && (!form.creator_user_id || !form.manager_user_id))} onClick={() => void saveOffice()}>
            {saving ? t('common.loading') : t('common.save')}
          </Button>
        </div>
      </Modal>

      <Modal
        open={Boolean(toggling)}
        title={toggling?.office.is_active ? t('org.deactivateOffice') : t('org.activateOffice')}
        onClose={() => setToggling(null)}
      >
        <p className="text-sm text-muted">
          {t(toggling?.office.is_active ? 'org.deactivateConfirm' : 'org.activateConfirm', { name: toggling?.office.name ?? '' })}
        </p>
        {toggling ? (
          <p className="mt-2 font-semibold">{toggling.office.name} · {toggling.company.name}</p>
        ) : null}
        <div className="mt-5 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setToggling(null)}>{t('common.cancel')}</Button>
          <Button className="flex-1" disabled={saving} onClick={() => void confirmToggle()}>
            {saving ? t('common.loading') : (toggling?.office.is_active ? t('org.deactivateOffice') : t('org.activateOffice'))}
          </Button>
        </div>
      </Modal>

      <Modal
        open={Boolean(deleting)}
        title={t('org.deleteOffice')}
        onClose={() => setDeleting(null)}
      >
        <p className="text-sm text-muted">
          {t('org.deleteOfficeConfirm')}
        </p>
        {deleting ? (
          <p className="mt-2 font-semibold">{deleting.office.name} · {deleting.company.name}</p>
        ) : null}
        <div className="mt-5 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" disabled={saving} onClick={() => void confirmDelete()}>
            {saving ? t('common.loading') : t('common.delete')}
          </Button>
        </div>
      </Modal>
    </div>
  )
}
