import { useEffect, useState, type FormEvent } from 'react'
import { Outlet, useOutletContext } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Package, Search } from 'lucide-react'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

type Line = { product_id: string; quantity: string }
type Product = { id: number; name: string; sku?: string | null; price: number; cost: number; stock: number; image_url?: string | null }
type Agent = { id: number; full_name: string; member_code: string; office_id?: number | null }
type Location = { id: number; name: string; kind: string; office_id?: number | null }
type Actor = { id: number; name: string }
type Transfer = {
  id: number
  reference: string
  status?: string
  transferred_at?: string | null
  received_at?: string | null
  from_depot?: Location | null
  to_depot?: Location & { office?: { name: string } | null } | null
  items?: Array<{ id: number; quantity: number; product?: Product | null }>
  created_by?: number | Actor | null
  dispatched_by?: number | Actor | null
  received_by?: number | Actor | null
  createdBy?: Actor | null
  dispatchedBy?: Actor | null
  receivedBy?: Actor | null
}
type AssignmentItem = {
  id: number
  quantity_out: number
  quantity_returned: number
  quantity_lost: number
  unit_price: number
  unit_cost: number
  product?: Product | null
}
type Assignment = {
  id: number
  reference: string
  status: string
  assigned_at?: string | null
  settled_at?: string | null
  expected_amount: number
  collected_amount: number
  profit_amount: number
  loss_amount: number
  member?: { full_name: string; member_code: string; phone?: string | null } | null
  office?: { name: string } | null
  items?: AssignmentItem[]
}

function emptyLine(): Line {
  return { product_id: '', quantity: '1' }
}

function actorLabel(value: number | Actor | null | undefined): string | undefined {
  if (!value || typeof value === 'number') return undefined
  return value.name
}

export function KamsTransfers() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Transfer[]>([])
  const [warehouses, setWarehouses] = useState<Array<Location & { balances?: Array<{ quantity: number; condition?: string; product_id?: number; product?: Product | null }> }>>([])
  const [depots, setDepots] = useState<Location[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [open, setOpen] = useState(false)
  const [fromId, setFromId] = useState('')
  const [toId, setToId] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [error, setError] = useState('')
  const [stockByProduct, setStockByProduct] = useState<Record<number, number>>({})

  const load = () => api.get('/modules/kams/stock/transfers').then((res) => setRows(res.data.data))

  const loadLookups = () => {
    api.get('/modules/kams/stock/lookups').then((res) => {
      setDepots(res.data.data.depots || [])
      setProducts(res.data.data.products || [])
    })
    api.get('/modules/kams/depots', { params: { kind: 'warehouse', per_page: 100 } }).then((res) => {
      setWarehouses(res.data.data || [])
    })
  }

  const receiveTransfer = async (id: number) => {
    setError('')
    try {
      await api.post(`/modules/kams/stock/transfers/${id}/receive`)
      void load()
      void loadLookups()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  useEffect(() => {
    void load()
    void loadLookups()
  }, [])

  useEffect(() => {
    const warehouse = warehouses.find((row) => String(row.id) === fromId)
    const next: Record<number, number> = {}
    ;(warehouse?.balances || [])
      .filter((item) => (item.condition || 'available') === 'available')
      .forEach((item) => {
        const productId = item.product?.id || item.product_id
        if (!productId) return
        next[productId] = (next[productId] || 0) + (item.quantity || 0)
      })
    setStockByProduct(next)
  }, [fromId, warehouses])

  const requestedByProduct = () => {
    const totals: Record<number, number> = {}
    lines.forEach((line) => {
      if (!line.product_id) return
      const id = Number(line.product_id)
      totals[id] = (totals[id] || 0) + Number(line.quantity || 0)
    })
    return totals
  }

  const availableFor = (productId: string) => {
    if (!productId) return 0
    return stockByProduct[Number(productId)] || 0
  }

  const remainingFor = (productId: string, lineIndex: number) => {
    if (!productId) return 0
    const available = availableFor(productId)
    const usedElsewhere = lines.reduce((sum, line, index) => {
      if (index === lineIndex || line.product_id !== productId) return sum
      return sum + Number(line.quantity || 0)
    }, 0)
    return Math.max(0, available - usedElsewhere)
  }

  const stockOverflow = () => {
    const requested = requestedByProduct()
    return Object.entries(requested).some(([productId, qty]) => qty > (stockByProduct[Number(productId)] || 0))
  }

  const openModal = () => {
    setError('')
    setFromId(warehouses[0] ? String(warehouses[0].id) : '')
    setToId(depots[0] ? String(depots[0].id) : '')
    setLines([emptyLine()])
    setOpen(true)
    void loadLookups()
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    const items = lines.filter((line) => line.product_id).map((line) => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) }))
    if (items.length === 0) {
      setError(t('kams.transferNeedsItems'))
      return
    }
    if (stockOverflow()) {
      setError(t('kams.transferExceedsStock'))
      return
    }
    try {
      await api.post('/modules/kams/stock/transfers', {
        from_depot_id: Number(fromId),
        to_depot_id: Number(toId),
        auto_receive: true,
        items,
      })
      setOpen(false)
      setLines([emptyLine()])
      void load()
      void loadLookups()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const actorName = (row: Transfer) =>
    actorLabel(row.receivedBy) || actorLabel(row.received_by) || actorLabel(row.dispatchedBy) || actorLabel(row.dispatched_by) || actorLabel(row.createdBy) || actorLabel(row.created_by)

  return (
    <div>
      <div className="mb-4 flex justify-end">
        {hasPermission('kams.products.update') ? <Button onClick={openModal}>{t('kams.newTransfer')}</Button> : null}
      </div>
      {error && !open ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.reference}</div>
                <div className="text-sm text-muted">{row.from_depot?.name} → {row.to_depot?.name} ({row.to_depot?.office?.name})</div>
                <div className="mt-1 text-xs text-muted">
                  {t('kams.performedBy')}: {actorName(row) || '—'}
                  {row.transferred_at ? ` · ${new Date(row.transferred_at).toLocaleString()}` : ''}
                  {row.received_at ? ` · ${t('kams.status.received')}: ${new Date(row.received_at).toLocaleString()}` : ''}
                </div>
              </div>
              <Badge tone={row.status === 'in_transit' ? 'amber' : 'green'}>{t(`kams.status.${row.status || 'received'}`, { defaultValue: row.status || 'received' })}</Badge>
            </div>
            <div className="mt-3 space-y-1 text-sm">
              {(row.items || []).map((item) => (
                <div key={item.id} className="flex justify-between">
                  <span>{item.product?.name}</span>
                  <span className="font-semibold">{item.quantity}</span>
                </div>
              ))}
            </div>
            {row.status === 'in_transit' && hasPermission('kams.products.update') ? (
              <Button className="mt-4" onClick={() => void receiveTransfer(row.id)}>{t('kams.receiveTransfer')}</Button>
            ) : null}
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal open={open} title={t('kams.newTransfer')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Select label={t('kams.fromWarehouse')} value={fromId} onChange={(e) => { setFromId(e.target.value); setError('') }} required>
            <option value="">—</option>
            {warehouses.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <Select label={t('kams.toDepot')} value={toId} onChange={(e) => setToId(e.target.value)} required>
            <option value="">—</option>
            {depots.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          {lines.map((line, index) => {
            const available = availableFor(line.product_id)
            const remaining = remainingFor(line.product_id, index)
            const qty = Number(line.quantity || 0)
            const over = Boolean(line.product_id) && qty > remaining
            return (
              <div key={index} className="space-y-1">
                <div className="grid gap-2 sm:grid-cols-[1fr_120px]">
                  <Select
                    label={t('kams.products')}
                    value={line.product_id}
                    onChange={(e) => setLines(lines.map((item, i) => i === index ? { ...item, product_id: e.target.value } : item))}
                    required
                  >
                    <option value="">—</option>
                    {products.map((product) => {
                      const stock = stockByProduct[product.id] || 0
                      return (
                        <option key={product.id} value={product.id} disabled={fromId !== '' && stock <= 0}>
                          {product.name}{fromId ? ` (${stock})` : ''}
                        </option>
                      )
                    })}
                  </Select>
                  <Field
                    label={t('kams.quantity')}
                    type="number"
                    min="1"
                    max={String(Math.max(1, remaining || 1))}
                    value={line.quantity}
                    onChange={(e) => setLines(lines.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))}
                    required
                  />
                </div>
                {line.product_id ? (
                  <p className={`text-xs ${over ? 'text-red-600' : 'text-muted'}`}>
                    {t('kams.availableStock')}: {available}
                    {lines.filter((item) => item.product_id === line.product_id).length > 1 ? ` · ${t('kams.remainingForLine')}: ${remaining}` : ''}
                    {over ? ` — ${t('kams.transferExceedsStock')}` : ''}
                  </p>
                ) : null}
              </div>
            )
          })}
          <Button type="button" variant="ghost" onClick={() => setLines([...lines, emptyLine()])}>{t('kams.addLine')}</Button>
          <Button type="submit" className="w-full" disabled={stockOverflow() || !fromId || !toId}>{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}

export type OperationsOfficeFilter = { officeId: string }

export function KamsAssignmentsWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canView = hasPermission('kams.products.view')
  const [officeId, setOfficeId] = useState('')
  const [offices, setOffices] = useState<Array<{ id: number; name: string; code?: string | null }>>([])

  useEffect(() => {
    api.get('/modules/kams/stock/lookups').then((res) => {
      const list = (res.data.data.offices || []) as Array<{ id: number; name: string; code?: string | null }>
      setOffices(list)
    })
  }, [])

  return (
    <div>
      {offices.length > 1 ? (
        <div className="mb-4 sm:max-w-xs">
          <Select
            className="w-full min-w-0"
            label={t('kams.filterOffice')}
            value={officeId}
            onChange={(event) => setOfficeId(event.target.value)}
          >
            <option value="">{t('kams.allOffices')}</option>
            {offices.map((office) => (
              <option key={office.id} value={office.id}>{office.name}{office.code ? ` · ${office.code}` : ''}</option>
            ))}
          </Select>
        </div>
      ) : null}
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/stock/assignments', end: true, label: t('kams.assignments'), show: canView },
          { to: '/modules/kams/stock/assignments/reports', end: true, label: t('kams.assignmentReports'), show: canView },
          { to: '/modules/kams/stock/assignments/journal', end: true, label: t('kams.stockJournal'), show: canView },
        ]}
      />
      <Outlet context={{ officeId } satisfies OperationsOfficeFilter} />
    </div>
  )
}

export function KamsAssignments() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const { officeId } = useOutletContext<OperationsOfficeFilter>()
  const [rows, setRows] = useState<Assignment[]>([])
  const [agents, setAgents] = useState<Agent[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [adding, setAdding] = useState<Assignment | null>(null)
  const [editing, setEditing] = useState<Assignment | null>(null)
  const [editLines, setEditLines] = useState<Array<{ id: number; quantity: string }>>([])
  const [expandedIds, setExpandedIds] = useState<number[]>([])
  const [settling, setSettling] = useState<Assignment | null>(null)
  const [surplusConfirm, setSurplusConfirm] = useState<{ amount: number } | null>(null)
  const [memberId, setMemberId] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [addLines, setAddLines] = useState<Line[]>([emptyLine()])
  const [prepaid, setPrepaid] = useState('')
  const [collected, setCollected] = useState('')
  const [returns, setReturns] = useState<Record<number, { returned: string; lost: string }>>({})
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const load = (term = query) =>
    api.get('/modules/kams/stock/assignments', {
      params: {
        status: 'open',
        ...(officeId ? { office_id: officeId } : {}),
        ...(term ? { search: term } : {}),
      },
    }).then((res) => setRows(res.data.data))

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    void load(query)
  }, [query, officeId])

  useEffect(() => {
    api.get('/modules/kams/stock/lookups').then((res) => {
      setAgents(res.data.data.agents || [])
      setProducts(res.data.data.products || [])
    })
  }, [])

  const settleTotals = (() => {
    if (!settling) return { amountDue: 0, collectedAmount: 0, prepaidAmount: 0, surplus: 0, shortfall: 0 }
    const amountDue = (settling.items || []).reduce((sum, item) => {
      const returnedQty = Number(returns[item.id]?.returned || 0)
      const lostQty = Number(returns[item.id]?.lost || 0)
      const soldQty = Math.max(0, item.quantity_out - returnedQty - lostQty)
      return sum + soldQty * Number(item.unit_price || 0)
    }, 0)
    const prepaidAmount = Number(settling.collected_amount || 0)
    const collectedAmount = Number(collected || 0)
    const surplus = Math.max(0, collectedAmount - amountDue)
    const shortfall = Math.max(0, amountDue - collectedAmount)
    return {
      amountDue: Math.round(amountDue * 100) / 100,
      collectedAmount: Math.round(collectedAmount * 100) / 100,
      prepaidAmount: Math.round(prepaidAmount * 100) / 100,
      surplus: Math.round(surplus * 100) / 100,
      shortfall: Math.round(shortfall * 100) / 100,
    }
  })()

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/stock/assignments', {
        member_id: Number(memberId),
        items: lines.filter((line) => line.product_id).map((line) => ({ product_id: Number(line.product_id), quantity: Number(line.quantity) })),
      })
      setOpen(false)
      setLines([emptyLine()])
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const submitAdd = async (event: FormEvent) => {
    event.preventDefault()
    if (!adding) return
    setError('')
    const items = addLines.filter((line) => line.product_id).map((line) => ({
      product_id: Number(line.product_id),
      quantity: Number(line.quantity),
    }))
    if (items.length === 0) {
      setError(t('kams.addAtLeastOneProduct'))
      return
    }
    setSaving(true)
    try {
      await api.post(`/modules/kams/stock/assignments/${adding.id}/items`, {
        items,
        collected_amount: Number(prepaid || 0),
      })
      setAdding(null)
      setAddLines([emptyLine()])
      setPrepaid('')
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const submitSettle = async (depositSurplus: boolean) => {
    if (!settling) return
    setError('')
    setSaving(true)
    try {
      await api.post(`/modules/kams/stock/assignments/${settling.id}/settle`, {
        collected_amount: Number(collected),
        deposit_surplus: depositSurplus,
        items: (settling.items || []).map((item) => ({
          id: item.id,
          quantity_returned: Number(returns[item.id]?.returned || 0),
          quantity_lost: Number(returns[item.id]?.lost || 0),
        })),
      })
      setSurplusConfirm(null)
      setSettling(null)
      setCollected('')
      setReturns({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
      setSurplusConfirm(null)
    } finally {
      setSaving(false)
    }
  }

  const settle = async (event: FormEvent) => {
    event.preventDefault()
    if (!settling) return
    const overflow = (settling.items || []).find((item) => {
      const returnedQty = Number(returns[item.id]?.returned || 0)
      const lostQty = Number(returns[item.id]?.lost || 0)
      return returnedQty + lostQty > item.quantity_out
    })
    if (overflow) {
      setError(t('kams.settleQtyMismatch', {
        product: overflow.product?.name || '—',
        out: overflow.quantity_out,
      }))
      return
    }
    if (settleTotals.shortfall > 0) {
      setError(t('kams.settleCashShort'))
      return
    }
    if (settleTotals.surplus > 0) {
      setSurplusConfirm({ amount: settleTotals.surplus })
      return
    }
    await submitSettle(false)
  }

  const closeSettle = () => {
    if (saving) return
    setSettling(null)
    setSurplusConfirm(null)
    setError('')
  }

  const openAdd = (row: Assignment) => {
    setAdding(row)
    setAddLines([emptyLine()])
    setPrepaid('')
    setError('')
  }

  const openEdit = (row: Assignment) => {
    setEditing(row)
    setEditLines((row.items || []).map((item) => ({ id: item.id, quantity: String(item.quantity_out) })))
    setError('')
  }

  const toggleExpanded = (id: number) => {
    setExpandedIds((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]))
  }

  const submitEdit = async (event: FormEvent) => {
    event.preventDefault()
    if (!editing) return
    setError('')
    const items = editLines.map((line) => ({ id: line.id, quantity: Number(line.quantity) }))
    if (items.every((item) => item.quantity <= 0)) {
      setError(t('kams.assignmentMustKeepProduct'))
      return
    }
    setSaving(true)
    try {
      await api.put(`/modules/kams/stock/assignments/${editing.id}`, { items })
      setEditing(null)
      setEditLines([])
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const openSettle = (row: Assignment) => {
    setSettling(row)
    setError('')
    setCollected(row.collected_amount > 0 ? String(row.collected_amount) : '')
    setReturns({})
    setSurplusConfirm(null)
  }

  return (
    <div>
      <p className="mb-4 text-sm text-muted">{t('kams.assignmentFlowHint')}</p>
      <div className="mb-4 flex items-center gap-3">
        <div className="relative flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-xl border border-line bg-white px-3">
          <Search size={16} className="shrink-0 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('kams.searchAssignments')}
            className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
        </div>
        {hasPermission('kams.products.update') ? <Button className="shrink-0" onClick={() => { setError(''); setOpen(true) }}>{t('kams.newAssignment')}</Button> : null}
      </div>
      <div className="grid gap-3">
        {rows.map((row) => {
          const expanded = expandedIds.includes(row.id)
          const items = row.items || []
          const itemCount = items.length
          const quantityTotal = items.reduce((sum, item) => sum + Number(item.quantity_out || 0), 0)
          const valueTotal = items.reduce((sum, item) => sum + Number(item.quantity_out || 0) * Number(item.unit_price || 0), 0)
          return (
            <Card key={row.id} className="overflow-hidden">
              <button
                type="button"
                onClick={() => toggleExpanded(row.id)}
                aria-expanded={expanded}
                className="flex w-full items-start justify-between gap-3 p-5 text-left hover:bg-surface/60"
              >
                <div className="min-w-0">
                  <div className="font-semibold">
                    {row.member?.full_name || row.reference}
                  </div>
                  {row.member?.phone || row.office?.name ? (
                    <div className="text-sm text-muted">
                      {[row.member?.phone, row.office?.name].filter(Boolean).join(' · ')}
                    </div>
                  ) : null}
                  <div className="mt-1 text-xs text-muted">
                    {t('kams.products')}: {itemCount}
                    {' · '}
                    {t('kams.quantity')}: {quantityTotal}
                    {' · '}
                    {t('kams.assignmentValue')}: {money(valueTotal)}
                    {row.status === 'open' && row.collected_amount > 0
                      ? ` · ${t('kams.prepaidCash')} ${money(row.collected_amount)}`
                      : ''}
                  </div>
                </div>
                <div className="flex shrink-0 items-center gap-2">
                  <Badge tone={row.status === 'open' ? 'amber' : 'green'}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
                  <ChevronDown size={18} className={cn('text-muted transition', expanded ? 'rotate-0' : '-rotate-90')} />
                </div>
              </button>
              {expanded ? (
                <div className="border-t border-line bg-surface px-5 py-4">
                  {itemCount === 0 ? (
                    <p className="text-sm text-muted">{t('common.empty')}</p>
                  ) : (
                    <div className={cn(
                      'grid overflow-hidden rounded-xl border border-line bg-white shadow-sm',
                      row.status === 'settled'
                        ? 'grid-cols-[minmax(0,1fr)_minmax(6.5rem,max-content)_minmax(4.5rem,max-content)_minmax(4.5rem,max-content)]'
                        : 'grid-cols-[minmax(0,1fr)_minmax(6.5rem,max-content)_minmax(4.5rem,max-content)]',
                    )}>
                      <div className="col-span-full grid grid-cols-subgrid items-end gap-x-3 border-b border-line bg-[#f4faf5] px-4 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted">
                        <span>{t('kams.product')}</span>
                        <span className="text-right">{t('kams.salePrice')}</span>
                        <span className="text-right">{t('kams.quantity')}</span>
                        {row.status === 'settled' ? <span className="text-right">{t('kams.sold')}</span> : null}
                      </div>
                      {(row.items || []).map((item) => {
                        const sold = Math.max(0, item.quantity_out - item.quantity_returned - item.quantity_lost)
                        return (
                          <div
                            key={item.id}
                            className="col-span-full grid grid-cols-subgrid items-center gap-x-3 border-t border-line px-4 py-3 text-sm"
                          >
                            <div className="flex min-w-0 items-center gap-3">
                              <span className="grid h-10 w-10 shrink-0 overflow-hidden place-items-center rounded-lg bg-lhms-soft text-lhms">
                                {item.product?.image_url ? (
                                  <img src={item.product.image_url} alt={item.product.name || ''} className="h-full w-full object-cover" />
                                ) : (
                                  <Package size={16} />
                                )}
                              </span>
                              <div className="min-w-0">
                                <div className="truncate font-semibold text-ink">{item.product?.name || '—'}</div>
                                {item.product?.sku ? <div className="truncate text-xs text-muted">{item.product.sku}</div> : null}
                              </div>
                            </div>
                            <span className="text-right tabular-nums text-muted">{money(item.unit_price)}</span>
                            <span className="text-right font-semibold tabular-nums">{item.quantity_out}</span>
                            {row.status === 'settled' ? <span className="text-right font-semibold tabular-nums text-lhms">{sold}</span> : null}
                          </div>
                        )
                      })}
                    </div>
                  )}
                  {row.status === 'settled' ? (
                    <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                      <div className="rounded-xl border border-line bg-white px-3 py-2">{t('kams.amountDue')}: <b>{money(row.expected_amount)}</b></div>
                      <div className="rounded-xl border border-line bg-white px-3 py-2">{t('kams.cashCollected')}: <b>{money(row.collected_amount)}</b></div>
                      <div className="rounded-xl border border-line bg-white px-3 py-2">{t('kams.profit')}: <b>{money(row.profit_amount)}</b></div>
                      <div className="rounded-xl border border-line bg-white px-3 py-2">{t('kams.loss')}: <b>{money(row.loss_amount)}</b></div>
                    </div>
                  ) : null}
                </div>
              ) : null}
              {row.status === 'open' && hasPermission('kams.products.update') ? (
                <div className="flex flex-wrap gap-2 border-t border-line px-5 py-4">
                  <Button variant="secondary" onClick={() => openAdd(row)}>{t('common.add')}</Button>
                  <Button variant="ghost" className="bg-lhms/15 text-lhms hover:bg-lhms/25" onClick={() => openEdit(row)}>{t('common.edit')}</Button>
                  <Button onClick={() => openSettle(row)}>{t('kams.settleAssignment')}</Button>
                </div>
              ) : null}
            </Card>
          )
        })}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal open={open} title={t('kams.newAssignment')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Select label={t('kams.agents')} value={memberId} onChange={(e) => setMemberId(e.target.value)} required>
            <option value="">—</option>
            {agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.full_name} ({agent.member_code})</option>)}
          </Select>
          {lines.map((line, index) => (
            <div key={index} className="grid gap-2 sm:grid-cols-[1fr_120px]">
              <Select label={t('kams.products')} value={line.product_id} onChange={(e) => setLines(lines.map((item, i) => i === index ? { ...item, product_id: e.target.value } : item))}>
                <option value="">—</option>
                {products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
              </Select>
              <Field label={t('kams.quantity')} type="number" min="1" value={line.quantity} onChange={(e) => setLines(lines.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))} />
            </div>
          ))}
          <Button type="button" variant="ghost" onClick={() => setLines([...lines, emptyLine()])}>{t('kams.addLine')}</Button>
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(adding)} title={t('kams.addToAssignment')} onClose={() => { if (!saving) { setAdding(null); setError('') } }}>
        <form className="space-y-3" onSubmit={(e) => void submitAdd(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">{adding?.member?.full_name} · {adding?.reference}</p>
          <p className="text-sm text-muted">{t('kams.addToAssignmentHint')}</p>
          {addLines.map((line, index) => (
            <div key={index} className="grid gap-2 sm:grid-cols-[1fr_120px]">
              <SearchCombobox
                label={t('kams.products')}
                value={
                  line.product_id
                    ? (() => {
                        const product = products.find((item) => String(item.id) === line.product_id)
                        return product
                          ? { id: String(product.id), label: product.name, hint: product.sku || undefined }
                          : null
                      })()
                    : null
                }
                items={products.map((product): SearchComboboxItem => ({
                  id: String(product.id),
                  label: product.name,
                  hint: product.sku || undefined,
                }))}
                placeholder={t('kams.searchProducts')}
                emptyLabel={t('common.empty')}
                onSelect={(item) => {
                  setAddLines(addLines.map((row, i) => (
                    i === index ? { ...row, product_id: item?.id || '' } : row
                  )))
                }}
              />
              <Field label={t('kams.quantity')} type="number" min="1" value={line.quantity} onChange={(e) => setAddLines(addLines.map((item, i) => i === index ? { ...item, quantity: e.target.value } : item))} />
            </div>
          ))}
          <Button
            type="button"
            variant="ghost"
            className="bg-lhms/15 text-lhms hover:bg-lhms/25"
            onClick={() => setAddLines([...addLines, emptyLine()])}
          >
            {t('kams.addLine')}
          </Button>
          {(adding?.collected_amount || 0) > 0 ? (
            <p className="text-sm text-muted">{t('kams.prepaidCash')}: <b>{money(adding?.collected_amount || 0)}</b></p>
          ) : null}
          <Field
            label={t('kams.receivePrepaidCash')}
            type="number"
            min="0"
            step="0.01"
            value={prepaid}
            onChange={(e) => setPrepaid(e.target.value)}
          />
          <p className="text-xs text-muted">{t('kams.receivePrepaidCashHint')}</p>
          <Button type="submit" className="w-full" disabled={saving}>{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(editing)} title={t('kams.editAssignment')} onClose={() => { if (!saving) { setEditing(null); setError('') } }}>
        <form className="space-y-3" onSubmit={(e) => void submitEdit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">{editing?.member?.full_name} · {editing?.reference}</p>
          <p className="text-sm text-muted">{t('kams.editAssignmentHint')}</p>
          {(editing?.items || []).map((item) => {
            const line = editLines.find((row) => row.id === item.id)
            return (
              <div key={item.id} className="grid gap-2 rounded-xl border border-line bg-surface p-3 sm:grid-cols-[1fr_120px]">
                <div>
                  <div className="font-semibold">{item.product?.name}</div>
                  {item.product?.sku ? <div className="text-xs text-muted">{item.product.sku}</div> : null}
                  <div className="mt-1 text-xs text-muted">{t('kams.salePrice')}: {money(item.unit_price)}</div>
                </div>
                <Field
                  label={t('kams.quantity')}
                  type="number"
                  min="0"
                  value={line?.quantity ?? String(item.quantity_out)}
                  onChange={(e) => setEditLines(editLines.map((row) => (
                    row.id === item.id ? { ...row, quantity: e.target.value } : row
                  )))}
                />
              </div>
            )
          })}
          <p className="text-xs text-muted">{t('kams.editAssignmentQtyHint')}</p>
          <Button type="submit" className="w-full" disabled={saving}>{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(settling) && !surplusConfirm} title={t('kams.settleAssignment')} onClose={closeSettle}>
        <form className="space-y-3" onSubmit={(e) => void settle(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">{t('kams.closeDayHint')}</p>
          <p className="text-sm text-muted"><span className="font-semibold text-ink">{settling?.member?.full_name}</span>{settling?.reference ? ` · ${settling.reference}` : ''}</p>
          {(settling?.items || []).map((item) => {
            const returnedQty = Number(returns[item.id]?.returned || 0)
            const lostQty = Number(returns[item.id]?.lost || 0)
            const soldQty = Math.max(0, item.quantity_out - returnedQty - lostQty)
            const lineDue = Math.round(soldQty * Number(item.unit_price || 0) * 100) / 100
            return (
            <div key={item.id} className="space-y-2 rounded-xl bg-surface p-3">
              <div className="flex items-start gap-3">
                <span className="grid h-12 w-12 shrink-0 overflow-hidden place-items-center rounded-lg bg-lhms-soft text-lhms">
                  {item.product?.image_url ? (
                    <img src={item.product.image_url} alt={item.product.name || ''} className="h-full w-full object-cover" />
                  ) : (
                    <Package size={18} />
                  )}
                </span>
                <div className="min-w-0 flex-1 space-y-2">
                  <div className="font-semibold">{item.product?.name} · {item.quantity_out} {t('kams.assignedQty')}</div>
                  <div className="grid grid-cols-2 gap-2">
                    <Field label={t('kams.returned')} type="number" min="0" max={Math.max(0, item.quantity_out - lostQty)} value={returns[item.id]?.returned || '0'} onChange={(e) => setReturns({ ...returns, [item.id]: { returned: e.target.value, lost: returns[item.id]?.lost || '0' } })} />
                    <Field label={t('kams.lost')} type="number" min="0" max={Math.max(0, item.quantity_out - returnedQty)} value={returns[item.id]?.lost || '0'} onChange={(e) => setReturns({ ...returns, [item.id]: { returned: returns[item.id]?.returned || '0', lost: e.target.value } })} />
                  </div>
                </div>
              </div>
              <div className="flex justify-between gap-3 text-sm text-muted">
                <span>{t('kams.sold')}: <b>{soldQty}</b></span>
                <span>{t('kams.lineDue')}: <b>{money(lineDue)}</b></span>
              </div>
            </div>
            )
          })}
          <div className="rounded-xl border border-line bg-white p-3 space-y-1 text-sm">
            <div className="flex items-center justify-between gap-3 rounded-lg bg-lhms-soft px-3 py-2 text-lhms">
              <span>{t('kams.amountDue')}</span>
              <b className="text-base">{money(settleTotals.amountDue)}</b>
            </div>
            {settleTotals.prepaidAmount > 0 ? (
              <div className="flex justify-between gap-3 text-muted">
                <span>{t('kams.prepaidCash')}</span>
                <b>{money(settleTotals.prepaidAmount)}</b>
              </div>
            ) : null}
            {settleTotals.surplus > 0 ? (
              <div className="flex justify-between gap-3 text-lhms">
                <span>{t('kams.surplus')}</span>
                <b>{money(settleTotals.surplus)}</b>
              </div>
            ) : null}
            {settleTotals.shortfall > 0 && Number(collected || 0) > 0 ? (
              <div className="flex justify-between gap-3 text-danger">
                <span>{t('kams.shortfall')}</span>
                <b>{money(settleTotals.shortfall)}</b>
              </div>
            ) : null}
          </div>
          <Field label={t('kams.cashCollected')} type="number" min="0" step="0.01" value={collected} onChange={(e) => setCollected(e.target.value)} required />
          {settleTotals.prepaidAmount > 0 ? (
            <p className="text-xs text-muted">{t('kams.cashCollectedIncludesPrepaid')}</p>
          ) : null}
          <Button type="submit" className="w-full" disabled={saving || settleTotals.shortfall > 0}>{t('kams.settleNow')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(surplusConfirm)} title={t('kams.surplusConfirmTitle')} onClose={() => { if (!saving) setSurplusConfirm(null) }}>
        <div className="space-y-4">
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">
            {t('kams.surplusConfirmBody', {
              surplus: money(surplusConfirm?.amount || 0),
              due: money(settleTotals.amountDue),
              collected: money(settleTotals.collectedAmount),
              agent: settling?.member?.full_name || '',
            })}
          </p>
          <div className="grid gap-2 sm:grid-cols-2">
            <Button type="button" variant="secondary" disabled={saving} onClick={() => setSurplusConfirm(null)}>{t('common.cancel')}</Button>
            <Button type="button" disabled={saving} onClick={() => void submitSettle(true)}>{t('kams.surplusDeposit')}</Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}
