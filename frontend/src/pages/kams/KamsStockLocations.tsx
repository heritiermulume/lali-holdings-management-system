import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money } from '../../lib/format'

type Office = { id: number; company_id: number; name: string; code: string; city?: { name?: string } | null }
type Province = { id: number; name: string; name_fr?: string }
type Product = { id: number; name: string; sku?: string | null; price: number; cost: number; field_price?: number; manager_price?: number; company_price?: number; stock: number }
type Balance = { id: number; quantity: number; condition?: string; product?: Product | null }
type Location = {
  id: number
  company_id?: number
  name: string
  code?: string | null
  kind: string
  status: string
  notes?: string | null
  office_id?: number | null
  province_id?: number | null
  office?: { id: number; name: string; code?: string; city?: { name?: string } | null } | null
  province?: Province | null
  balances?: Balance[]
}

type Lookups = {
  offices: Office[]
  provinces: Province[]
  warehouses: Location[]
  depots: Location[]
  products: Product[]
  can_choose_office: boolean
  can_manage_warehouses: boolean
}

function locationUnits(row: Location) {
  return (row.balances || []).filter((item) => (item.condition || 'available') === 'available').reduce((sum, item) => sum + (item.quantity || 0), 0)
}

export function KamsWarehouses() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Location[]>([])
  const [lookups, setLookups] = useState<Lookups | null>(null)
  const [open, setOpen] = useState(false)
  const [receiveOpen, setReceiveOpen] = useState(false)
  const [target, setTarget] = useState<Location | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/depots', { params: { kind: 'warehouse', per_page: 50 } }).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/depots', {
        kind: 'warehouse',
        name: form.name,
        code: form.code || null,
        province_id: form.province_id ? Number(form.province_id) : null,
        notes: form.notes || null,
        status: 'active',
      })
      setOpen(false)
      setForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const receive = async (event: FormEvent) => {
    event.preventDefault()
    if (!target) return
    setError('')
    try {
      await api.post('/modules/kams/stock/receipts', {
        warehouse_id: target.id,
        product_id: Number(form.product_id),
        quantity: Number(form.quantity),
        unit_cost: form.unit_cost ? Number(form.unit_cost) : undefined,
        supplier_name: form.supplier_name || null,
        notes: form.notes || null,
        validate: true,
      })
      setReceiveOpen(false)
      setForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex justify-end">
        {lookups?.can_manage_warehouses && hasPermission('kams.products.create') ? (
          <Button onClick={() => { setForm({}); setError(''); setOpen(true) }}>{t('kams.addWarehouse')}</Button>
        ) : null}
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.name}</div>
                <div className="text-sm text-muted">{row.code || '—'} · {i18n.language.startsWith('fr') ? row.province?.name_fr || row.province?.name : row.province?.name || t('kams.noRegion')}</div>
              </div>
              <Badge>{locationUnits(row)}</Badge>
            </div>
            <div className="mt-3 space-y-2">
              {(row.balances || []).filter((item) => item.quantity > 0).map((item) => (
                <div key={item.id} className="flex justify-between text-sm">
                  <span>{item.product?.name}{item.condition && item.condition !== 'available' ? ` (${item.condition})` : ''}</span>
                  <span className="font-semibold">{item.quantity}</span>
                </div>
              ))}
            </div>
            {lookups?.can_manage_warehouses && hasPermission('kams.products.update') ? (
              <Button className="mt-4" variant="secondary" onClick={() => { setTarget(row); setForm({}); setError(''); setReceiveOpen(true) }}>{t('kams.receiveStock')}</Button>
            ) : null}
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal open={open} title={t('kams.newWarehouse')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <Field label={t('kams.depotCode')} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <Select label={t('kams.region')} value={form.province_id || ''} onChange={(e) => setForm({ ...form, province_id: e.target.value })}>
            <option value="">—</option>
            {(lookups?.provinces || []).map((province) => (
              <option key={province.id} value={province.id}>{i18n.language.startsWith('fr') ? province.name_fr || province.name : province.name}</option>
            ))}
          </Select>
          <Field label={t('kams.notes')} value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={receiveOpen} title={t('kams.receiveStock')} onClose={() => setReceiveOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void receive(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">{target?.name}</p>
          <Field label={t('kams.supplier')} value={form.supplier_name || ''} onChange={(e) => setForm({ ...form, supplier_name: e.target.value })} />
          <Select label={t('kams.products')} value={form.product_id || ''} onChange={(e) => setForm({ ...form, product_id: e.target.value })} required>
            <option value="">—</option>
            {(lookups?.products || []).map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}
          </Select>
          <Field label={t('kams.quantity')} type="number" min="1" value={form.quantity || ''} onChange={(e) => setForm({ ...form, quantity: e.target.value })} required />
          <Field label={t('kams.cost')} type="number" min="0" step="0.01" value={form.unit_cost || ''} onChange={(e) => setForm({ ...form, unit_cost: e.target.value })} />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}

export function KamsDepots() {
  const { t } = useTranslation()
  const { hasPermission, user } = useAuth()
  const [rows, setRows] = useState<Location[]>([])
  const [lookups, setLookups] = useState<Lookups | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({})
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/depots', { params: { kind: 'depot', per_page: 50 } }).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
  }, [])

  const assignedOfficeId = () => {
    if (user?.primary_office?.id && (lookups?.offices || []).some((office) => office.id === user.primary_office?.id)) {
      return String(user.primary_office.id)
    }
    return lookups?.offices[0] ? String(lookups.offices[0].id) : ''
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/depots', {
        kind: 'depot',
        name: form.name,
        code: form.code || null,
        office_id: Number(lookups?.can_choose_office ? form.office_id : assignedOfficeId()),
        notes: form.notes || null,
        status: 'active',
      })
      setOpen(false)
      setForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex justify-end">
        {hasPermission('kams.products.create') ? (
          <Button onClick={() => { setForm({ office_id: assignedOfficeId() }); setError(''); setOpen(true) }}>{t('kams.addDepot')}</Button>
        ) : null}
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.name}</div>
                <div className="text-sm text-muted">{row.office?.name || '—'} · {row.code || '—'}</div>
              </div>
              <Badge tone={row.status === 'active' ? 'green' : 'slate'}>{locationUnits(row)}</Badge>
            </div>
            <div className="mt-3 space-y-2">
              {(row.balances || []).filter((item) => item.quantity > 0).map((item) => (
                <div key={item.id} className="flex justify-between text-sm">
                  <span>{item.product?.name}{item.condition && item.condition !== 'available' ? ` (${item.condition})` : ''}</span>
                  <span className="font-semibold">{item.quantity}</span>
                </div>
              ))}
            </div>
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <Modal open={open} title={t('kams.newDepot')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {lookups?.can_choose_office ? (
            <Select label={t('kams.office')} value={form.office_id || ''} onChange={(e) => setForm({ ...form, office_id: e.target.value })}>
              <option value="">—</option>
              {(lookups.offices || []).map((office) => <option key={office.id} value={office.id}>{office.name}</option>)}
            </Select>
          ) : null}
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <Field label={t('kams.depotCode')} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}

export function KamsStockProducts() {
  const { t } = useTranslation()
  const { hasPermission, user } = useAuth()
  const [rows, setRows] = useState<Array<Product & { balances?: Array<{ quantity: number; depot?: { name: string; kind: string } }>}>>([])
  const [lookups, setLookups] = useState<Lookups | null>(null)
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({})
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/products', { params: { per_page: 50 } }).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/modules/kams/stock/lookups').then((res) => setLookups(res.data.data))
  }, [])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    const companyId = lookups?.warehouses[0]?.company_id || user?.primary_company?.id
    try {
      await api.post('/modules/kams/products', {
        company_id: companyId,
        name: form.name,
        sku: form.sku || null,
        price: Number(form.field_price || form.price),
        field_price: Number(form.field_price || form.price),
        manager_price: form.manager_price ? Number(form.manager_price) : undefined,
        company_price: form.company_price ? Number(form.company_price) : (form.cost ? Number(form.cost) : undefined),
        cost: form.company_price ? Number(form.company_price) : (form.cost ? Number(form.cost) : undefined),
        stock: form.stock ? Number(form.stock) : 0,
        warehouse_id: form.warehouse_id ? Number(form.warehouse_id) : undefined,
      })
      setOpen(false)
      setForm({})
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div>
      <div className="mb-4 flex justify-end">
        {hasPermission('kams.products.create') ? <Button onClick={() => { setForm({ warehouse_id: lookups?.warehouses[0] ? String(lookups.warehouses[0].id) : '' }); setError(''); setOpen(true) }}>{t('kams.newProduct')}</Button> : null}
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="font-semibold">{row.name}</div>
            <div className="text-sm text-muted">
              {t('kams.fieldPrice')} {money(row.field_price || row.price)} · {t('kams.managerPrice')} {money(row.manager_price || 0)} · {t('kams.companyPrice')} {money(row.company_price || row.cost)}
            </div>
            <div className="mt-2 font-bold text-lhms">{row.stock} {t('kams.unitsInStock').toLowerCase()}</div>
            <div className="mt-3 space-y-1 text-sm text-muted">
              {(row.balances || []).filter((item) => item.quantity > 0).map((item, index) => (
                <div key={index}>{item.depot?.name}: {item.quantity}</div>
              ))}
            </div>
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}
      <Modal open={open} title={t('kams.newProduct')} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} required />
          <Field label="SKU" value={form.sku || ''} onChange={(e) => setForm({ ...form, sku: e.target.value })} />
          <Field label={t('kams.fieldPrice')} type="number" min="0" step="0.01" value={form.field_price || form.price || ''} onChange={(e) => setForm({ ...form, field_price: e.target.value, price: e.target.value })} required />
          <Field label={t('kams.managerPrice')} type="number" min="0" step="0.01" value={form.manager_price || ''} onChange={(e) => setForm({ ...form, manager_price: e.target.value })} />
          <Field label={t('kams.companyPrice')} type="number" min="0" step="0.01" value={form.company_price || form.cost || ''} onChange={(e) => setForm({ ...form, company_price: e.target.value, cost: e.target.value })} />
          <Select label={t('kams.warehouses')} value={form.warehouse_id || ''} onChange={(e) => setForm({ ...form, warehouse_id: e.target.value })}>
            <option value="">—</option>
            {(lookups?.warehouses || []).map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}
          </Select>
          <Field label={t('kams.initialStock')} type="number" min="0" value={form.stock || ''} onChange={(e) => setForm({ ...form, stock: e.target.value })} />
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}
