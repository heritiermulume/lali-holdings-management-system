import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money } from '../../lib/format'

type Line = { product_id: string; quantity: string; unit_cost?: string; unit_price?: string }
type Product = { id: number; name: string; sku?: string | null; price: number; cost: number; avg_cost?: number; stock: number }
type Agent = { id: number; full_name: string; member_code: string; office_id?: number | null }
type Location = { id: number; name: string; kind: string }

function emptyLine(): Line {
  return { product_id: '', quantity: '1', unit_cost: '', unit_price: '' }
}

export function KamsReceipts() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Array<{
    id: number
    reference: string
    status: string
    supplier_name?: string | null
    total_cost: number
    warehouse?: { name: string } | null
    items?: Array<{ id: number; quantity: number; unit_cost: number; product?: Product | null }>
  }>>([])
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/stock/receipts').then((res) => setRows(res.data.data))

  useEffect(() => { void load() }, [])

  const act = async (id: number, action: 'validate' | 'cancel') => {
    setError('')
    try {
      await api.post(`/modules/kams/stock/receipts/${id}/${action}`)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      {error ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.reference} · {row.warehouse?.name}</div>
                <div className="text-sm text-muted">{row.supplier_name || '—'} · {money(row.total_cost)}</div>
              </div>
              <Badge tone={row.status === 'received' ? 'green' : row.status === 'cancelled' ? 'slate' : 'amber'}>
                {t(`kams.status.${row.status}`, { defaultValue: row.status })}
              </Badge>
            </div>
            <div className="mt-3 space-y-1 text-sm">
              {(row.items || []).map((item) => (
                <div key={item.id} className="flex justify-between">
                  <span>{item.product?.name}</span>
                  <span>{item.quantity} × {money(item.unit_cost)}</span>
                </div>
              ))}
            </div>
            {row.status === 'pending' && hasPermission('kams.stock.update') ? (
              <div className="mt-4 flex gap-2">
                <Button onClick={() => void act(row.id, 'validate')}>{t('kams.validateReceipt')}</Button>
                <Button variant="secondary" onClick={() => void act(row.id, 'cancel')}>{t('kams.cancelReceipt')}</Button>
              </div>
            ) : null}
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}
    </div>
  )
}

export function KamsReturns() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Array<{
    id: number
    reference: string
    type: string
    status: string
    condition: string
    reason?: string | null
    member?: { full_name: string } | null
    items?: Array<{ id: number; quantity: number; product?: Product | null }>
  }>>([])
  const [agents, setAgents] = useState<Agent[]>([])
  const [products, setProducts] = useState<Product[]>([])
  const [depots, setDepots] = useState<Location[]>([])
  const [warehouses, setWarehouses] = useState<Location[]>([])
  const [sales, setSales] = useState<Array<{ id: number; reference: string }>>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({ type: 'customer', condition: 'quarantine' })
  const [lines, setLines] = useState<Line[]>([emptyLine()])
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/stock/returns').then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => {
      setAgents(res.data.data.agents || [])
      setProducts(res.data.data.products || [])
      setDepots(res.data.data.depots || [])
      setWarehouses(res.data.data.warehouses || [])
    })
    api.get('/modules/kams/stock/sales').then((res) => setSales((res.data.data || []).map((row: { id: number; reference: string }) => ({ id: row.id, reference: row.reference }))))
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/stock/returns', {
        type: form.type,
        member_id: form.member_id ? Number(form.member_id) : null,
        sale_id: form.sale_id ? Number(form.sale_id) : null,
        from_depot_id: form.from_depot_id ? Number(form.from_depot_id) : null,
        to_depot_id: form.to_depot_id ? Number(form.to_depot_id) : null,
        condition: form.condition,
        reason: form.reason || null,
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

  const accept = async (id: number) => {
    setError('')
    try {
      await api.post(`/modules/kams/stock/returns/${id}/accept`)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex justify-end">
        {hasPermission('kams.stock.update') ? <Button onClick={() => { setError(''); setOpen(true) }}>{t('kams.newReturn')}</Button> : null}
      </div>
      {error ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.reference} · {t(`kams.returnType.${row.type}`, { defaultValue: row.type })}</div>
                <div className="text-sm text-muted">{row.member?.full_name || '—'} · {t(`kams.condition.${row.condition}`, { defaultValue: row.condition })}</div>
                {row.reason ? <div className="mt-1 text-sm">{row.reason}</div> : null}
              </div>
              <Badge>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
            </div>
            <div className="mt-3 space-y-1 text-sm">
              {(row.items || []).map((item) => (
                <div key={item.id} className="flex justify-between">
                  <span>{item.product?.name}</span>
                  <span>{item.quantity}</span>
                </div>
              ))}
            </div>
            {row.status === 'requested' && hasPermission('kams.stock.update') ? (
              <Button className="mt-4" onClick={() => void accept(row.id)}>{t('kams.acceptReturn')}</Button>
            ) : null}
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal open={open} title={t('kams.newReturn')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Select label={t('kams.returnKind')} value={form.type || 'customer'} onChange={(e) => setForm({ ...form, type: e.target.value })}>
            {['customer', 'seller_to_depot', 'depot_to_warehouse', 'defective'].map((type) => (
              <option key={type} value={type}>{t(`kams.returnType.${type}`)}</option>
            ))}
          </Select>
          <Select label={t('kams.agents')} value={form.member_id || ''} onChange={(e) => setForm({ ...form, member_id: e.target.value })}>
            <option value="">—</option>
            {agents.map((agent) => <option key={agent.id} value={agent.id}>{agent.full_name}</option>)}
          </Select>
          <Select label={t('kams.sales')} value={form.sale_id || ''} onChange={(e) => setForm({ ...form, sale_id: e.target.value })}>
            <option value="">—</option>
            {sales.map((sale) => <option key={sale.id} value={sale.id}>{sale.reference}</option>)}
          </Select>
          <Select label={t('kams.fromWarehouse')} value={form.from_depot_id || ''} onChange={(e) => setForm({ ...form, from_depot_id: e.target.value })}>
            <option value="">—</option>
            {[...depots, ...warehouses].map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <Select label={t('kams.toDepot')} value={form.to_depot_id || ''} onChange={(e) => setForm({ ...form, to_depot_id: e.target.value })}>
            <option value="">—</option>
            {[...depots, ...warehouses].map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <Select label={t('kams.stockCondition')} value={form.condition || 'quarantine'} onChange={(e) => setForm({ ...form, condition: e.target.value })}>
            {['available', 'quarantine', 'damaged'].map((item) => <option key={item} value={item}>{t(`kams.condition.${item}`)}</option>)}
          </Select>
          <Field label={t('kams.reason')} value={form.reason || ''} onChange={(e) => setForm({ ...form, reason: e.target.value })} />
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

export function KamsCommissions() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Array<{
    id: number
    status: string
    estimated_amount: number
    adjusted_amount: number
    payable_amount: number
    paid_amount: number
    member?: { full_name: string } | null
    sale?: { reference: string } | null
  }>>([])
  const [paying, setPaying] = useState<number | null>(null)
  const [amount, setAmount] = useState('')
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/stock/commissions').then((res) => setRows(res.data.data))
  useEffect(() => { void load() }, [])

  const pay = async (event: FormEvent) => {
    event.preventDefault()
    if (!paying) return
    setError('')
    try {
      await api.post(`/modules/kams/stock/commissions/${paying}/pay`, { amount: Number(amount) })
      setPaying(null)
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      {error ? <p className="mb-3 text-sm text-red-600">{error}</p> : null}
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.member?.full_name} · {row.sale?.reference}</div>
                <div className="text-sm text-muted">{t('kams.estimated')}: {money(row.estimated_amount)} · {t('kams.adjusted')}: {money(row.adjusted_amount)}</div>
              </div>
              <Badge tone={row.status === 'paid' ? 'green' : row.status === 'payable' ? 'amber' : 'slate'}>
                {t(`kams.status.${row.status}`, { defaultValue: row.status })}
              </Badge>
            </div>
            <div className="mt-3 text-sm">{t('kams.payable')}: <b>{money(row.payable_amount)}</b> · {t('kams.paid')}: <b>{money(row.paid_amount)}</b></div>
            {row.status === 'payable' && hasPermission('kams.stock.update') ? (
              <Button className="mt-4" onClick={() => { setPaying(row.id); setAmount(String(row.payable_amount - row.paid_amount)); setError('') }}>{t('kams.payCommission')}</Button>
            ) : null}
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}
      <Modal open={Boolean(paying)} title={t('kams.payCommission')} onClose={() => setPaying(null)}>
        <form className="space-y-3" onSubmit={(e) => void pay(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Field label={t('kams.payable')} type="number" min="0.01" step="0.01" value={amount} onChange={(e) => setAmount(e.target.value)} required />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}
