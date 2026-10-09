import { ArrowLeft, ChevronDown, Download, Eye, ListFilter, Printer, Search } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { PaginationButtons } from '../../components/PaginationButtons'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { Badge, Button, Empty, Field, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
import { trackLoadSpinner } from '../../lib/dataSync'
import { cn } from '../../lib/cn'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'

type Location = { id: number; name: string; code?: string | null; kind: string; status?: string }
type Product = { id: number; name: string; sku?: string | null }
type Movement = {
  id: number
  quantity: number
  type: string
  status: string
  condition?: string | null
  notes?: string | null
  unit_cost?: number | null
  occurred_at?: string | null
  direction?: 'in' | 'out' | 'internal'
  document_reference?: string | null
  performed_by?: { id: number | null; name: string } | null
  product?: Product | null
  from_depot?: Location | null
  to_depot?: Location | null
  member?: { id: number; full_name: string; member_code?: string | null } | null
  user?: { id: number; name?: string | null; first_name?: string | null; last_name?: string | null } | null
}

const MOVEMENT_TYPES = [
  'receipt',
  'transfer',
  'transfer_receive',
  'assignment',
  'sale',
  'return',
  'loss',
  'adjustment',
] as const

const DIRECTIONS = ['', 'in', 'out', 'internal'] as const

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

function formatDateTime(value: string | null | undefined, locale: string) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString(locale, {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function performerName(row: Movement) {
  if (row.performed_by?.name?.trim()) return row.performed_by.name.trim()
  const userName = row.user?.name?.trim()
    || [row.user?.first_name, row.user?.last_name].filter(Boolean).join(' ').trim()
  if (userName) return userName
  return row.member?.full_name?.trim() || ''
}

export function KamsWarehouseMovements() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const canExport = hasPermission('kams.stock.reports.print')
  const canPrint = hasPermission('kams.stock.reports.print')
  const canPreview = hasPermission('kams.stock.reports.preview')
  const { warehouseId, depotId: depotParam } = useParams()
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const depotId = Number(warehouseId || depotParam)
  const isDepot = Boolean(depotParam)

  const [rows, setRows] = useState<Movement[]>([])
  const [location, setLocation] = useState<Location | null>(null)
  const [products, setProducts] = useState<Product[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [type, setType] = useState('')
  const [direction, setDirection] = useState('')
  const [productId, setProductId] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [filterOpen, setFilterOpen] = useState(false)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)

  const activeFilters = useMemo(
    () => [type, direction, productId, from, to].filter(Boolean).length,
    [type, direction, productId, from, to],
  )

  const listParams = (term = query) => ({
    ...(isDepot ? { depot_id: depotId } : { warehouse_id: depotId }),
    per_page: 1000,
    ...(term ? { search: term } : {}),
    ...(type ? { type } : {}),
    ...(direction ? { direction } : {}),
    ...(productId ? { product_id: Number(productId) } : {}),
    ...(from ? { from } : {}),
    ...(to ? { to } : {}),
  })

  const load = (term = query) => {
    if (!depotId) return Promise.resolve()
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/stock/movements', { params: listParams(term) })
      .then((res) => {
        setRows((res.data.data as Movement[]) || [])
        const metaLocation = res.data.meta?.location as Location | undefined
        if (metaLocation) setLocation(metaLocation)
      })
      .finally(stop)
  }

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    void load()
    setPage(1)
  }, [depotId, isDepot, query, type, direction, productId, from, to])

  useLiveData(() => load(query), { enabled: Boolean(depotId) })

  useEffect(() => {
    api.get('/modules/kams/stock/lookups').then((res) => {
      setProducts((res.data.data.products || []) as Product[])
    })
  }, [])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!filterRef.current?.contains(event.target as Node)) setFilterOpen(false)
      if (!printMenuRef.current?.contains(event.target as Node)) setPrintMenuOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  const typeLabel = (value: string) => t(`kams.movementType.${value}`, { defaultValue: value })
  const directionLabel = (value?: string | null) => {
    if (!value) return '—'
    return t(`kams.movementDirection.${value}`, { defaultValue: value })
  }

  const lastPage = Math.max(1, Math.ceil(rows.length / perPage) || 1)
  const safePage = Math.min(page, lastPage)
  const visible = rows.slice((safePage - 1) * perPage, safePage * perPage)
  const rangeStart = rows.length === 0 ? 0 : (safePage - 1) * perPage + 1
  const rangeEnd = Math.min(safePage * perPage, rows.length)

  const headers = () => [
    t('kams.occurredAt'),
    t('kams.movementTypeLabel'),
    t('kams.direction'),
    t('kams.product'),
    t('kams.quantity'),
    t('kams.fromLocation'),
    t('kams.toLocation'),
    t('kams.documentRef'),
    t('kams.performedBy'),
    t('kams.notes'),
  ]

  const columns = (row: Movement) => [
    formatDateTime(row.occurred_at, locale),
    typeLabel(row.type),
    directionLabel(row.direction),
    row.product?.name || '—',
    String(row.quantity),
    row.from_depot?.name || '—',
    row.to_depot?.name || '—',
    row.document_reference || '—',
    performerName(row) || '—',
    row.notes || '',
  ]

  const printOptions = (list: Movement[]) => ({
    title: isDepot ? t('kams.depotMovements') : t('kams.warehouseMovements'),
    module: t('kams.title'),
    subtitle: location ? `${location.name}${location.code ? ` · ${location.code}` : ''}` : (isDepot ? t('kams.depot') : t('kams.warehouses')),
    meta: [
      { label: t('common.filter'), value: activeFilters || query ? t('kams.filteredResults') : t('common.all') },
      { label: t('common.records'), value: String(list.length) },
      { label: t('common.printedOn'), value: new Date().toLocaleString(locale) },
    ],
    headers: headers(),
    rows: list.map(columns),
    emptyLabel: t('common.empty'),
    footerNote: t('common.confidential'),
    orientation: 'landscape' as const,
  })

  const loadForPrint = async () => withBusy('data', async () => {
    const res = await api.get('/modules/kams/stock/movements', { params: listParams() })
    return (res.data.data as Movement[]) || []
  })

  const exportRows = async () => {
    const list = await loadForPrint()
    const slug = (location?.code || location?.name || 'warehouse').toLowerCase().replace(/\s+/g, '-')
    downloadCsv(`mouvements-${slug}-${new Date().toISOString().slice(0, 10)}.csv`, [
      headers(),
      ...list.map(columns),
    ])
  }

  const printRows = async () => {
    const list = await loadForPrint()
    printHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const previewRows = async () => {
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(rows)))
    const list = await loadForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(list)))
  }

  if (!depotId) {
    return <Empty>{t('common.empty')}</Empty>
  }

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0">
          <Link
            to={isDepot ? '/modules/kams/stock/depots' : '/modules/kams/stock/warehouses'}
            className="mb-2 inline-flex items-center gap-1.5 text-sm font-semibold text-lhms hover:underline"
          >
            <ArrowLeft size={16} />
            {isDepot ? t('kams.backToDepots') : t('kams.backToWarehouses')}
          </Link>
          <h2 className="text-xl font-bold text-ink">
            {isDepot ? t('kams.depotMovements') : t('kams.warehouseMovements')}
          </h2>
          <p className="mt-1 text-sm text-muted">
            {location ? `${location.name}${location.code ? ` · ${location.code}` : ''}` : t('common.loading')}
          </p>
        </div>
      </div>

      <div className="mb-4 flex flex-col-reverse gap-3 sm:flex-row sm:items-center">
        <div className="relative flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-xl border border-line bg-white px-3">
          <Search size={16} className="shrink-0 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('kams.searchMovements')}
            className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
          <div ref={filterRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
            <button
              type="button"
              aria-label={t('common.filter')}
              aria-expanded={filterOpen}
              className={cn(
                'relative grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface hover:text-ink',
                activeFilters > 0 && 'bg-lhms-soft text-lhms',
              )}
              onClick={() => setFilterOpen((open) => !open)}
            >
              <ListFilter size={16} />
              {activeFilters > 0 ? (
                <span className="absolute -right-0.5 -top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-lhms px-1 text-[10px] font-bold text-white">
                  {activeFilters}
                </span>
              ) : null}
            </button>
            {filterOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-72 space-y-3 rounded-xl border border-line bg-white p-3 shadow-xl">
                <Select label={t('kams.movementTypeLabel')} value={type} onChange={(e) => setType(e.target.value)}>
                  <option value="">{t('common.all')}</option>
                  {MOVEMENT_TYPES.map((value) => (
                    <option key={value} value={value}>{typeLabel(value)}</option>
                  ))}
                </Select>
                <Select label={t('kams.direction')} value={direction} onChange={(e) => setDirection(e.target.value)}>
                  {DIRECTIONS.map((value) => (
                    <option key={value || 'all'} value={value}>
                      {value ? directionLabel(value) : t('common.all')}
                    </option>
                  ))}
                </Select>
                <Select label={t('kams.product')} value={productId} onChange={(e) => setProductId(e.target.value)}>
                  <option value="">{t('common.all')}</option>
                  {products.map((product) => (
                    <option key={product.id} value={product.id}>
                      {product.name}{product.sku ? ` (${product.sku})` : ''}
                    </option>
                  ))}
                </Select>
                <Field label={t('kams.dateFrom')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
                <Field label={t('kams.dateTo')} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
                {activeFilters > 0 ? (
                  <Button
                    variant="secondary"
                    className="w-full"
                    onClick={() => {
                      setType('')
                      setDirection('')
                      setProductId('')
                      setFrom('')
                      setTo('')
                    }}
                  >
                    {t('kams.clearFilters')}
                  </Button>
                ) : null}
              </div>
            ) : null}
          </div>
        </div>

        <div className="flex shrink-0 flex-nowrap items-center gap-2">
          {canExport ? (
          <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('common.export')} onClick={() => void exportRows()}>
            <Download size={16} />
            <span className="hidden sm:inline">{t('common.export')}</span>
          </Button>
          ) : null}
          {canPrint || canPreview ? (
          <div ref={printMenuRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
            <Button
              variant="secondary"
              className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4"
              aria-label={t('common.print')}
              aria-expanded={printMenuOpen}
              onClick={() => setPrintMenuOpen((open) => !open)}
            >
              <Printer size={16} />
              <span className="hidden sm:inline">{t('common.print')}</span>
              <ChevronDown size={14} className="hidden sm:inline" />
            </Button>
            {printMenuOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-52 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                {canPreview ? (
                <button
                  type="button"
                  className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                  onClick={() => {
                    setPrintMenuOpen(false)
                    void previewRows()
                  }}
                >
                  <Eye size={14} /> {t('common.preview')}
                </button>
                ) : null}
                {canPrint ? (
                <button
                  type="button"
                  className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                  onClick={() => {
                    setPrintMenuOpen(false)
                    void printRows()
                  }}
                >
                  <Printer size={14} /> {t('common.print')}
                </button>
                ) : null}
              </div>
            ) : null}
          </div>
          ) : null}
        </div>
      </div>

      {loading ? (
        <LoadingScreen />
      ) : (
        <div className="overflow-hidden rounded-2xl border border-line">
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-lhms text-white">
                <tr className="text-left text-xs font-semibold tracking-wide">
                  <th className="bg-lhms px-3 py-3 font-semibold whitespace-nowrap">{t('kams.occurredAt')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold whitespace-nowrap">{t('kams.movementTypeLabel')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold whitespace-nowrap">{t('kams.direction')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.product')}</th>
                  <th className="bg-lhms px-3 py-3 text-right font-semibold whitespace-nowrap">{t('kams.quantity')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.fromLocation')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.toLocation')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold whitespace-nowrap">{t('kams.documentRef')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold whitespace-nowrap">{t('kams.performedBy')}</th>
                  <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.notes')}</th>
                </tr>
              </thead>
              <tbody>
                {visible.length === 0 ? (
                  <tr>
                    <td colSpan={10} className="px-3 py-8 text-center text-muted">{t('common.empty')}</td>
                  </tr>
                ) : visible.map((row, index) => (
                  <tr
                    key={row.id}
                    className={cn(
                      'border-t border-line',
                      index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white',
                    )}
                  >
                    <td className="w-px whitespace-nowrap px-3 py-1.5 text-muted">{formatDateTime(row.occurred_at, locale)}</td>
                    <td className="px-3 py-1.5 font-semibold whitespace-nowrap">{typeLabel(row.type)}</td>
                    <td className="px-3 py-1.5 whitespace-nowrap">
                      <Badge tone={row.direction === 'in' ? 'green' : row.direction === 'out' ? 'amber' : 'slate'}>
                        {directionLabel(row.direction)}
                      </Badge>
                    </td>
                    <td className="max-w-[14rem] truncate px-3 py-1.5">
                      <div className="font-semibold">{row.product?.name || '—'}</div>
                      {row.product?.sku ? <div className="text-xs text-muted">{row.product.sku}</div> : null}
                    </td>
                    <td className="px-3 py-1.5 text-right font-bold whitespace-nowrap">{row.quantity}</td>
                    <td className="max-w-[10rem] truncate px-3 py-1.5">{row.from_depot?.name || '—'}</td>
                    <td className="max-w-[10rem] truncate px-3 py-1.5">{row.to_depot?.name || '—'}</td>
                    <td className="px-3 py-1.5 whitespace-nowrap text-muted">{row.document_reference || '—'}</td>
                    <td className="max-w-[10rem] truncate px-3 py-1.5">{performerName(row) || '—'}</td>
                    <td className="max-w-[14rem] truncate px-3 py-1.5 text-muted">{row.notes || '—'}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-col gap-3 border-t border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-muted">{t('kams.pageRange', { from: rangeStart, to: rangeEnd, total: rows.length })}</p>
            <div className="flex flex-wrap items-center gap-2">
              <label className="flex items-center gap-2 text-sm text-muted">
                {t('kams.rowsPerPage')}
                <select
                  value={perPage}
                  onChange={(event) => {
                    setPerPage(Number(event.target.value))
                    setPage(1)
                  }}
                  className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink"
                >
                  {[10, 20, 50].map((size) => <option key={size} value={size}>{size}</option>)}
                </select>
              </label>
              <PaginationButtons
                page={safePage}
                lastPage={lastPage}
                onPrev={() => setPage((value) => Math.max(1, value - 1))}
                onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
              />
            </div>
          </div>
        </div>
      )}

      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />
    </div>
  )
}
