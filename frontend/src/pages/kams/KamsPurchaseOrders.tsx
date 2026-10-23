import { ChevronDown, Info, Pencil, ScrollText, Trash2 } from 'lucide-react'
import { useEffect, useState, type FormEvent, Fragment } from 'react'
import { useTranslation } from 'react-i18next'
import { PaginationButtons, showListPagination } from '../../components/PaginationButtons'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'

type Product = { id: number; name: string; sku?: string | null; company_price?: number; cost?: number; avg_cost?: number }
type Warehouse = { id: number; name: string }
type Supplier = { id: number; name: string }
type Item = {
  id: number
  product_id: number
  quantity: number
  received_quantity: number
  unit_cost: number
  line_total: number
  product?: { id: number; name: string; sku?: string | null } | null
}
type Order = {
  id: number
  reference: string
  status: string
  ordered_at: string
  total: number
  notes?: string | null
  supplier_name?: string | null
  warehouse?: Warehouse | null
  items?: Item[]
}
type Lookups = { warehouses: Warehouse[]; products: Product[]; suppliers: Supplier[] }
type DraftLine = { id?: number; product_id: string; quantity: string; unit_cost: string; received_quantity?: number }
type JournalChange = { field: string; before: unknown; after: unknown }
type JournalEntry = {
  id: number
  action: string
  order_id?: number | null
  reference?: string | null
  created_at?: string | null
  user?: { id: number; name: string } | null
  changes: JournalChange[]
}

function purchaseCost(product?: Product | null) {
  const avg = Number(product?.avg_cost || 0)
  if (avg > 0) return avg
  const company = Number(product?.company_price || 0)
  if (company > 0) return company
  return Number(product?.cost || 0)
}

function receivedAmount(order: Order) {
  return (order.items || []).reduce((sum, item) => sum + item.received_quantity * item.unit_cost, 0)
}

export function KamsPurchaseOrders() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('kams.stock.orders.update')
  const canDelete = hasPermission('kams.stock.orders.delete')
  const canReceiveOrder = hasPermission('kams.stock.orders.receive')
  const canCancelOrder = hasPermission('kams.stock.orders.cancel')
  const [orders, setOrders] = useState<Order[]>([])
  const [totals, setTotals] = useState({ ordered_amount: 0, received_amount: 0, outstanding_amount: 0 })
  const [lookups, setLookups] = useState<Lookups | null>(null)
  const [openId, setOpenId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [creating, setCreating] = useState(false)
  const [editing, setEditing] = useState<Order | null>(null)
  const [deleting, setDeleting] = useState<Order | null>(null)
  const [journalOrder, setJournalOrder] = useState<Order | null>(null)
  const [journalOpen, setJournalOpen] = useState(false)
  const [journal, setJournal] = useState<JournalEntry[]>([])
  const [receiving, setReceiving] = useState<Order | null>(null)
  const [receiveQty, setReceiveQty] = useState<Record<number, string>>({})
  const [form, setForm] = useState({ warehouse_id: '', supplier_id: '', ordered_at: '', notes: '' })
  const [lines, setLines] = useState<DraftLine[]>([{ product_id: '', quantity: '1', unit_cost: '' }])
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)

  const load = () => api.get('/modules/kams/stock/purchase-orders', { params: { per_page: 100 } }).then((res) => {
    setOrders(res.data.data || [])
    setTotals({
      ordered_amount: Number(res.data.meta?.ordered_amount || 0),
      received_amount: Number(res.data.meta?.received_amount || 0),
      outstanding_amount: Number(res.data.meta?.outstanding_amount || 0),
    })
  })

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
  }, [])
  useLiveData(load)

  const openCreate = () => {
    setEditing(null)
    setForm({
      warehouse_id: lookups?.warehouses[0] ? String(lookups.warehouses[0].id) : '',
      supplier_id: '',
      ordered_at: new Date().toISOString().slice(0, 10),
      notes: '',
    })
    setLines([{ product_id: '', quantity: '1', unit_cost: '' }])
    setError('')
    setCreating(true)
  }

  const openEdit = (order: Order) => {
    const supplier = lookups?.suppliers.find((row) => row.name === order.supplier_name)
    setEditing(order)
    setForm({
      warehouse_id: order.warehouse?.id ? String(order.warehouse.id) : '',
      supplier_id: supplier ? String(supplier.id) : '',
      ordered_at: String(order.ordered_at).slice(0, 10),
      notes: order.notes || '',
    })
    setLines((order.items || []).map((item) => ({
      id: item.id,
      product_id: String(item.product_id),
      quantity: String(item.quantity),
      unit_cost: String(item.unit_cost),
      received_quantity: item.received_quantity,
    })))
    setError('')
    setCreating(true)
  }

  const openJournal = async (order?: Order) => {
    setJournalOrder(order || null)
    setJournalOpen(true)
    const res = await api.get('/modules/kams/stock/purchase-orders/journal', {
      params: { per_page: 100, order_id: order?.id },
    })
    setJournal(res.data.data || [])
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    setSaving(true)
    try {
      if (editing) {
        await api.put(`/modules/kams/stock/purchase-orders/${editing.id}`, {
          warehouse_id: Number(form.warehouse_id),
          supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
          ordered_at: form.ordered_at,
          notes: form.notes || null,
          items: lines.map((line) => ({
            id: line.id,
            product_id: Number(line.product_id),
            quantity: Number(line.quantity),
            unit_cost: Number(line.unit_cost || 0),
          })),
        })
      } else {
        await api.post('/modules/kams/stock/purchase-orders', {
          warehouse_id: Number(form.warehouse_id),
          supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
          ordered_at: form.ordered_at,
          notes: form.notes || null,
          items: lines.map((line) => ({
            product_id: Number(line.product_id),
            quantity: Number(line.quantity),
            unit_cost: Number(line.unit_cost || 0),
          })),
        })
      }
      setCreating(false)
      setEditing(null)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const openReceive = (order: Order) => {
    const qty: Record<number, string> = {}
    for (const item of order.items || []) {
      qty[item.id] = String(Math.max(0, item.quantity - item.received_quantity))
    }
    setReceiveQty(qty)
    setReceiving(order)
    setError('')
  }

  const submitReceive = async (event: FormEvent) => {
    event.preventDefault()
    if (!receiving) return
    setSaving(true)
    setError('')
    try {
      await api.post(`/modules/kams/stock/purchase-orders/${receiving.id}/receive`, {
        items: (receiving.items || []).map((item) => ({
          id: item.id,
          quantity: Number(receiveQty[item.id] || 0),
        })),
      })
      setReceiving(null)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const cancelOrder = async (order: Order) => {
    setError('')
    try {
      await api.post(`/modules/kams/stock/purchase-orders/${order.id}/cancel`)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex items-center justify-end">
        <div className="flex shrink-0 flex-nowrap items-center justify-end gap-2">
          {hasPermission('kams.stock.orders.create') ? <Button className="shrink-0" onClick={openCreate}>{t('kams.newPurchaseOrder')}</Button> : null}
          <Button variant="secondary" className="shrink-0" onClick={() => void openJournal()}>
            <ScrollText size={16} />
            {t('kams.orderJournal')}
          </Button>
        </div>
      </div>
      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <Card className="p-4"><div className="text-sm text-muted">{t('kams.orderedAmount')}</div><div className="mt-1 text-xl font-extrabold">{money(totals.ordered_amount)}</div></Card>
        <Card className="p-4"><div className="text-sm text-muted">{t('kams.receivedAmount')}</div><div className="mt-1 text-xl font-extrabold">{money(totals.received_amount)}</div></Card>
        <Card className="p-4"><div className="text-sm text-muted">{t('kams.outstandingAmount')}</div><div className="mt-1 text-xl font-extrabold">{money(totals.outstanding_amount)}</div></Card>
      </div>
      {error && !creating && !receiving ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}
      {orders.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
        <OrdersTable
          orders={orders}
          page={page}
          perPage={perPage}
          onPage={setPage}
          onPerPage={(size) => { setPerPage(size); setPage(1) }}
          openId={openId}
          onToggle={(id) => setOpenId(openId === id ? null : id)}
          canEdit={canEdit}
          canDelete={canDelete}
          canReceive={canReceiveOrder}
          canCancel={canCancelOrder}
          onDetails={(order) => void openJournal(order)}
          onEdit={openEdit}
          onDelete={setDeleting}
          onReceive={openReceive}
          onCancel={(order) => void cancelOrder(order)}
        />
      )}

      <Modal open={creating} title={editing ? t('kams.editPurchaseOrder') : t('kams.newPurchaseOrder')} onClose={() => { setCreating(false); setEditing(null) }}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Select label={t('kams.warehouse')} value={form.warehouse_id} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })} required>
            <option value="">—</option>
            {(lookups?.warehouses || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <SearchCombobox
            label={t('kams.supplier')}
            value={form.supplier_id ? { id: form.supplier_id, label: lookups?.suppliers.find((row) => String(row.id) === form.supplier_id)?.name || '' } : null}
            items={(lookups?.suppliers || []).map((row): SearchComboboxItem => ({ id: String(row.id), label: row.name }))}
            placeholder={t('kams.searchSuppliers')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, supplier_id: item?.id || '' })}
          />
          <Field label={t('kams.orderDate')} type="date" value={form.ordered_at} onChange={(e) => setForm({ ...form, ordered_at: e.target.value })} required />
          {lines.map((line, index) => {
            const locked = (line.received_quantity || 0) > 0
            return (
            <div key={index} className="grid gap-2 sm:grid-cols-[1fr_90px_120px]">
              <SearchCombobox
                label={t('kams.product')}
                value={line.product_id ? { id: line.product_id, label: lookups?.products.find((row) => String(row.id) === line.product_id)?.name || line.product_id } : null}
                items={(lookups?.products || []).map((row): SearchComboboxItem => ({ id: String(row.id), label: row.name, hint: row.sku || undefined }))}
                placeholder={t('kams.searchProducts')}
                emptyLabel={t('common.empty')}
                onSelect={(item) => {
                  if (locked) return
                  const product = lookups?.products.find((row) => String(row.id) === item?.id)
                  setLines((current) => current.map((entry, i) => i === index ? {
                    ...entry,
                    product_id: item?.id || '',
                    unit_cost: product ? String(purchaseCost(product)) : entry.unit_cost,
                  } : entry))
                }}
              />
              <Field label={t('kams.quantity')} type="number" min={String(line.received_quantity || 1)} value={line.quantity} onChange={(e) => setLines((current) => current.map((entry, i) => i === index ? { ...entry, quantity: e.target.value } : entry))} required />
              <Field label={t('kams.purchasePrice')} type="number" min="0" step="0.01" value={line.unit_cost} disabled={locked} onChange={(e) => setLines((current) => current.map((entry, i) => i === index ? { ...entry, unit_cost: e.target.value } : entry))} required />
            </div>
            )
          })}
          {!editing || (editing.items || []).every((item) => item.received_quantity === 0) ? (
            <button type="button" className="text-sm font-medium text-lhms" onClick={() => setLines((current) => [...current, { product_id: '', quantity: '1', unit_cost: '' }])}>
              {t('kams.addOrderLine')}
            </button>
          ) : null}
          <Field label={t('kams.notes')} value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(receiving)} title={t('kams.receiveOrder')} onClose={() => setReceiving(null)}>
        {receiving ? (
          <form className="space-y-3" onSubmit={(e) => void submitReceive(e)}>
            {error ? <p className="text-sm text-red-600">{error}</p> : null}
            <p className="text-sm text-muted">{receiving.reference} · {receiving.warehouse?.name}</p>
            {(receiving.items || []).map((item) => {
              const remaining = item.quantity - item.received_quantity
              if (remaining <= 0) return null
              return (
                <Field
                  key={item.id}
                  label={`${item.product?.name || ''} · ${t('kams.remainingQty')} ${remaining}`}
                  type="number"
                  min="0"
                  max={remaining}
                  value={receiveQty[item.id] ?? ''}
                  onChange={(e) => setReceiveQty((current) => ({ ...current, [item.id]: e.target.value }))}
                />
              )
            })}
            <div className="flex gap-2">
              <Button type="button" variant="secondary" className="flex-1" onClick={() => {
                const qty: Record<number, string> = {}
                for (const item of receiving.items || []) qty[item.id] = String(Math.max(0, item.quantity - item.received_quantity))
                setReceiveQty(qty)
              }}>{t('kams.receiveAll')}</Button>
              <Button type="submit" className="flex-1" disabled={saving}>{saving ? t('common.loading') : t('kams.receiveOrder')}</Button>
            </div>
          </form>
        ) : null}
      </Modal>

      <Modal open={Boolean(deleting)} title={t('kams.deletePurchaseOrder')} onClose={() => setDeleting(null)}>
        <div className="space-y-4">
          <p className="text-sm text-muted">{t('kams.deletePurchaseOrderConfirm', { reference: deleting?.reference })}</p>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
            <Button onClick={() => {
              if (!deleting) return
              void api.delete(`/modules/kams/stock/purchase-orders/${deleting.id}`).then(() => {
                setDeleting(null)
                void load()
              }).catch((err) => {
                const response = (err as { response?: { data?: { message?: string } } }).response?.data
                setError(response?.message || t('kams.saveError'))
                setDeleting(null)
              })
            }}>{t('common.delete')}</Button>
          </div>
        </div>
      </Modal>

      <Modal open={journalOpen} title={journalOrder ? t('kams.orderDetails') : t('kams.orderJournal')} onClose={() => { setJournalOpen(false); setJournalOrder(null) }}>
        <div className="space-y-3">
          {journalOrder ? <p className="text-sm text-muted">{journalOrder.reference}</p> : <p className="text-sm text-muted">{t('kams.orderJournalHint')}</p>}
          {journal.length === 0 ? <p className="py-6 text-center text-sm text-muted">{t('common.empty')}</p> : journal.map((entry) => (
            <div key={entry.id} className="rounded-xl border border-line p-3 text-sm">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="font-semibold">{t(`kams.purchaseJournal.${entry.action}`, { defaultValue: entry.action })}</div>
                  <div className="text-xs text-muted">{entry.reference} · {entry.user?.name || '—'}</div>
                </div>
                <div className="text-xs text-muted">{entry.created_at ? new Date(entry.created_at).toLocaleString() : ''}</div>
              </div>
              <ul className="mt-2 space-y-1 text-xs text-muted">
                {entry.changes.map((change, index) => (
                  <li key={index}>
                    {t(`kams.purchaseJournalFields.${change.field}`, { defaultValue: change.field })}
                    {change.field === 'items' || change.field === 'received_now'
                      ? `: ${JSON.stringify(change.after)}`
                      : `: ${change.before ?? '—'} → ${change.after ?? '—'}`}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      </Modal>
    </div>
  )
}

function OrdersTable({
  orders,
  page,
  perPage,
  onPage,
  onPerPage,
  openId,
  onToggle,
  canEdit,
  canDelete,
  canReceive,
  canCancel,
  onDetails,
  onEdit,
  onDelete,
  onReceive,
  onCancel,
}: {
  orders: Order[]
  page: number
  perPage: number
  onPage: (page: number) => void
  onPerPage: (size: number) => void
  openId: number | null
  onToggle: (id: number) => void
  canEdit: boolean
  canDelete: boolean
  canReceive: boolean
  canCancel: boolean
  onDetails: (order: Order) => void
  onEdit: (order: Order) => void
  onDelete: (order: Order) => void
  onReceive: (order: Order) => void
  onCancel: (order: Order) => void
}) {
  const { t } = useTranslation()
  const lastPage = Math.max(1, Math.ceil(orders.length / perPage) || 1)
  const safePage = Math.min(page, lastPage)
  const pageRows = showListPagination(orders.length) ? orders.slice((safePage - 1) * perPage, safePage * perPage) : orders
  const rangeStart = orders.length === 0 ? 0 : (safePage - 1) * perPage + 1
  const rangeEnd = Math.min(safePage * perPage, orders.length)
  const columns = [
    { label: t('kams.reference'), className: 'min-w-40' },
    { label: t('kams.supplier'), className: 'min-w-48' },
    { label: t('kams.warehouse'), className: 'min-w-48' },
    { label: t('kams.orderDate'), className: 'min-w-40' },
    { label: t('common.status'), className: 'min-w-44' },
    { label: t('kams.orderedAmount'), className: 'min-w-44' },
    { label: t('kams.receivedAmount'), className: 'min-w-48' },
    { label: t('common.actions'), className: 'min-w-80 text-right' },
  ]

  return (
    <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(4,55,17,0.04)]">
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-lhms text-white">
            <tr className="text-left text-xs font-semibold tracking-wide">
              {columns.map((column) => (
                <th key={column.label} className={cn('bg-lhms px-4 py-1.5 font-semibold', column.className)}>{column.label}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {pageRows.map((order, index) => {
              const received = receivedAmount(order)
              const showReceive = canReceive && (order.status === 'ordered' || order.status === 'partial')
              const showCancel = canCancel && (order.status === 'ordered' || order.status === 'partial') && (order.items || []).every((item) => item.received_quantity === 0)
              const isOpen = openId === order.id
              return (
                <Fragment key={order.id}>
                  <tr className={cn('border-t border-line', index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white')}>
                    <td className="min-w-40 px-4 py-1.5 font-semibold">{order.reference}</td>
                    <td className="min-w-48 px-4 py-1.5">{order.supplier_name || '—'}</td>
                    <td className="min-w-48 px-4 py-1.5">{order.warehouse?.name || '—'}</td>
                    <td className="min-w-40 px-4 py-1.5 whitespace-nowrap">{String(order.ordered_at).slice(0, 10)}</td>
                    <td className="min-w-44 px-4 py-1.5">{t(`kams.purchaseStatus.${order.status}`)}</td>
                    <td className="min-w-44 px-4 py-1.5 whitespace-nowrap tabular-nums">{money(order.total)}</td>
                    <td className="min-w-48 px-4 py-1.5 whitespace-nowrap tabular-nums">{money(received)}</td>
                    <td className="min-w-80 px-4 py-1.5">
                      <div className="flex flex-nowrap items-center justify-end gap-1">
                        <button type="button" title={t('kams.orderDetails')} aria-label={t('kams.orderDetails')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-surface" onClick={() => onDetails(order)}>
                          <Info size={16} />
                        </button>
                        {canEdit && order.status !== 'cancelled' ? (
                          <button type="button" title={t('common.edit')} aria-label={t('common.edit')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-surface" onClick={() => onEdit(order)}>
                            <Pencil size={16} />
                          </button>
                        ) : null}
                        {canDelete && (order.items || []).every((item) => item.received_quantity === 0) ? (
                          <button type="button" title={t('common.delete')} aria-label={t('common.delete')} className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-surface" onClick={() => onDelete(order)}>
                            <Trash2 size={16} />
                          </button>
                        ) : null}
                        {showReceive ? <Button variant="secondary" onClick={() => onReceive(order)}>{t('kams.receiveOrder')}</Button> : null}
                        {showCancel ? <Button variant="secondary" onClick={() => onCancel(order)}>{t('common.cancel')}</Button> : null}
                        <button type="button" className="grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface" onClick={() => onToggle(order.id)}>
                          <ChevronDown size={18} className={cn('transition', isOpen ? 'rotate-0' : '-rotate-90')} />
                        </button>
                      </div>
                    </td>
                  </tr>
                  {isOpen ? (
                    <tr className="border-t border-line bg-surface">
                      <td colSpan={8} className="px-4 py-3">
                        <table className="w-full text-sm">
                          <thead>
                            <tr className="text-left text-xs text-muted">
                              <th className="py-1 font-medium">{t('kams.products')}</th>
                              <th className="py-1 text-right font-medium">{t('kams.quantity')}</th>
                              <th className="py-1 text-right font-medium">{t('kams.receivedQty')}</th>
                              <th className="py-1 text-right font-medium">{t('kams.purchasePrice')}</th>
                              <th className="py-1 text-right font-medium">{t('kams.lineTotal')}</th>
                            </tr>
                          </thead>
                          <tbody>
                            {(order.items || []).map((item) => (
                              <tr key={item.id}>
                                <td className="py-1">{item.product?.name}</td>
                                <td className="py-1 text-right tabular-nums">{item.quantity}</td>
                                <td className="py-1 text-right tabular-nums">{item.received_quantity}</td>
                                <td className="py-1 text-right tabular-nums">{money(item.unit_cost)}</td>
                                <td className="py-1 text-right tabular-nums">{money(item.line_total)}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                        {order.notes ? <p className="mt-2 text-xs text-muted">{order.notes}</p> : null}
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>
      {showListPagination(orders.length) ? (
        <div className="flex flex-col gap-3 border-t border-line px-4 py-1.5 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">{t('kams.pageRange', { from: rangeStart, to: rangeEnd, total: orders.length })}</p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2 text-sm text-muted">
              {t('kams.rowsPerPage')}
              <select value={perPage} onChange={(event) => onPerPage(Number(event.target.value))} className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink">
                {[10, 20, 50].map((size) => <option key={size} value={size}>{size}</option>)}
              </select>
            </label>
            <PaginationButtons
              page={safePage}
              lastPage={lastPage}
              onPrev={() => onPage(Math.max(1, safePage - 1))}
              onNext={() => onPage(Math.min(lastPage, safePage + 1))}
            />
          </div>
        </div>
      ) : null}
    </div>
  )
}
