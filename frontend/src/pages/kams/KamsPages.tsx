import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { money } from '../../lib/format'
import api from '../../lib/api'

type Company = { id: number; name: string }

export function KamsDashboard() {
  const { t } = useTranslation()
  const [data, setData] = useState<{
    stats: Record<string, number>
    finance: Record<string, number>
    recent_orders: Array<{ id: number; reference: string; total: number; status: string; client?: { name: string } }>
  } | null>(null)

  useEffect(() => {
    api.get('/modules/kams/dashboard').then((res) => setData(res.data.data))
  }, [])

  if (!data) return <p className="text-sm text-muted">{t('common.loading')}</p>

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {Object.entries(data.stats).map(([key, value]) => (
          <Card key={key} className="p-5">
            <div className="text-sm text-muted">{t(`kams.${key}`)}</div>
            <div className="mt-3 text-3xl font-extrabold">{value}</div>
          </Card>
        ))}
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {['revenue', 'expenses', 'profit', 'receivables'].map((key) => (
          <Card key={key} className="p-5">
            <div className="text-sm text-muted">{t(`dashboard.${key}`)}</div>
            <div className="mt-3 text-2xl font-bold text-lhms">{money(data.finance[key])}</div>
          </Card>
        ))}
      </div>
      <Card className="p-5">
        <div className="mb-3 font-semibold">{t('kams.orders')}</div>
        <div className="space-y-3">
          {data.recent_orders.map((order) => (
            <div key={order.id} className="flex items-center justify-between rounded-xl bg-surface p-3">
              <div>
                <div className="font-semibold">{order.reference}</div>
                <div className="text-sm text-muted">{order.client?.name}</div>
              </div>
              <div className="text-right">
                <div className="font-semibold">{money(order.total)}</div>
                <Badge>{order.status}</Badge>
              </div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}

function ResourcePage({
  endpoint,
  createLabel,
  fields,
  render,
}: {
  endpoint: string
  title?: string
  createLabel: string
  fields: Array<{ name: string; label: string; type?: string }>
  render: (row: Record<string, unknown>) => ReactNode
}) {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([])
  const [companies, setCompanies] = useState<Company[]>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({})

  const load = () => api.get(endpoint).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/companies').then((res) => setCompanies(res.data.data))
  }, [endpoint])

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const payload: Record<string, unknown> = { ...form }
    if (form.company_id) payload.company_id = Number(form.company_id)
    if (form.price) payload.price = Number(form.price)
    if (form.stock) payload.stock = Number(form.stock)
    if (form.budget) payload.budget = Number(form.budget)
    try {
      await api.post(endpoint, payload)
      setOpen(false)
      setForm({})
      load()
    } catch {
      window.alert('Unable to save. Check required fields.')
    }
  }

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button onClick={() => setOpen(true)}>{createLabel}</Button>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((row) => (
          <Card key={String(row.id)} className="p-5">{render(row)}</Card>
        ))}
      </div>
      <Modal open={open} title={createLabel} onClose={() => setOpen(false)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          <Select label={t('users.company')} value={form.company_id || ''} onChange={(e) => setForm({ ...form, company_id: e.target.value })}>
            <option value="">—</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </Select>
          {fields.map((field) => (
            <Field key={field.name} label={field.label} type={field.type || 'text'} value={form[field.name] || ''} onChange={(e) => setForm({ ...form, [field.name]: e.target.value })} />
          ))}
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}

export function KamsClients() {
  const { t } = useTranslation()
  return (
    <ResourcePage
      endpoint="/modules/kams/clients"
      title={t('kams.clients')}
      createLabel={t('kams.newClient')}
      fields={[
        { name: 'name', label: t('org.name') },
        { name: 'email', label: 'Email', type: 'email' },
        { name: 'phone', label: 'Phone' },
        { name: 'city', label: t('org.cities') },
        { name: 'segment', label: 'Segment' },
      ]}
      render={(row) => (
        <>
          <div className="font-semibold">{String(row.name)}</div>
          <div className="text-sm text-muted">{String(row.email || '')} · {String(row.city || '')}</div>
          <div className="mt-2"><Badge>{String(row.status)}</Badge></div>
        </>
      )}
    />
  )
}

export function KamsProducts() {
  const { t } = useTranslation()
  return (
    <ResourcePage
      endpoint="/modules/kams/products"
      title={t('kams.products')}
      createLabel={t('kams.newProduct')}
      fields={[
        { name: 'name', label: t('org.name') },
        { name: 'sku', label: 'SKU' },
        { name: 'category', label: 'Category' },
        { name: 'collection', label: 'Collection' },
        { name: 'price', label: 'Price', type: 'number' },
        { name: 'stock', label: 'Stock', type: 'number' },
      ]}
      render={(row) => (
        <>
          <div className="font-semibold">{String(row.name)}</div>
          <div className="text-sm text-muted">{String(row.sku || '')} · {String(row.collection || '')}</div>
          <div className="mt-2 font-bold text-lhms">{money(Number(row.price))}</div>
        </>
      )}
    />
  )
}

export function KamsOrders() {
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([])

  useEffect(() => {
    api.get('/modules/kams/orders').then((res) => setRows(res.data.data))
  }, [])

  return (
    <div className="grid gap-3">
      {rows.map((row) => (
        <Card key={String(row.id)} className="p-5">
          <div className="flex items-center justify-between">
            <div>
              <div className="font-semibold">{String(row.reference)}</div>
              <div className="text-sm text-muted">{String((row.client as { name?: string } | undefined)?.name || '')}</div>
            </div>
            <div className="text-right">
              <div className="font-bold">{money(Number(row.total))}</div>
              <Badge>{String(row.status)}</Badge>
            </div>
          </div>
        </Card>
      ))}
    </div>
  )
}

export function KamsCampaigns() {
  const { t } = useTranslation()
  return (
    <ResourcePage
      endpoint="/modules/kams/campaigns"
      title={t('kams.campaigns')}
      createLabel={t('kams.newCampaign')}
      fields={[
        { name: 'name', label: t('org.name') },
        { name: 'channel', label: 'Channel' },
        { name: 'budget', label: 'Budget', type: 'number' },
        { name: 'starts_on', label: 'Start', type: 'date' },
        { name: 'ends_on', label: 'End', type: 'date' },
      ]}
      render={(row) => (
        <>
          <div className="font-semibold">{String(row.name)}</div>
          <div className="text-sm text-muted">{String(row.channel)} · {money(Number(row.budget))}</div>
          <div className="mt-2"><Badge>{String(row.status)}</Badge></div>
        </>
      )}
    />
  )
}
