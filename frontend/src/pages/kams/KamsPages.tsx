import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate } from 'react-router-dom'
import { Area, AreaChart, Bar, BarChart, CartesianGrid, Cell, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { OfficeFilterSelect } from '../../components/OfficeFilterSelect'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { cn } from '../../lib/cn'
import { showAlert } from '../../lib/alert'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import { usePayloadOfficeFilter } from '../../hooks/useOfficeScope'
import { compact, money } from '../../lib/format'
import { moduleRegistry } from '../../modules/registry'
import api from '../../lib/api'

type Company = { id: number; name: string }

export function KamsEntry() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()

  if (hasPermission('kams.dashboard.view')) {
    return <KamsDashboard />
  }

  const next = moduleRegistry.kams.items.find((item) => {
    if (item.to === '/modules/kams') return false
    if (item.anyPerm?.length) return item.anyPerm.some((perm) => hasPermission(perm))
    return Boolean(item.perm && hasPermission(item.perm))
  })

  if (next) return <Navigate to={next.to} replace />
  return <Empty>{t('common.noAccess')}</Empty>
}

type DashboardPeriod = 'day' | 'week' | 'month' | 'custom'

const OFFICE_REVENUE_COLORS = ['#043711', '#1a6b3c', '#2d8a5a', '#5b8a72', '#c9a227', '#1d4ed8', '#0f766e', '#854d0e']

function isoDate(value: Date) {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

export function KamsDashboard() {
  const { t, i18n } = useTranslation()
  const { user, hasPermission } = useAuth()
  const knowsOptions = (user?.permissions ?? []).some((slug) => slug.startsWith('kams.dashboard.') && slug !== 'kams.dashboard.view')
  const allow = (slug: string) => hasPermission(slug) || (hasPermission('kams.dashboard.view') && !knowsOptions)
  const canProducts = allow('kams.dashboard.products.view')
  const canOrders = allow('kams.dashboard.orders.view')
  const canFinance = allow('kams.dashboard.finance.view')
  const canRecruitment = allow('kams.dashboard.recruitment.view')
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const today = isoDate(new Date())
  const [period, setPeriod] = useState<DashboardPeriod>('week')
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const { officeId, setOfficeId, syncFromPayload } = usePayloadOfficeFilter()
  const [data, setData] = useState<{
    range: { from: string; to: string }
    can_filter_offices?: boolean
    office_id?: number | null
    filter_offices?: Array<{ id: number; name: string; code?: string }>
    stats: Record<string, number>
    finance: Record<string, number> | null
    trend?: Array<{ from: string; to: string; revenue: number; expenses: number }>
    revenue_by_office?: Array<{ id: number; name: string; code?: string; revenue: number }>
    recruitment?: Record<string, number>
    recruitment_trend?: Array<{ from: string; to: string; applications: number; interviews: number; observations: number; approved: number }>
    recruitment_by_office?: Array<{ id: number; name: string; code?: string; applications: number; interviews: number; observations: number; approved: number }>
  } | null>(null)

  const load = () => {
    if (period === 'custom' && (!from || !to)) return
    return api.get('/modules/kams/dashboard', {
      params: {
        group_by: period,
        office_id: officeId || undefined,
        from: period === 'custom' ? from : undefined,
        to: period === 'custom' ? to : undefined,
      },
    }).then((res) => {
      const payload = res.data.data
      setData(payload)
      syncFromPayload(payload)
    })
  }

  useEffect(() => {
    void load()
  }, [period, officeId, from, to])

  useLiveData(load, { enabled: !(period === 'custom' && (!from || !to)) })

  if (!data) return <LoadingScreen />

  const formatDay = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString(locale)

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-2">
        <p className="shrink-0 whitespace-nowrap text-[11px] text-muted sm:text-xs">
          {t('kams.periodRange', { start: formatDay(data.range.from), end: formatDay(data.range.to) })}
        </p>
        <div className="flex w-full min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          {data.can_filter_offices ? (
            <OfficeFilterSelect
              value={officeId}
              offices={data.filter_offices || []}
              onChange={(e) => setOfficeId(e.target.value)}
            />
          ) : null}
          <div className="flex min-w-0 w-full overflow-x-auto rounded-full bg-white p-1 sm:w-auto sm:ml-auto">
            {(['day', 'week', 'month'] as const).map((item) => (
              <button
                key={item}
                type="button"
                onClick={() => setPeriod(item)}
                className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
              >
                {t(`kams.period.${item}`)}
              </button>
            ))}
            <button
              type="button"
              onClick={() => setPeriod('custom')}
              className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none', period === 'custom' ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
            >
              {t('dashboard.customPeriod')}
            </button>
          </div>
        </div>
        {period === 'custom' ? (
          <div className="grid w-full grid-cols-2 gap-2 sm:w-80 sm:ml-auto">
            <Field
              className="min-w-0 max-w-full"
              label={t('dashboard.dateFrom')}
              type="date"
              value={from}
              max={to || today}
              onChange={(event) => setFrom(event.target.value)}
            />
            <Field
              className="min-w-0 max-w-full"
              label={t('dashboard.dateTo')}
              type="date"
              value={to}
              min={from}
              max={today}
              onChange={(event) => setTo(event.target.value)}
            />
          </div>
        ) : null}
      </div>
      {canProducts || canOrders ? (
      <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        {([
          canProducts ? 'stock' : null,
          canOrders ? 'sales' : null,
          canOrders ? 'losses' : null,
          canOrders ? 'defectives' : null,
        ] as const).filter((key): key is 'stock' | 'sales' | 'losses' | 'defectives' => Boolean(key)).map((key) => (
          <Card key={key} className="min-w-0 p-4 sm:p-5">
            <div className="text-xs text-muted sm:text-sm">{t(`kams.${key}`)}</div>
            <div className="mt-2 text-2xl font-extrabold sm:mt-3 sm:text-3xl">{data.stats[key] ?? 0}</div>
          </Card>
        ))}
      </div>
      ) : null}
      {canFinance && data.finance ? (
      <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        {([
          ['revenue', t('dashboard.revenue')],
          ['expenses', t('dashboard.expenses')],
          ['profit', t('dashboard.profit')],
          ['fixed_charges', t('kams.fixedCharges')],
        ] as const).map(([key, label]) => {
          const finance = data.finance
          return (
          <Card key={key} className="min-w-0 p-4 sm:p-5">
            <div className="text-xs text-muted sm:text-sm">{label}</div>
            <div className="mt-2 break-words text-lg font-bold text-lhms sm:mt-3 sm:text-2xl">{money(finance?.[key])}</div>
          </Card>
          )
        })}
      </div>
      ) : null}
      {canFinance ? (
      <Card className="p-4 sm:p-5">
        <div className="mb-4 font-semibold">{t('dashboard.revenue')} · {t('dashboard.expenses')}</div>
        <div className="h-64 sm:h-72">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={(data.trend || []).map((row) => ({
              ...row,
              label: row.from === row.to ? formatDay(row.from) : `${formatDay(row.from)} – ${formatDay(row.to)}`,
            }))}>
              <defs>
                <linearGradient id="kams-revenue" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#043711" stopOpacity={0.25} />
                  <stop offset="95%" stopColor="#043711" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 11 }} />
              <YAxis width={44} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
              <Tooltip formatter={(value, key) => [money(Number(value)), t(`dashboard.${String(key)}`)]} />
              <Area type="monotone" dataKey="revenue" stroke="#043711" fill="url(#kams-revenue)" />
              <Area type="monotone" dataKey="expenses" stroke="#c81e1e" fillOpacity={0} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </Card>
      ) : null}
      {canFinance && !officeId && (data.revenue_by_office || []).length > 1 ? (
      <Card className="p-4 sm:p-5">
        <div className="mb-1 font-semibold">{t('kams.revenueByOffice')}</div>
        <p className="mb-4 text-sm text-muted">{t('kams.revenueByOfficeHint')}</p>
        <div className="h-64 sm:h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart
              layout="vertical"
              data={data.revenue_by_office || []}
              margin={{ top: 4, right: 12, left: 4, bottom: 4 }}
            >
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" horizontal={false} />
              <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
              <YAxis
                type="category"
                dataKey="name"
                width={108}
                tick={{ fontSize: 11 }}
                tickFormatter={(value) => (String(value).length > 14 ? `${String(value).slice(0, 13)}…` : String(value))}
              />
              <Tooltip
                formatter={(value) => [money(Number(value)), t('dashboard.revenue')]}
                labelFormatter={(label, payload) => {
                  const code = payload?.[0]?.payload?.code
                  return code ? `${label} · ${code}` : String(label)
                }}
              />
              <Bar dataKey="revenue" name={t('dashboard.revenue')} radius={[0, 6, 6, 0]} maxBarSize={28}>
                {(data.revenue_by_office || []).map((row, index) => (
                  <Cell key={row.id} fill={OFFICE_REVENUE_COLORS[index % OFFICE_REVENUE_COLORS.length]} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Card>
      ) : null}
      {canRecruitment && data.recruitment ? (
        <Card className="p-5">
          <div className="mb-3 font-semibold">{t('kams.recruitment')}</div>
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {['applications', 'interviews', 'observations', 'approved', 'rejected'].map((key) => (
              <div key={key} className="rounded-xl bg-surface p-4">
                <div className="text-sm text-muted">{t(`kams.${key}`)}</div>
                <div className="mt-2 text-2xl font-extrabold">{data.recruitment?.[key]}</div>
              </div>
            ))}
          </div>
          <div className="mt-5 h-64 sm:h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={(data.recruitment_trend || []).map((row) => ({
                ...row,
                label: row.from === row.to ? formatDay(row.from) : `${formatDay(row.from)} – ${formatDay(row.to)}`,
              }))}>
                <defs>
                  <linearGradient id="kams-applications" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#043711" stopOpacity={0.28} />
                    <stop offset="95%" stopColor="#043711" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis width={36} allowDecimals={false} tick={{ fontSize: 11 }} />
                <Tooltip />
                <Legend />
                <Area type="monotone" dataKey="applications" name={t('kams.applications')} stroke="#043711" fill="url(#kams-applications)" />
                <Area type="monotone" dataKey="interviews" name={t('kams.interviews')} stroke="#2d8a5a" fillOpacity={0} />
                <Area type="monotone" dataKey="observations" name={t('kams.observations')} stroke="#c9a227" fillOpacity={0} />
                <Area type="monotone" dataKey="approved" name={t('kams.approved')} stroke="#1d4ed8" fillOpacity={0} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>
      ) : null}
      {canRecruitment && !officeId && (data.recruitment_by_office || []).length > 1 ? (
      <Card className="p-4 sm:p-5">
        <div className="mb-1 font-semibold">{t('kams.recruitmentByOffice')}</div>
        <p className="mb-4 text-sm text-muted">{t('kams.recruitmentByOfficeHint')}</p>
        <div className="h-72 sm:h-80">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart
              data={data.recruitment_by_office || []}
              margin={{ top: 8, right: 8, left: 0, bottom: 28 }}
            >
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis
                dataKey="name"
                tick={{ fontSize: 11 }}
                interval={0}
                angle={-20}
                textAnchor="end"
                height={56}
                tickFormatter={(value) => (String(value).length > 14 ? `${String(value).slice(0, 13)}…` : String(value))}
              />
              <YAxis width={36} allowDecimals={false} tick={{ fontSize: 11 }} />
              <Tooltip
                labelFormatter={(label, payload) => {
                  const code = payload?.[0]?.payload?.code
                  return code ? `${label} · ${code}` : String(label)
                }}
              />
              <Legend />
              <Bar dataKey="applications" name={t('kams.applications')} fill="#043711" radius={[4, 4, 0, 0]} />
              <Bar dataKey="interviews" name={t('kams.interviews')} fill="#2d8a5a" radius={[4, 4, 0, 0]} />
              <Bar dataKey="observations" name={t('kams.observations')} fill="#c9a227" radius={[4, 4, 0, 0]} />
              <Bar dataKey="approved" name={t('kams.approved')} fill="#1d4ed8" radius={[4, 4, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Card>
      ) : null}
    </div>
  )
}

function ResourcePage({
  endpoint,
  createLabel,
  permPrefix,
  fields,
  render,
}: {
  endpoint: string
  title?: string
  createLabel: string
  permPrefix: string
  fields: Array<{ name: string; label: string; type?: string }>
  render: (row: Record<string, unknown>) => ReactNode
}) {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canCreate = hasPermission(`${permPrefix}.create`)
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([])
  const [companies, setCompanies] = useState<Company[]>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({})

  const load = () => api.get(endpoint).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
    api.get('/companies').then((res) => setCompanies(res.data.data))
  }, [endpoint])

  useLiveData(load)

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
      void showAlert(t('common.saveError'), { tone: 'danger' })
    }
  }

  return (
    <div>
      {canCreate ? (
        <div className="mb-4 flex justify-end">
          <Button onClick={() => setOpen(true)}>{createLabel}</Button>
        </div>
      ) : null}
      <div className="grid gap-3 md:grid-cols-2">
        {rows.map((row) => (
          <Card key={String(row.id)} className="p-5">{render(row)}</Card>
        ))}
      </div>
      {canCreate ? (
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
      ) : null}
    </div>
  )
}

export { KamsClients, KamsClientsList, KamsClientsWorkspace, KamsSuppliersList } from './KamsClientsPage'

export function KamsProducts() {
  const { t } = useTranslation()
  return (
    <ResourcePage
      endpoint="/modules/kams/products"
      title={t('kams.products')}
      createLabel={t('kams.newProduct')}
      permPrefix="kams.products"
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

  const load = () => api.get('/modules/kams/orders').then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
  }, [])

  useLiveData(load)

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
