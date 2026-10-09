import { Download, Eye, ListFilter, Pencil, Plus, Printer, Search, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Outlet, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { OfficeFilterSelect } from '../../components/OfficeFilterSelect'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import { useOfficeScope } from '../../hooks/useOfficeScope'
import api from '../../lib/api'
import { trackLoadSpinner } from '../../lib/dataSync'
import { withBusy } from '../../lib/busy'
import { cn } from '../../lib/cn'
import { initials } from '../../lib/format'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

type Office = { id: number; company_id: number; name: string; code: string; city?: { id: number; name: string } | null }
type Location = { id: number; company_id?: number; name: string; code?: string | null }
type Client = {
  id: number
  company_id?: number
  office_id?: number | null
  kind?: 'client' | 'supplier'
  name: string
  email?: string | null
  phone?: string | null
  city?: string | null
  segment?: string | null
  status: string
  notes?: string | null
  office?: { id: number; name: string; code?: string; city?: { name?: string } | null } | null
  warehouses?: Array<{ id: number; name: string; code?: string | null }> | null
}

type PartyKind = 'client' | 'supplier'

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

function toneFor(status: string): 'green' | 'amber' | 'red' | 'slate' {
  if (status === 'active') return 'green'
  if (status === 'prospect') return 'amber'
  if (status === 'inactive') return 'red'
  return 'slate'
}

export function KamsClientsIndex() {
  const { hasPermission } = useAuth()
  if (hasPermission('kams.clients.view')) return <KamsClientsList />
  if (hasPermission('kams.suppliers.view')) return <Navigate to="/modules/kams/clients/suppliers" replace />
  return <Navigate to="/modules/kams" replace />
}

export function KamsClientsWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canClients = hasPermission('kams.clients.view')
  const canSuppliers = hasPermission('kams.suppliers.view')

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/clients', end: true, label: t('kams.clients'), show: canClients },
          { to: '/modules/kams/clients/suppliers', end: true, label: t('kams.suppliers'), show: canSuppliers },
        ]}
      />
      <Outlet />
    </div>
  )
}

export function KamsClientsList() {
  return <KamsPartyDirectory kind="client" />
}

export function KamsSuppliersList() {
  return <KamsPartyDirectory kind="supplier" />
}

function KamsPartyDirectory({ kind }: { kind: PartyKind }) {
  const { t } = useTranslation()
  const { hasPermission, user } = useAuth()
  const isSupplier = kind === 'supplier'
  const [rows, setRows] = useState<Client[]>([])
  const [offices, setOffices] = useState<Office[]>([])
  const [warehouses, setWarehouses] = useState<Location[]>([])
  const [segments, setSegments] = useState<string[]>([])
  const [statuses, setStatuses] = useState<string[]>([])
  const { officeId, setOfficeId, showOfficeFilter, canChooseOffice } = useOfficeScope(offices)
  const [selectedWarehouseIds, setSelectedWarehouseIds] = useState<number[]>([])
  const [assignAllWarehouses, setAssignAllWarehouses] = useState(true)
  const [status, setStatus] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [editing, setEditing] = useState<Client | null>(null)
  const [viewing, setViewing] = useState<Client | null>(null)
  const [deleting, setDeleting] = useState<Client | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [formError, setFormError] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const [filterOpen, setFilterOpen] = useState(false)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)

  const labels = {
    title: isSupplier ? t('kams.suppliers') : t('kams.clients'),
    hint: isSupplier ? t('kams.suppliersHint') : t('kams.clientsPageHint'),
    search: isSupplier ? t('kams.searchSuppliers') : t('kams.searchClients'),
    add: isSupplier ? t('kams.addSupplier') : t('kams.addClient'),
    edit: isSupplier ? t('kams.editSupplier') : t('kams.editClient'),
    remove: isSupplier ? t('kams.deleteSupplier') : t('kams.deleteClient'),
    removeConfirm: isSupplier ? 'kams.deleteSupplierConfirm' : 'kams.deleteClientConfirm',
    required: isSupplier ? t('kams.requiredSupplierFields') : t('kams.requiredClientFields'),
    exportPrefix: isSupplier ? 'fournisseurs' : 'clients',
  }

  const load = (term = query) => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/clients', {
      params: {
        per_page: 50,
        kind,
        ...(status ? { status } : {}),
        ...(!isSupplier && officeId ? { office_id: officeId } : {}),
        ...(term ? { search: term } : {}),
      },
    }).then((res) => setRows(res.data.data ?? [])).finally(stop)
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
    setSearch('')
    setQuery('')
    setStatus('')
    setFormOpen(false)
    setEditing(null)
    setViewing(null)
    setDeleting(null)
  }, [kind])

  useEffect(() => {
    void load()
  }, [status, query, kind, officeId])

  useLiveData(() => load(query))

  useEffect(() => {
    api.get('/modules/kams/clients/lookups').then((res) => {
      const list = (res.data.data.offices || []) as Office[]
      const warehouseList = (res.data.data.warehouses || []) as Location[]
      setOffices(list)
      setWarehouses(warehouseList)
      setSegments(res.data.data.options?.client_segments ?? [])
      setStatuses(res.data.data.options?.client_statuses ?? [])
      setSelectedWarehouseIds(warehouseList.map((row) => row.id))
      setAssignAllWarehouses(true)
    })
  }, [])

  const resetForm = () => {
    setEditing(null)
    setForm({})
    setFormError('')
    setSelectedWarehouseIds(warehouses.map((row) => row.id))
    setAssignAllWarehouses(true)
  }

  const assignedOfficeId = () => {
    if (user?.primary_office?.id && offices.some((office) => office.id === user.primary_office?.id)) {
      return String(user.primary_office.id)
    }
    return offices[0] ? String(offices[0].id) : ''
  }

  const openCreate = () => {
    resetForm()
    setForm({
      status: isSupplier ? 'active' : 'prospect',
      segment: 'retail',
      office_id: assignedOfficeId(),
    })
    setSelectedWarehouseIds(warehouses.map((row) => row.id))
    setAssignAllWarehouses(true)
    setFormOpen(true)
  }

  const openEdit = (row: Client) => {
    resetForm()
    setEditing(row)
    const linkedIds = (row.warehouses || []).map((item) => item.id)
    const allIds = warehouses.map((item) => item.id)
    setSelectedWarehouseIds(linkedIds.length ? linkedIds : allIds)
    setAssignAllWarehouses(linkedIds.length > 0 && allIds.length > 0 && linkedIds.length === allIds.length)
    setForm({
      office_id: row.office_id ? String(row.office_id) : '',
      name: row.name || '',
      email: row.email || '',
      phone: row.phone || '',
      city: row.city || '',
      segment: row.segment || 'retail',
      status: row.status || (isSupplier ? 'active' : 'prospect'),
      notes: row.notes || '',
    })
    setFormOpen(true)
  }

  const submitClient = async (event: FormEvent) => {
    event.preventDefault()
    const officeId = canChooseOffice ? form.office_id : assignedOfficeId()
    const warehouseIds = assignAllWarehouses ? warehouses.map((row) => row.id) : selectedWarehouseIds
    if (!form.name?.trim()) {
      setFormError(labels.required)
      return
    }
    if (isSupplier) {
      if (warehouseIds.length === 0) {
        setFormError(t('kams.requiredSupplierWarehouse'))
        return
      }
    } else if (canChooseOffice && !officeId) {
      setFormError(labels.required)
      return
    }
    const payload = {
      kind,
      office_id: isSupplier ? undefined : (officeId ? Number(officeId) : undefined),
      warehouse_ids: isSupplier ? warehouseIds : undefined,
      assign_all_warehouses: isSupplier ? assignAllWarehouses : undefined,
      name: form.name.trim(),
      email: form.email || null,
      phone: form.phone || null,
      city: form.city || null,
      segment: isSupplier ? (form.segment || 'other') : (form.segment || 'retail'),
      status: form.status || (isSupplier ? 'active' : 'prospect'),
      notes: form.notes || null,
    }
    setSaving(true)
    setFormError('')
    try {
      if (editing) {
        await api.put(`/modules/kams/clients/${editing.id}`, payload)
      } else {
        await api.post('/modules/kams/clients', payload)
      }
      setFormOpen(false)
      resetForm()
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
      const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
      setFormError(firstError || response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const removeClient = async () => {
    if (!deleting) return
    await api.delete(`/modules/kams/clients/${deleting.id}`)
    setDeleting(null)
    void load()
  }

  const clientParams = () => ({
    per_page: 1000,
    kind,
    ...(status ? { status } : {}),
    ...(!isSupplier && officeId ? { office_id: officeId } : {}),
    ...(query ? { search: query } : {}),
  })

  const clientHeaders = () => [
    t('org.name'),
    t('kams.email'),
    t('kams.phone'),
    isSupplier ? t('kams.warehouses') : t('kams.office'),
    t('kams.city'),
    ...(isSupplier ? [] : [t('kams.segment')]),
    t('common.status'),
  ]

  const clientColumns = (row: Client) => [
    row.name,
    row.email || '',
    row.phone || '',
    isSupplier
      ? ((row.warehouses || []).map((item) => item.name).join(', ') || '')
      : (row.office?.name || ''),
    row.city || row.office?.city?.name || '',
    ...(isSupplier ? [] : [t(`kams.segmentOption.${row.segment || 'other'}`, { defaultValue: row.segment || '' })]),
    t(`kams.status.${row.status}`, { defaultValue: row.status }),
  ]

  const printOptions = (list: Client[]) => ({
    title: labels.title,
    module: t('kams.title'),
    subtitle: labels.hint,
    meta: [
      { label: t('common.filter'), value: status ? t(`kams.status.${status}`) : t('common.all') },
      { label: t('common.records'), value: String(list.length) },
      { label: t('common.printedOn'), value: new Date().toLocaleString() },
    ],
    headers: clientHeaders(),
    rows: list.map(clientColumns),
    emptyLabel: t('common.empty'),
    footerNote: t('common.confidential'),
    orientation: 'landscape' as const,
  })

  const loadClientsForPrint = async () => {
    return withBusy('data', async () => {
      const res = await api.get('/modules/kams/clients', { params: clientParams() })
      return (res.data.data as Client[]) || []
    })
  }

  const exportClients = async () => {
    const list = await loadClientsForPrint()
    downloadCsv(`${labels.exportPrefix}-${new Date().toISOString().slice(0, 10)}.csv`, [
      clientHeaders(),
      ...list.map(clientColumns),
    ])
  }

  const printClients = async () => {
    const list = await loadClientsForPrint()
    printHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const previewClients = async () => {
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(rows)))
    const list = await loadClientsForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const subtitle = (row: Client) => [
    isSupplier
      ? ((row.warehouses || []).map((item) => item.name).join(', ') || null)
      : row.office?.name,
    row.phone,
    row.email,
    row.city,
  ].filter(Boolean).join(' · ') || '—'

  return (
    <div>
      <p className="mb-4 text-sm text-muted">{labels.hint}</p>
      <div className="mb-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
        {!isSupplier && showOfficeFilter ? (
          <OfficeFilterSelect
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
            placeholder={labels.search}
            className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
          <div ref={filterRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
            <button
              type="button"
              aria-label={t('common.filter')}
              aria-expanded={filterOpen}
              className={cn(
                'grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface hover:text-ink',
                status && 'bg-lhms-soft text-lhms',
              )}
              onClick={() => setFilterOpen((open) => !open)}
            >
              <ListFilter size={16} />
            </button>
            {filterOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                {[{ value: '', label: t('common.all') }, ...statuses.map((value) => ({ value, label: t(`kams.status.${value}`) }))].map((option) => (
                  <button
                    key={option.value || 'all'}
                    type="button"
                    className={cn(
                      'flex w-full px-3 py-2.5 text-left text-sm hover:bg-surface',
                      status === option.value && 'bg-lhms-soft font-semibold text-lhms',
                    )}
                    onClick={() => {
                      setStatus(option.value)
                      setFilterOpen(false)
                    }}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </div>
        <div className="flex shrink-0 flex-nowrap items-center gap-2">
          {hasPermission('kams.clients.create') ? (
            <Button className="shrink-0 px-3 sm:px-4" onClick={openCreate}>
              <Plus size={16} /> {labels.add}
            </Button>
          ) : null}
          {hasPermission('kams.clients.view') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('common.export')} onClick={() => void exportClients()}>
              <Download size={16} />
              <span className="hidden sm:inline">{t('common.export')}</span>
            </Button>
          ) : null}
          {hasPermission('kams.clients.view') ? (
          <div
            ref={printMenuRef}
            className="relative shrink-0"
            onMouseDown={(event) => event.stopPropagation()}
          >
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
                    void printClients()
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
                    void previewClients()
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
            <span className="grid h-12 w-12 shrink-0 place-items-center rounded-full bg-lhms text-sm font-bold text-white">
              {initials(row.name)}
            </span>
            <div className="min-w-0 flex-1">
              <div className="truncate font-semibold">{row.name}</div>
              <div className="truncate text-xs text-muted sm:text-sm">{subtitle(row)}</div>
            </div>
            <div className="hidden shrink-0 items-center gap-2 sm:flex">
              <Badge tone={toneFor(row.status)}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              <button type="button" aria-label={t('common.view')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => setViewing(row)}>
                <Eye size={16} />
              </button>
              {hasPermission('kams.clients.update') ? (
              <button type="button" aria-label={t('common.edit')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => openEdit(row)}>
                <Pencil size={16} />
              </button>
              ) : null}
              {hasPermission('kams.clients.delete') ? (
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

      <Modal open={formOpen} title={editing ? labels.edit : labels.add} onClose={() => { setFormOpen(false); resetForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submitClient(e)}>
          {isSupplier ? (
            warehouses.length > 0 ? (
              <div className="space-y-2 rounded-xl border border-line p-3">
                <div className="flex items-center justify-between gap-3">
                  <div className="text-sm font-medium">{t('kams.warehouses')}</div>
                  <label className="flex items-center gap-2 text-sm text-muted">
                    <input
                      type="checkbox"
                      checked={assignAllWarehouses}
                      onChange={(e) => {
                        setAssignAllWarehouses(e.target.checked)
                        if (e.target.checked) setSelectedWarehouseIds(warehouses.map((row) => row.id))
                      }}
                    />
                    {t('kams.allWarehouses')}
                  </label>
                </div>
                <div className="max-h-40 space-y-2 overflow-y-auto">
                  {warehouses.map((warehouse) => (
                    <label key={warehouse.id} className="flex items-center gap-2 text-sm">
                      <input
                        type="checkbox"
                        checked={assignAllWarehouses || selectedWarehouseIds.includes(warehouse.id)}
                        disabled={assignAllWarehouses}
                        onChange={(e) => {
                          setSelectedWarehouseIds((current) => (
                            e.target.checked
                              ? [...current, warehouse.id]
                              : current.filter((id) => id !== warehouse.id)
                          ))
                        }}
                      />
                      <span>{warehouse.name}{warehouse.code ? ` (${warehouse.code})` : ''}</span>
                    </label>
                  ))}
                </div>
              </div>
            ) : (
              <p className="text-sm text-muted">{t('kams.noWarehousesYet')}</p>
            )
          ) : canChooseOffice ? (
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
            <Field label={t('kams.phone')} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
            <Field label={t('kams.email')} type="email" value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </div>
          <div className={cn('grid gap-3', isSupplier ? '' : 'sm:grid-cols-2')}>
            <Field label={t('kams.city')} value={form.city || ''} onChange={(e) => setForm({ ...form, city: e.target.value })} />
            {!isSupplier ? (
              <Select label={t('kams.segment')} value={form.segment || 'retail'} onChange={(e) => setForm({ ...form, segment: e.target.value })}>
                {segments.map((segment) => (
                  <option key={segment} value={segment}>{t(`kams.segmentOption.${segment}`)}</option>
                ))}
              </Select>
            ) : null}
          </div>
          <Select label={t('common.status')} value={form.status || (isSupplier ? 'active' : 'prospect')} onChange={(e) => setForm({ ...form, status: e.target.value })}>
            {statuses.map((value) => (
              <option key={value} value={value}>{t(`kams.status.${value}`)}</option>
            ))}
          </Select>
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

      <Modal open={Boolean(viewing)} title={viewing?.name || labels.title} onClose={() => setViewing(null)}>
        {viewing ? (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              <span className="grid h-20 w-20 place-items-center rounded-full bg-lhms text-xl font-bold text-white">
                {initials(viewing.name)}
              </span>
            </div>
            <div>{isSupplier ? t('kams.warehouses') : t('kams.office')}: <strong>{isSupplier ? ((viewing.warehouses || []).map((item) => item.name).join(', ') || '—') : (viewing.office?.name || '—')}</strong></div>
            <div>{t('kams.email')}: <strong>{viewing.email || '—'}</strong></div>
            <div>{t('kams.phone')}: <strong>{viewing.phone || '—'}</strong></div>
            <div>{t('kams.city')}: <strong>{viewing.city || '—'}</strong></div>
            {!isSupplier ? (
              <div>{t('kams.segment')}: <strong>{t(`kams.segmentOption.${viewing.segment || 'other'}`, { defaultValue: viewing.segment || '—' })}</strong></div>
            ) : null}
            <div>{t('common.status')}: <Badge tone={toneFor(viewing.status)}>{t(`kams.status.${viewing.status}`, { defaultValue: viewing.status })}</Badge></div>
            {viewing.notes ? <div>{t('kams.notes')}: {viewing.notes}</div> : null}
          </div>
        ) : null}
      </Modal>

      <Modal open={Boolean(deleting)} title={labels.remove} onClose={() => setDeleting(null)}>
        <p className="text-sm text-muted">{t(labels.removeConfirm, { name: deleting?.name })}</p>
        <div className="mt-4 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" onClick={() => void removeClient()}>{t('common.delete')}</Button>
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

/** @deprecated Prefer KamsClientsList / KamsSuppliersList */
export function KamsClients() {
  return <KamsClientsList />
}
