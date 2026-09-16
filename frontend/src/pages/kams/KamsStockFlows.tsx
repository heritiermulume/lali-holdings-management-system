import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money } from '../../lib/format'

type Line = { product_id: string; quantity: string }
type Product = { id: number; name: string; sku?: string | null; price: number; cost: number; stock: number }
type Agent = { id: number; full_name: string; member_code: string; office_id?: number | null }
type Location = { id: number; name: string; kind: string; office_id?: number | null }
type Transfer = {
  id: number
  reference: string
  status?: string
  transferred_at?: string | null
  from_depot?: Location | null
  to_depot?: Location & { office?: { name: string } | null } | null
  items?: Array<{ id: number; quantity: number; product?: Product | null }>
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
  member?: { full_name: string; member_code: string } | null
  office?: { name: string } | null
  items?: AssignmentItem[]
}

function emptyLine(): Line {
  return { product_id: '', quantity: '1' }
}

export function KamsTransfers() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Transfer[]>([])
  const [warehouses, setWarehouses] = useState<Location[]>([])
  const [depots, setDepots] = useState<Location[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [open, setOpen] = useState(false)
  const [fromId, setFromId] = useState('')
  const [toId, setToId] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/stock/transfers').then((res) => setRows(res.data.data))

  const receiveTransfer = async (id: number) => {
    setError('')
    try {
      await api.post(`/modules/kams/stock/transfers/${id}/receive`)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => {
      setWarehouses(res.data.data.warehouses || [])
      setDepots(res.data.data.depots || [])
      setProducts(res.data.data.products || [])
    })
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/stock/transfers', {
        from_depot_id: Number(fromId),
        to_depot_id: Number(toId),
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

  return (
    <div>
      <div className="mb-4 flex justify-end">
        {hasPermission('kams.products.update') ? <Button onClick={() => { setError(''); setOpen(true) }}>{t('kams.newTransfer')}</Button> : null}
      </div>
      {error ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.reference}</div>
                <div className="text-sm text-muted">{row.from_depot?.name} → {row.to_depot?.name} ({row.to_depot?.office?.name})</div>
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
          <Select label={t('kams.fromWarehouse')} value={fromId} onChange={(e) => setFromId(e.target.value)} required>
            <option value="">—</option>
            {warehouses.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <Select label={t('kams.toDepot')} value={toId} onChange={(e) => setToId(e.target.value)} required>
            <option value="">—</option>
            {depots.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
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
    </div>
  )
}

export function KamsAssignments() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Assignment[]>([])
  const [agents, setAgents] = useState<Agent[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [open, setOpen] = useState(false)
  const [settling, setSettling] = useState<Assignment | null>(null)
  const [memberId, setMemberId] = useState('')
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [collected, setCollected] = useState('')
  const [returns, setReturns] = useState<Record<number, { returned: string; lost: string }>>({})
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/stock/assignments').then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => {
      setAgents(res.data.data.agents || [])
      setProducts(res.data.data.products || [])
    })
  }, [])

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

  const settle = async (event: FormEvent) => {
    event.preventDefault()
    if (!settling) return
    setError('')
    try {
      await api.post(`/modules/kams/stock/assignments/${settling.id}/settle`, {
        collected_amount: Number(collected),
        items: (settling.items || []).map((item) => ({
          id: item.id,
          quantity_returned: Number(returns[item.id]?.returned || 0),
          quantity_lost: Number(returns[item.id]?.lost || 0),
        })),
      })
      setSettling(null)
      setCollected('')
      setReturns({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <p className="text-sm text-muted">{t('kams.assignmentFlowHint')}</p>
        {hasPermission('kams.products.update') ? <Button onClick={() => { setError(''); setOpen(true) }}>{t('kams.newAssignment')}</Button> : null}
      </div>
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.reference} · {row.member?.full_name}</div>
                <div className="text-sm text-muted">{row.office?.name} · {t('kams.assignmentNotSale')}</div>
              </div>
              <Badge tone={row.status === 'open' ? 'amber' : 'green'}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
            </div>
            <div className="mt-3 space-y-1 text-sm">
              {(row.items || []).map((item) => (
                <div key={item.id} className="flex justify-between">
                  <span>{item.product?.name}</span>
                  <span>{item.quantity_out} {row.status === 'settled' ? `→ ${item.quantity_out - item.quantity_returned - item.quantity_lost} ${t('kams.sold').toLowerCase()}` : ''}</span>
                </div>
              ))}
            </div>
            {row.status === 'settled' ? (
              <div className="mt-3 grid gap-2 sm:grid-cols-3 text-sm">
                <div>{t('kams.cashCollected')}: <b>{money(row.collected_amount)}</b></div>
                <div>{t('kams.profit')}: <b>{money(row.profit_amount)}</b></div>
                <div>{t('kams.loss')}: <b>{money(row.loss_amount)}</b></div>
              </div>
            ) : hasPermission('kams.products.update') ? (
              <Button className="mt-4" variant="secondary" onClick={() => { setSettling(row); setError(''); setCollected(''); setReturns({}) }}>{t('kams.settleAssignment')}</Button>
            ) : null}
          </Card>
        ))}
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

      <Modal open={Boolean(settling)} title={t('kams.settleAssignment')} onClose={() => setSettling(null)}>
        <form className="space-y-3" onSubmit={(e) => void settle(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">{t('kams.closeDayHint')}</p>
          <p className="text-sm text-muted">{settling?.member?.full_name} · {settling?.reference}</p>
          {(settling?.items || []).map((item) => {
            const returnedQty = Number(returns[item.id]?.returned || 0)
            const lostQty = Number(returns[item.id]?.lost || 0)
            const soldQty = Math.max(0, item.quantity_out - returnedQty - lostQty)
            return (
            <div key={item.id} className="rounded-xl bg-surface p-3 space-y-2">
              <div className="font-semibold">{item.product?.name} · {item.quantity_out} {t('kams.assignedQty')}</div>
              <div className="grid gap-2 sm:grid-cols-2">
                <Field label={t('kams.returned')} type="number" min="0" max={item.quantity_out} value={returns[item.id]?.returned || '0'} onChange={(e) => setReturns({ ...returns, [item.id]: { returned: e.target.value, lost: returns[item.id]?.lost || '0' } })} />
                <Field label={t('kams.lost')} type="number" min="0" max={item.quantity_out} value={returns[item.id]?.lost || '0'} onChange={(e) => setReturns({ ...returns, [item.id]: { returned: returns[item.id]?.returned || '0', lost: e.target.value } })} />
              </div>
              <div className="text-sm text-muted">{t('kams.sold')}: <b>{soldQty}</b></div>
            </div>
            )
          })}
          <Field label={t('kams.cashCollected')} type="number" min="0" step="0.01" value={collected} onChange={(e) => setCollected(e.target.value)} required />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}
