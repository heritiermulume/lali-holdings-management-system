import { ArrowLeft, ChevronDown, Download, Eye, ListFilter, Printer, Search } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { Badge, Button, Card, Empty, Field, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
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
    setLoading(true)
    return api.get('/modules/kams/stock/movements', { params: { ...listParams(term), per_page: 100 } })
      .then((res) => {
        setRows((res.data.data as Movement[]) || [])
        const metaLocation = res.data.meta?.location as Location | undefined
        if (metaLocation) setLocation(metaLocation)
      })
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    void load()
  }, [depotId, isDepot, query, type, direction, productId, from, to])

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
          <Button variant="secondary" className="h-11 w-11 shrink-0 px-0 sm:w-auto sm:px-4" aria-label={t('common.export')} onClick={() => void exportRows()}>
            <Download size={16} />
            <span className="hidden sm:inline">{t('common.export')}</span>
          </Button>
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
              </div>
            ) : null}
          </div>
        </div>
      </div>

      {loading ? (
        <LoadingScreen />
      ) : rows.length === 0 ? (
        <Empty>{t('common.empty')}</Empty>
      ) : (
        <div className="grid gap-3">
          {rows.map((row) => (
            <Card key={row.id} className="p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex flex-wrap items-center gap-2">
                    <div className="font-semibold">{row.product?.name || '—'}</div>
                    <Badge tone={row.direction === 'in' ? 'green' : row.direction === 'out' ? 'amber' : 'slate'}>
                      {directionLabel(row.direction)}
                    </Badge>
                    <Badge tone="slate">{typeLabel(row.type)}</Badge>
                  </div>
                  <div className="mt-1 text-sm text-muted">
                    {formatDateTime(row.occurred_at, locale)}
                    {row.document_reference ? ` · ${row.document_reference}` : ''}
                    {row.product?.sku ? ` · ${row.product.sku}` : ''}
                  </div>
                  <div className="mt-2 text-sm text-muted">
                    {(row.from_depot?.name || '—')} → {(row.to_depot?.name || '—')}
                  </div>
                  <div className="mt-2 text-sm">
                    <span className="text-muted">{t('kams.performedBy')} : </span>
                    <span className="font-semibold text-ink">{performerName(row) || '—'}</span>
                  </div>
                  {row.notes ? <div className="mt-1 text-sm text-muted">{row.notes}</div> : null}
                </div>
                <div className="text-right">
                  <div className="text-lg font-bold">{row.quantity}</div>
                </div>
              </div>
            </Card>
          ))}
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
