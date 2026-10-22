import { Camera, Eye, Gem, ListFilter, Pencil, Plus, Printer, Search, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { PhotoSourcePicker } from '../../components/PhotoSourcePicker'
import { OfficeFilterSelect } from '../../components/OfficeFilterSelect'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import { useOfficeScope } from '../../hooks/useOfficeScope'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
import { trackLoadSpinner } from '../../lib/dataSync'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'

type Office = { id: number; company_id: number; name: string; code: string; city?: { id: number; name: string } | null }

type Asset = {
  id: number
  company_id: number
  office_id: number
  name: string
  category: string
  quantity: number
  unit_value: number
  total_value?: number
  acquired_on?: string | null
  status: string
  notes?: string | null
  image_path?: string | null
  image_url?: string | null
  office?: { id: number; name: string; code?: string; city?: { name?: string } | null } | null
}

function toneFor(status: string): 'green' | 'amber' | 'red' | 'slate' {
  if (status === 'active') return 'green'
  if (status === 'maintenance') return 'amber'
  if (status === 'disposed') return 'red'
  return 'slate'
}

function uniqueById(items: Asset[]) {
  const seen = new Set<number>()
  return items.filter((row) => {
    if (seen.has(row.id)) return false
    seen.add(row.id)
    return true
  })
}

export function KamsPatrimoinePage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Asset[]>([])
  const [offices, setOffices] = useState<Office[]>([])
  const [categories, setCategories] = useState<string[]>([])
  const [statuses, setStatuses] = useState<string[]>([])
  const [totalValue, setTotalValue] = useState(0)
  const { officeId, setOfficeId, showOfficeFilter, canChooseOffice } = useOfficeScope(offices)
  const [status, setStatus] = useState('')
  const [category, setCategory] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Asset | null>(null)
  const [viewing, setViewing] = useState<Asset | null>(null)
  const [deleting, setDeleting] = useState<Asset | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const [filterOpen, setFilterOpen] = useState(false)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [imageFile, setImageFile] = useState<File | null>(null)
  const [imagePreview, setImagePreview] = useState<string | null>(null)
  const [removeImage, setRemoveImage] = useState(false)
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)

  const listParams = () => ({
    per_page: 100,
    ...(officeId ? { office_id: officeId } : {}),
    ...(status ? { status } : {}),
    ...(category ? { category } : {}),
    ...(query ? { search: query } : {}),
  })

  const applyListResponse = (res: { data: { data?: Asset[]; meta?: { total_value?: number } } }) => {
    setRows(uniqueById(res.data.data ?? []))
    setTotalValue(Number(res.data.meta?.total_value ?? 0))
  }

  const reload = () => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/patrimoine', { params: listParams() })
      .then(applyListResponse)
      .finally(stop)
  }

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
    api.get('/modules/kams/patrimoine/lookups').then((res) => {
      setOffices((res.data.data?.offices ?? []) as Office[])
      setCategories(res.data.data?.categories ?? [])
      setStatuses(res.data.data?.statuses ?? [])
    }).catch(() => undefined)
  }, [])

  useEffect(() => {
    void reload()
  }, [query, status, category, officeId])

  useLiveData(reload)

  const clearImageState = () => {
    setImageFile(null)
    setRemoveImage(false)
    setImagePreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return null
    })
  }

  const resetForm = () => {
    setForm({})
    setFormError('')
    setEditing(null)
    clearImageState()
  }

  const openCreate = () => {
    resetForm()
    setForm({
      office_id: officeId || (canChooseOffice ? '' : String(offices[0]?.id || '')),
      category: 'furniture',
      quantity: '1',
      unit_value: '0',
      status: 'active',
    })
    setFormOpen(true)
  }

  const openEdit = (row: Asset) => {
    setEditing(row)
    setForm({
      office_id: String(row.office_id || ''),
      name: row.name || '',
      category: row.category || 'other',
      quantity: String(row.quantity || 1),
      unit_value: String(row.unit_value ?? 0),
      acquired_on: row.acquired_on ? String(row.acquired_on).slice(0, 10) : '',
      status: row.status || 'active',
      notes: row.notes || '',
    })
    setFormError('')
    setImageFile(null)
    setRemoveImage(false)
    setImagePreview(row.image_url || null)
    setFormOpen(true)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (saving) return
    if (!form.name?.trim()) {
      setFormError(t('kams.requiredAssetFields'))
      return
    }
    if (canChooseOffice && !form.office_id) {
      setFormError(t('kams.requiredAssetFields'))
      return
    }

    setSaving(true)
    try {
      await withBusy('data', async () => {
        if (imageFile || (editing && removeImage)) {
          const payload = new FormData()
          if (form.office_id) payload.append('office_id', form.office_id)
          payload.append('name', form.name.trim())
          payload.append('category', form.category || 'other')
          payload.append('quantity', String(Math.max(1, Number(form.quantity) || 1)))
          payload.append('unit_value', String(Math.max(0, Number(form.unit_value) || 0)))
          if (form.acquired_on) payload.append('acquired_on', form.acquired_on)
          payload.append('status', form.status || 'active')
          if (form.notes) payload.append('notes', form.notes)
          if (imageFile) payload.append('image', imageFile)
          if (editing && removeImage && !imageFile) payload.append('remove_image', '1')

          if (editing) {
            payload.append('_method', 'PUT')
            await api.post(`/modules/kams/patrimoine/${editing.id}`, payload)
          } else {
            await api.post('/modules/kams/patrimoine', payload)
          }
        } else {
          const payload = {
            office_id: form.office_id ? Number(form.office_id) : undefined,
            name: form.name.trim(),
            category: form.category || 'other',
            quantity: Math.max(1, Number(form.quantity) || 1),
            unit_value: Math.max(0, Number(form.unit_value) || 0),
            acquired_on: form.acquired_on || null,
            status: form.status || 'active',
            notes: form.notes || null,
          }
          if (editing) {
            await api.put(`/modules/kams/patrimoine/${editing.id}`, payload)
          } else {
            await api.post('/modules/kams/patrimoine', payload)
          }
        }
      })
      setFormOpen(false)
      resetForm()
      await reload()
    } catch (error: unknown) {
      const data = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } })?.response?.data
      const firstFieldError = data?.errors ? Object.values(data.errors).flat()[0] : undefined
      setFormError(firstFieldError || data?.message || t('common.error'))
    } finally {
      setSaving(false)
    }
  }

  const remove = async () => {
    if (!deleting) return
    await withBusy('data', async () => {
      await api.delete(`/modules/kams/patrimoine/${deleting.id}`)
    })
    setDeleting(null)
    await reload()
  }

  const lineValue = (row: Asset) => row.total_value ?? (Number(row.quantity) * Number(row.unit_value))

  const assetHeaders = () => [
    t('org.name'),
    t('kams.assetCategory'),
    t('kams.office'),
    t('kams.quantity'),
    t('kams.unitValue'),
    t('kams.patrimoineTotal'),
    t('common.status'),
  ]

  const assetColumns = (row: Asset) => [
    row.name,
    t(`kams.assetCategoryOption.${row.category}`, { defaultValue: row.category }),
    row.office?.name || '',
    String(row.quantity),
    money(row.unit_value),
    money(lineValue(row)),
    t(`kams.assetStatus.${row.status}`, { defaultValue: row.status }),
  ]

  const printOptions = (list: Asset[]) => ({
    title: t('kams.patrimoine'),
    module: t('kams.title'),
    subtitle: t('kams.patrimoineHint'),
    meta: [
      { label: t('kams.patrimoineTotal'), value: money(list.filter((row) => row.status === 'active').reduce((sum, row) => sum + lineValue(row), 0)) },
      {
        label: t('kams.filterOffice'),
        value: officeId
          ? (offices.find((office) => String(office.id) === officeId)?.name || officeId)
          : t('kams.allOffices'),
      },
      { label: t('common.filter'), value: [status ? t(`kams.assetStatus.${status}`) : null, category ? t(`kams.assetCategoryOption.${category}`) : null].filter(Boolean).join(' · ') || t('common.all') },
      { label: t('common.records'), value: String(list.length) },
      { label: t('common.printedOn'), value: new Date().toLocaleString() },
    ],
    headers: assetHeaders(),
    rows: list.map(assetColumns),
    emptyLabel: t('common.empty'),
    footerNote: t('common.confidential'),
    orientation: 'landscape' as const,
  })

  const loadForPrint = async () => withBusy('data', async () => {
    const res = await api.get('/modules/kams/patrimoine', { params: listParams() })
    return uniqueById((res.data.data as Asset[]) || [])
  })

  const printAssets = async () => {
    const list = await loadForPrint()
    printHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const previewAssets = async () => {
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(rows)))
    const list = await loadForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(list)))
  }

  return (
    <div>
      <Card className="mb-4 px-4 py-3">
        <div className="text-xs font-semibold uppercase tracking-wide text-muted">{t('kams.patrimoineTotal')}</div>
        <div className="mt-1 text-2xl font-bold text-lhms">{money(totalValue)}</div>
      </Card>

      <div className="mb-4 flex items-center gap-2">
        {showOfficeFilter ? (
          <OfficeFilterSelect
            className="!w-44 shrink-0"
            value={officeId}
            offices={offices}
            onChange={(e) => setOfficeId(e.target.value)}
          />
        ) : null}
        <div className="relative flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-xl border border-line bg-white px-3">
          <Search size={16} className="shrink-0 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('kams.searchAssets')}
            className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
          <div ref={filterRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
            <button
              type="button"
              aria-label={t('common.filter')}
              aria-expanded={filterOpen}
              className={cn(
                'grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface hover:text-ink',
                (status || category) && 'bg-lhms-soft text-lhms',
              )}
              onClick={() => setFilterOpen((open) => !open)}
            >
              <ListFilter size={16} />
            </button>
            {filterOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-64 space-y-3 overflow-hidden rounded-xl border border-line bg-white p-3 shadow-xl">
                <div>
                  <div className="mb-1 text-xs font-semibold uppercase text-muted">{t('common.status')}</div>
                  <div className="space-y-1">
                    {[{ value: '', label: t('common.all') }, ...statuses.map((value) => ({ value, label: t(`kams.assetStatus.${value}`) }))].map((option) => (
                      <button
                        key={`status-${option.value || 'all'}`}
                        type="button"
                        className={cn(
                          'flex w-full rounded-lg px-2 py-2 text-left text-sm hover:bg-surface',
                          status === option.value && 'bg-lhms-soft font-semibold text-lhms',
                        )}
                        onClick={() => setStatus(option.value)}
                      >
                        {option.label}
                      </button>
                    ))}
                  </div>
                </div>
                <div>
                  <div className="mb-1 text-xs font-semibold uppercase text-muted">{t('kams.assetCategory')}</div>
                  <div className="space-y-1">
                    {[{ value: '', label: t('common.all') }, ...categories.map((value) => ({ value, label: t(`kams.assetCategoryOption.${value}`) }))].map((option) => (
                      <button
                        key={`category-${option.value || 'all'}`}
                        type="button"
                        className={cn(
                          'flex w-full rounded-lg px-2 py-2 text-left text-sm hover:bg-surface',
                          category === option.value && 'bg-lhms-soft font-semibold text-lhms',
                        )}
                        onClick={() => setCategory(option.value)}
                      >
                        {option.label}
                      </button>
                    ))}
                  </div>
                </div>
              </div>
            ) : null}
          </div>
        </div>
        <div className="flex shrink-0 flex-nowrap items-center gap-2">
          {hasPermission('kams.patrimoine.create') ? (
            <Button className="shrink-0 px-3 sm:px-4" onClick={openCreate}>
              <Plus size={16} /> {t('kams.addAsset')}
            </Button>
          ) : null}
          {hasPermission('kams.patrimoine.view') ? (
            <div ref={printMenuRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
              <Button
                variant="secondary"
                className="h-11 w-11 px-0 sm:w-auto sm:px-4"
                aria-label={t('common.print')}
                aria-expanded={printMenuOpen}
                onClick={() => setPrintMenuOpen((open) => !open)}
              >
                <Printer size={16} />
                <span className="hidden sm:inline">{t('common.print')}</span>
              </Button>
              {printMenuOpen ? (
                <div className="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                    onClick={(event) => {
                      event.stopPropagation()
                      setPrintMenuOpen(false)
                      void printAssets()
                    }}
                  >
                    <Printer size={16} className="text-lhms" /> {t('common.print')}
                  </button>
                  <button
                    type="button"
                    className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                    onClick={(event) => {
                      event.stopPropagation()
                      setPrintMenuOpen(false)
                      void previewAssets()
                    }}
                  >
                    <Eye size={16} className="text-lhms" /> {t('common.preview')}
                  </button>
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
      </div>

      <Card className="overflow-hidden p-0">
        {loading && rows.length === 0 ? <LoadingScreen /> : (
          <>
            {rows.length === 0 ? <p className="px-4 py-8 text-center text-sm text-muted">{t('common.empty')}</p> : null}
            {rows.map((row) => (
              <div key={row.id} className="flex items-center gap-3 border-b border-line px-4 py-3 last:border-b-0 hover:bg-surface/70">
                {row.image_url ? (
                  <img src={row.image_url} alt="" className="h-12 w-12 shrink-0 rounded-full object-cover" />
                ) : (
                  <span className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-lhms text-white">
                    <Gem size={18} />
                  </span>
                )}
                <div className="min-w-0 flex-1">
                  <div className="truncate font-semibold">{row.name}</div>
                  <div className="truncate text-xs text-muted sm:text-sm">
                    {t(`kams.assetCategoryOption.${row.category}`, { defaultValue: row.category })}
                    {' · '}
                    {row.office?.name || '—'}
                    {' · '}
                    {row.quantity} × {money(row.unit_value)}
                  </div>
                </div>
                <div className="hidden shrink-0 text-right sm:block">
                  <div className="font-semibold text-ink">{money(lineValue(row))}</div>
                  <Badge tone={toneFor(row.status)}>{t(`kams.assetStatus.${row.status}`, { defaultValue: row.status })}</Badge>
                </div>
                <div className="flex shrink-0 items-center gap-1">
                  <button type="button" aria-label={t('common.view')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => setViewing(row)}>
                    <Eye size={16} />
                  </button>
                  {hasPermission('kams.patrimoine.update') ? (
                    <button type="button" aria-label={t('common.edit')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => openEdit(row)}>
                      <Pencil size={16} />
                    </button>
                  ) : null}
                  {hasPermission('kams.patrimoine.delete') ? (
                    <button type="button" aria-label={t('common.delete')} className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-white" onClick={() => setDeleting(row)}>
                      <Trash2 size={16} />
                    </button>
                  ) : null}
                </div>
              </div>
            ))}
          </>
        )}
      </Card>

      <Modal open={formOpen} title={editing ? t('kams.editAsset') : t('kams.addAsset')} onClose={() => { setFormOpen(false); resetForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          <div className="flex justify-center pb-1">
            <PhotoSourcePicker
              title={t('kams.assetImage')}
              onFile={(file) => {
                setImageFile(file)
                setRemoveImage(false)
                setImagePreview((current) => {
                  if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
                  return URL.createObjectURL(file)
                })
              }}
            >
              {(openPhotoMenu) => (
                <div className="relative">
                  <button
                    type="button"
                    className="grid h-24 w-24 place-items-center overflow-hidden rounded-full bg-lhms-soft text-lhms ring-2 ring-lhms/20"
                    onClick={openPhotoMenu}
                    aria-label={t('kams.assetImage')}
                  >
                    {imagePreview ? (
                      <img src={imagePreview} alt="" className="h-full w-full object-cover" />
                    ) : (
                      <Gem size={32} />
                    )}
                  </button>
                  {imagePreview ? (
                    <button
                      type="button"
                      aria-label={t('kams.removeImage')}
                      className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-danger p-0 text-white shadow-md ring-2 ring-white transition hover:bg-[#a31818]"
                      onClick={() => {
                        setImageFile(null)
                        setRemoveImage(true)
                        setImagePreview((current) => {
                          if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
                          return null
                        })
                      }}
                    >
                      <Trash2 size={14} />
                    </button>
                  ) : null}
                  <button
                    type="button"
                    aria-label={t('kams.assetImage')}
                    className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-lhms p-0 text-white shadow-md ring-2 ring-white transition hover:bg-lhms-deep"
                    onClick={openPhotoMenu}
                  >
                    <Camera size={14} />
                  </button>
                </div>
              )}
            </PhotoSourcePicker>
          </div>
          {canChooseOffice ? (
            <Select label={t('kams.office')} required value={form.office_id || ''} onChange={(e) => setForm({ ...form, office_id: e.target.value })}>
              <option value="">—</option>
              {offices.map((office) => (
                <option key={office.id} value={office.id}>
                  {office.name}{office.city?.name ? ` · ${office.city.name}` : office.code ? ` (${office.code})` : ''}
                </option>
              ))}
            </Select>
          ) : null}
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label={t('kams.assetCategory')} value={form.category || 'other'} onChange={(e) => setForm({ ...form, category: e.target.value })}>
              {categories.map((value) => (
                <option key={value} value={value}>{t(`kams.assetCategoryOption.${value}`)}</option>
              ))}
            </Select>
            <Select label={t('common.status')} value={form.status || 'active'} onChange={(e) => setForm({ ...form, status: e.target.value })}>
              {statuses.map((value) => (
                <option key={value} value={value}>{t(`kams.assetStatus.${value}`)}</option>
              ))}
            </Select>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('kams.quantity')} type="number" min={1} value={form.quantity || '1'} onChange={(e) => setForm({ ...form, quantity: e.target.value })} required />
            <Field label={t('kams.unitValue')} type="number" min={0} step="0.01" value={form.unit_value || '0'} onChange={(e) => setForm({ ...form, unit_value: e.target.value })} required />
          </div>
          <Field label={t('kams.acquiredOn')} type="date" value={form.acquired_on || ''} onChange={(e) => setForm({ ...form, acquired_on: e.target.value })} />
          <label className="block space-y-1.5">
            <span className="text-sm font-medium">{t('kams.notes')}</span>
            <textarea
              className="min-h-24 w-full rounded-xl border border-line px-3 py-2 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
              value={form.notes || ''}
              onChange={(e) => setForm({ ...form, notes: e.target.value })}
            />
          </label>
          {formError ? <p className="text-sm text-danger">{formError}</p> : null}
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(viewing)} title={viewing?.name || t('kams.patrimoine')} onClose={() => setViewing(null)}>
        {viewing ? (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              {viewing.image_url ? (
                <img src={viewing.image_url} alt="" className="h-24 w-24 rounded-full object-cover" />
              ) : (
                <span className="grid h-24 w-24 place-items-center rounded-full bg-lhms text-white">
                  <Gem size={32} />
                </span>
              )}
            </div>
            <div>{t('kams.office')}: <strong>{viewing.office?.name || '—'}</strong></div>
            <div>{t('kams.assetCategory')}: <strong>{t(`kams.assetCategoryOption.${viewing.category}`, { defaultValue: viewing.category })}</strong></div>
            <div>{t('kams.quantity')}: <strong>{viewing.quantity}</strong></div>
            <div>{t('kams.unitValue')}: <strong>{money(viewing.unit_value)}</strong></div>
            <div>{t('kams.patrimoineTotal')}: <strong>{money(lineValue(viewing))}</strong></div>
            <div>{t('kams.acquiredOn')}: <strong>{viewing.acquired_on ? String(viewing.acquired_on).slice(0, 10) : '—'}</strong></div>
            <div>{t('common.status')}: <Badge tone={toneFor(viewing.status)}>{t(`kams.assetStatus.${viewing.status}`, { defaultValue: viewing.status })}</Badge></div>
            {viewing.notes ? <div>{t('kams.notes')}: {viewing.notes}</div> : null}
          </div>
        ) : null}
      </Modal>

      <Modal open={Boolean(deleting)} title={t('kams.deleteAsset')} onClose={() => setDeleting(null)}>
        <p className="text-sm text-muted">{t('kams.deleteAssetConfirm', { name: deleting?.name })}</p>
        <div className="mt-4 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" onClick={() => void remove()}>{t('common.delete')}</Button>
        </div>
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
