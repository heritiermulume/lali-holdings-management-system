import { Ban, Pencil, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PaginationButtons } from '../components/PaginationButtons'
import { Badge, Button, Card, Field, Modal } from '../components/ui'
import { LoadingScreen } from '../components/LoadingScreen'
import { WorldCountryCombobox } from '../components/WorldCountryCombobox'
import { useAuth } from '../context/AuthContext'
import type { WorldCountry } from '../data/worldCountries'
import { useLiveData } from '../hooks/useLiveData'
import { cn } from '../lib/cn'
import api from '../lib/api'
import { trackLoadSpinner } from '../lib/dataSync'

type Row = Record<string, unknown> & {
  id: number
  name: string
  name_fr?: string
  code?: string
  is_active?: boolean
  iso_code?: string
  country_id?: number
  province_id?: number
  city_id?: number
  commune_id?: number
  latitude?: number | null
  longitude?: number | null
}

const tabs = ['countries', 'provinces', 'cities', 'communes', 'neighborhoods'] as const
const editableTabs = new Set<(typeof tabs)[number]>(['provinces', 'cities', 'communes', 'neighborhoods'])

function isActive(row: Row) {
  return row.is_active !== false
}

function rowValue(row: Row, key: string): string {
  const value = row[key]
  return value == null ? '' : String(value)
}

export function OrganizationPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [tab, setTab] = useState<(typeof tabs)[number]>('countries')
  const [rows, setRows] = useState<Row[]>([])
  const [open, setOpen] = useState(false)
  const [editing, setEditing] = useState<Row | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [pickedCountry, setPickedCountry] = useState<WorldCountry | null>(null)
  const [countries, setCountries] = useState<Row[]>([])
  const [provinces, setProvinces] = useState<Row[]>([])
  const [cities, setCities] = useState<Row[]>([])
  const [communes, setCommunes] = useState<Row[]>([])
  const [currencyMap, setCurrencyMap] = useState<Record<string, string>>({})
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')
  const [deleting, setDeleting] = useState<Row | null>(null)
  const [toggling, setToggling] = useState<Row | null>(null)
  const [actionError, setActionError] = useState('')
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [total, setTotal] = useState(0)
  const [lastPage, setLastPage] = useState(1)

  const canCreate = hasPermission('organization.create')
  const canUpdate = hasPermission('organization.update')
  const canDelete = hasPermission('organization.delete')
  const canEditTab = editableTabs.has(tab)

  const load = () => {
    const stop = trackLoadSpinner(setLoading)
    return Promise.all([
      api.get(`/${tab}`, { params: { per_page: perPage, page } }).then((res) => {
        setRows(res.data.data ?? [])
        const meta = res.data.meta ?? {}
        const nextTotal = Number(meta.total ?? 0)
        const nextLast = Math.max(1, Number(meta.last_page ?? 1))
        setTotal(nextTotal)
        setLastPage(nextLast)
        if (page > nextLast) setPage(nextLast)
      }),
      api.get('/countries', { params: { per_page: 50 } }).then((res) => setCountries(res.data.data)),
      api.get('/provinces', { params: { per_page: 100 }, skipBusy: true }).then((res) => setProvinces(res.data.data)),
      api.get('/cities', { params: { per_page: 50 } }).then((res) => setCities(res.data.data)),
      api.get('/communes', { params: { per_page: 50 } }).then((res) => setCommunes(res.data.data)),
      api.get('/finance').then((res) => {
        const map: Record<string, string> = {}
        for (const row of (res.data.data?.currencies ?? []) as Array<{ code: string; symbol: string }>) {
          map[row.code] = row.symbol
        }
        setCurrencyMap(map)
      }).catch(() => setCurrencyMap({})),
    ]).finally(stop)
  }

  useEffect(() => {
    void load()
  }, [tab, page, perPage])

  useLiveData(load)

  const needsNameFr = tab === 'provinces' || tab === 'cities' || tab === 'communes' || tab === 'neighborhoods'
  const needsCode = tab === 'provinces' || tab === 'cities' || tab === 'communes' || tab === 'neighborhoods'

  const closeForm = () => {
    setOpen(false)
    setEditing(null)
    setForm({})
    setPickedCountry(null)
    setError('')
  }

  const openCreate = () => {
    setEditing(null)
    setForm({})
    setPickedCountry(null)
    setError('')
    setOpen(true)
  }

  const openEdit = (row: Row) => {
    setEditing(row)
    setPickedCountry(null)
    setError('')
    setActionError('')
    setForm({
      name: row.name || '',
      name_fr: row.name_fr || row.name || '',
      code: rowValue(row, 'code'),
      country_id: rowValue(row, 'country_id'),
      province_id: rowValue(row, 'province_id'),
      city_id: rowValue(row, 'city_id'),
      commune_id: rowValue(row, 'commune_id'),
    })
    setOpen(true)
  }

  const submit = async () => {
    setError('')
    setSaving(true)
    try {
      const payload: Record<string, unknown> = {}
      if (tab === 'countries') {
        if (!pickedCountry) {
          setError(t('org.pickCountry'))
          return
        }
        payload.is_active = true
        payload.name = pickedCountry.name
        payload.name_fr = pickedCountry.nameFr
        payload.iso_code = pickedCountry.iso
        payload.phone_code = pickedCountry.phone ? pickedCountry.phone.slice(0, 8) : null
        if (pickedCountry.currency) {
          payload.currency_code = pickedCountry.currency
          if (currencyMap[pickedCountry.currency]) {
            payload.currency_symbol = currencyMap[pickedCountry.currency]
          }
        }
        payload.latitude = pickedCountry.lat
        payload.longitude = pickedCountry.lng
      } else {
        payload.name = form.name
        payload.name_fr = form.name_fr || form.name
        if (needsCode) payload.code = form.code || null
        if (form.country_id) payload.country_id = Number(form.country_id)
        if (tab === 'cities') {
          if (!form.province_id) {
            setError(t('org.pickProvinceFirst'))
            return
          }
          payload.province_id = Number(form.province_id)
        }
        if (form.city_id) payload.city_id = Number(form.city_id)
        if (form.commune_id) payload.commune_id = Number(form.commune_id)
        if (!editing) payload.is_active = true
      }

      if (editing) {
        await api.put(`/${tab}/${editing.id}`, payload)
      } else {
        await api.post(`/${tab}`, payload)
      }
      closeForm()
      load()
    } catch (err: unknown) {
      const response = (err as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
      const firstFieldError = response?.errors
        ? Object.values(response.errors).flat().find(Boolean)
        : null
      setError(firstFieldError || response?.message || t('org.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const confirmToggle = async () => {
    if (!toggling) return
    setSaving(true)
    setActionError('')
    try {
      await api.put(`/${tab}/${toggling.id}`, { is_active: !isActive(toggling) })
      setToggling(null)
      load()
    } catch {
      setActionError(t('org.actionError'))
      setToggling(null)
    } finally {
      setSaving(false)
    }
  }

  const confirmDelete = async () => {
    if (!deleting) return
    setSaving(true)
    setActionError('')
    try {
      await api.delete(`/${tab}/${deleting.id}`)
      setDeleting(null)
      load()
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } }).response?.status
      setActionError(status === 409 ? t('org.deleteInUse') : t('org.actionError'))
      setDeleting(null)
    } finally {
      setSaving(false)
    }
  }

  const modalTitle = editing
    ? `${t('common.edit')} — ${t(`org.${tab}`)}`
    : `${t('org.add')} ${t(`org.${tab}`)}`

  const actions = (row: Row) => {
    const toggleLabel = isActive(row) ? t('org.deactivateOffice') : t('org.activateOffice')
    return (
      <div className="flex flex-wrap items-center gap-1">
        {canUpdate && canEditTab ? (
          <button
            type="button"
            title={t('common.edit')}
            aria-label={t('common.edit')}
            className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-surface"
            onClick={() => openEdit(row)}
          >
            <Pencil size={16} />
          </button>
        ) : null}
        {canUpdate ? (
          <button
            type="button"
            title={toggleLabel}
            aria-label={toggleLabel}
            className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-surface"
            onClick={() => { setActionError(''); setToggling(row) }}
          >
            <Ban size={16} />
          </button>
        ) : null}
        {canDelete ? (
          <button
            type="button"
            title={t('common.delete')}
            aria-label={t('common.delete')}
            className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-surface"
            onClick={() => { setActionError(''); setDeleting(row) }}
          >
            <Trash2 size={16} />
          </button>
        ) : null}
      </div>
    )
  }

  return (
    <div>
      {canCreate ? (
        <div className="mb-4 flex justify-end">
          <Button onClick={openCreate}>{t('org.add')}</Button>
        </div>
      ) : null}
      <div className="mb-4 flex gap-2 overflow-x-auto">
        {tabs.map((item) => (
          <button
            key={item}
            onClick={() => {
              setTab(item)
              setPage(1)
            }}
            className={`min-h-10 rounded-full px-4 text-sm font-semibold ${tab === item ? 'bg-lhms text-white' : 'bg-white text-muted'}`}
          >
            {t(`org.${item}`)}
          </button>
        ))}
      </div>
      {actionError ? <p className="mb-3 text-sm text-danger">{actionError}</p> : null}
      {loading ? <LoadingScreen /> : (
      <Card className="overflow-hidden">
        <div className="hidden md:block">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-muted">
              <tr>
                <th className="px-5 py-3">{t('org.name')}</th>
                <th className="px-5 py-3">{t('org.code')}</th>
                {tab === 'countries' ? <th className="px-5 py-3">{t('org.coordinates')}</th> : null}
                <th className="px-5 py-3">{t('org.status')}</th>
                {(canUpdate || canDelete) ? <th className="px-5 py-3">{t('common.actions')}</th> : null}
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id} className={cn('border-t border-line', !isActive(row) && 'opacity-60')}>
                  <td className="px-5 py-3 font-semibold">{row.name}</td>
                  <td className="px-5 py-3">{String(row.code || row.iso_code || '—')}</td>
                  {tab === 'countries' ? (
                    <td className="px-5 py-3 text-muted">
                      {row.latitude != null && row.longitude != null
                        ? `${Number(row.latitude).toFixed(2)}, ${Number(row.longitude).toFixed(2)}`
                        : '—'}
                    </td>
                  ) : null}
                  <td className="px-5 py-3">
                    <Badge tone={isActive(row) ? 'green' : 'amber'}>
                      {isActive(row) ? t('org.active') : t('org.inactive')}
                    </Badge>
                  </td>
                  {(canUpdate || canDelete) ? <td className="px-5 py-3">{actions(row)}</td> : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="space-y-3 p-4 md:hidden">
          {rows.map((row) => (
            <div key={row.id} className={cn('rounded-xl border border-line p-4', !isActive(row) && 'opacity-60')}>
              <div className="font-semibold">{row.name}</div>
              <div className="mt-1 text-sm text-muted">{String(row.code || row.iso_code || '')}</div>
              <div className="mt-2">
                <Badge tone={isActive(row) ? 'green' : 'amber'}>
                  {isActive(row) ? t('org.active') : t('org.inactive')}
                </Badge>
              </div>
              <div className="mt-2">{actions(row)}</div>
            </div>
          ))}
        </div>
        <div className="flex flex-col gap-3 border-t border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">
            {t('common.pageRange', {
              from: total === 0 ? 0 : (page - 1) * perPage + 1,
              to: Math.min(page * perPage, total),
              total,
            })}
          </p>
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
              page={page}
              lastPage={lastPage}
              disabled={loading}
              onPrev={() => setPage((value) => Math.max(1, value - 1))}
              onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
            />
          </div>
        </div>
      </Card>
      )}

      <Modal open={open} title={modalTitle} onClose={closeForm}>
        <div className="space-y-3">
          {tab === 'countries' ? (
            <>
              <WorldCountryCombobox
                value={pickedCountry}
                excludeIso={countries.map((country) => String(country.iso_code || ''))}
                onSelect={setPickedCountry}
              />
              {pickedCountry ? (
                <div className="rounded-2xl bg-surface px-4 py-3 text-sm">
                  <div className="font-semibold">{pickedCountry.nameFr}</div>
                  <div className="mt-1 text-muted">
                    {pickedCountry.iso}
                    {pickedCountry.phone ? ` · ${pickedCountry.phone}` : ''}
                    {` · ${pickedCountry.currency}`}
                  </div>
                  {pickedCountry.lat != null && pickedCountry.lng != null ? (
                    <div className="mt-1 text-muted">{t('org.coordinates')}: {pickedCountry.lat.toFixed(4)}, {pickedCountry.lng.toFixed(4)}</div>
                  ) : null}
                </div>
              ) : null}
            </>
          ) : (
            <>
              <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} />
              {needsNameFr ? (
                <Field label={t('org.nameFr')} value={form.name_fr || ''} onChange={(e) => setForm({ ...form, name_fr: e.target.value })} />
              ) : null}
              {needsCode ? (
                <Field label={t('org.code')} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value })} />
              ) : null}
            </>
          )}
          {tab === 'provinces' || tab === 'cities' ? (
            <label className="block text-sm font-medium">
              {t('org.countries')}
              <select
                className="mt-1 min-h-11 w-full rounded-xl border border-line px-3"
                value={form.country_id || ''}
                onChange={(e) => setForm({ ...form, country_id: e.target.value, province_id: '' })}
              >
                <option value="">—</option>
                {countries.map((country) => <option key={country.id} value={country.id}>{country.name}</option>)}
              </select>
            </label>
          ) : null}
          {tab === 'cities' ? (
            <label className="block text-sm font-medium">
              {t('org.provinces')}
              <select
                className="mt-1 min-h-11 w-full rounded-xl border border-line px-3 disabled:bg-surface"
                value={form.province_id || ''}
                disabled={!form.country_id}
                onChange={(e) => setForm({ ...form, province_id: e.target.value })}
              >
                <option value="">{form.country_id ? '—' : t('org.pickCountryFirst')}</option>
                {provinces
                  .filter((province) => !form.country_id || String(province.country_id) === form.country_id)
                  .map((province) => <option key={province.id} value={province.id}>{province.name}</option>)}
              </select>
            </label>
          ) : null}
          {tab === 'communes' ? (
            <label className="block text-sm font-medium">
              {t('org.cities')}
              <select className="mt-1 min-h-11 w-full rounded-xl border border-line px-3" value={form.city_id || ''} onChange={(e) => setForm({ ...form, city_id: e.target.value })}>
                <option value="">—</option>
                {cities.map((city) => <option key={city.id} value={city.id}>{city.name}</option>)}
              </select>
            </label>
          ) : null}
          {tab === 'neighborhoods' ? (
            <label className="block text-sm font-medium">
              {t('org.communes')}
              <select className="mt-1 min-h-11 w-full rounded-xl border border-line px-3" value={form.commune_id || ''} onChange={(e) => setForm({ ...form, commune_id: e.target.value })}>
                <option value="">—</option>
                {communes.map((commune) => <option key={commune.id} value={commune.id}>{commune.name}</option>)}
              </select>
            </label>
          ) : null}
          {error ? <p className="text-sm text-danger">{error}</p> : null}
          <Button className="w-full" disabled={saving} onClick={() => void submit()}>{saving ? t('common.loading') : t('common.save')}</Button>
        </div>
      </Modal>

      <Modal
        open={Boolean(toggling)}
        title={toggling && isActive(toggling) ? t('org.deactivateOffice') : t('org.activateOffice')}
        onClose={() => setToggling(null)}
      >
        <p className="text-sm text-muted">
          {t(toggling && isActive(toggling) ? 'org.deactivateConfirm' : 'org.activateConfirm', { name: toggling?.name ?? '' })}
        </p>
        <div className="mt-5 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setToggling(null)}>{t('common.cancel')}</Button>
          <Button className="flex-1" disabled={saving} onClick={() => void confirmToggle()}>
            {saving ? t('common.loading') : (toggling && isActive(toggling) ? t('org.deactivateOffice') : t('org.activateOffice'))}
          </Button>
        </div>
      </Modal>

      <Modal
        open={Boolean(deleting)}
        title={t('common.delete')}
        onClose={() => setDeleting(null)}
      >
        <p className="text-sm text-muted">
          {t('org.deleteConfirm', { name: deleting?.name ?? '' })}
        </p>
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
