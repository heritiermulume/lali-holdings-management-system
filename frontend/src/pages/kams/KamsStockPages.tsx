import { AlertTriangle } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Navigate, Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Area,
  AreaChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { OfficeFilterSelect } from '../../components/OfficeFilterSelect'
import { Card, Empty, Field } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import { syncOfficeIdFromPayload } from '../../hooks/useOfficeScope'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

type Period = 'day' | 'week' | 'month' | 'custom'
type SeriesPoint = {
  key: string
  from: string
  to: string
  transfers: number
  assignments: number
  sales: number
  settlements: number
  cash: number
  profit: number
  loss: number
}
type StockData = {
  group_by: Period
  range: { from: string; to: string }
  totals: {
    products: number
    warehouses: number
    depots: number
    warehouse_units: number
    depot_units: number
    with_agents: number
    in_transit: number
    quarantine: number
    units: number
    inventory_value: number
    open_assignments: number
    sold_units: number
    active_sellers: number
    commissions_payable: number
    guy_savings?: number
    manager_savings?: number
    cash: number
    profit: number
    loss: number
    expected: number
  }
  series: SeriesPoint[]
  alerts: Array<{ tone: string; key: string; count: number }>
  recommendations: Array<{ key: string; product?: string; from?: string; to?: string; quantity?: number; agent?: string }>
  low_stock: Array<{ product?: string; sku?: string; location?: string; kind?: string; quantity: number }>
  can_filter_offices?: boolean
  office_id?: number | null
  filter_offices?: Array<{ id: number; name: string; code?: string }>
}

function parseDate(value: string) {
  return new Date(`${value}T12:00:00`)
}

function isoDate(value: Date) {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function formatDay(value: string, locale: string) {
  return parseDate(value).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
}

export function KamsStockIndex() {
  const { hasPermission } = useAuth()
  if (hasPermission('kams.stock.statistics.view')) return <KamsStockStats />
  if (hasPermission('kams.stock.warehouses.view')) return <Navigate to="/modules/kams/stock/warehouses" replace />
  if (hasPermission('kams.stock.depots.view')) return <Navigate to="/modules/kams/stock/depots" replace />
  if (hasPermission('kams.stock.assignments.view')) return <Navigate to="/modules/kams/stock/assignments" replace />
  if (hasPermission('kams.stock.reports.view')) return <Navigate to="/modules/kams/stock/assignments/reports" replace />
  if (hasPermission('kams.stock.journal.view')) return <Navigate to="/modules/kams/stock/assignments/journal" replace />
  return <Navigate to="/modules/kams" replace />
}

export function KamsStockWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canStats = hasPermission('kams.stock.statistics.view')
  const canWarehouses = hasPermission('kams.stock.warehouses.view')
  const canDepots = hasPermission('kams.stock.depots.view')
  const canAssignments = hasPermission('kams.stock.assignments.view') || hasPermission('kams.stock.reports.view') || hasPermission('kams.stock.journal.view')

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/stock', end: true, label: t('kams.statistics'), show: canStats },
          { to: '/modules/kams/stock/warehouses', label: t('kams.warehouses'), show: canWarehouses },
          { to: '/modules/kams/stock/depots', label: t('kams.depots'), show: canDepots },
          { to: '/modules/kams/stock/assignments', label: t('kams.commercialOperations'), show: canAssignments },
        ]}
      />
      <Outlet />
    </div>
  )
}

export function KamsStockStats() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const today = isoDate(new Date())
  const [period, setPeriod] = useState<Period>('week')
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [officeId, setOfficeId] = useState('')
  const [data, setData] = useState<StockData | null>(null)

  const load = () => {
    if (period === 'custom' && (!from || !to)) return
    return api.get('/modules/kams/stock/dashboard', {
      params: {
        group_by: period,
        office_id: officeId || undefined,
        from: period === 'custom' ? from : undefined,
        to: period === 'custom' ? to : undefined,
      },
    }).then((res) => {
      const payload = res.data.data
      setData(payload)
      syncOfficeIdFromPayload(setOfficeId, payload)
    })
  }

  useEffect(() => {
    void load()
  }, [period, from, to, officeId])

  useLiveData(load, { enabled: !(period === 'custom' && (!from || !to)) })

  const chartData = useMemo(() => {
    if (!data) return []
    return data.series.map((row) => ({
      ...row,
      label: row.from === row.to
        ? formatDay(row.from, locale)
        : `${formatDay(row.from, locale)} – ${formatDay(row.to, locale)}`,
    }))
  }, [data, locale])

  if (!data) return <LoadingScreen />

  const kpis = [
    { key: 'availableStock', value: data.totals.units },
    { key: 'soldUnits', value: data.totals.sold_units },
    { key: 'openAssignments', value: data.totals.open_assignments },
    { key: 'withAgents', value: data.totals.with_agents },
    { key: 'inTransit', value: data.totals.in_transit, hint: `${t('kams.quarantine')}: ${data.totals.quarantine}` },
  ]

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-2">
        <p className="shrink-0 whitespace-nowrap text-[11px] text-muted sm:text-xs">
          {t('kams.periodRange', {
            start: parseDate(data.range.from).toLocaleDateString(locale),
            end: parseDate(data.range.to).toLocaleDateString(locale),
          })}
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
            <Field className="min-w-0 max-w-full" label={t('dashboard.dateFrom')} type="date" value={from} max={to || today} onChange={(event) => setFrom(event.target.value)} />
            <Field className="min-w-0 max-w-full" label={t('dashboard.dateTo')} type="date" value={to} min={from} max={today} onChange={(event) => setTo(event.target.value)} />
          </div>
        ) : null}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
        {kpis.map((item) => (
          <Card key={item.key} className="min-w-0 p-4 sm:p-5">
            <div className="text-xs text-muted sm:text-sm">{t(`kams.${item.key}`)}</div>
            <div className="mt-2 text-2xl font-extrabold sm:mt-3 sm:text-3xl">{item.value}</div>
            {'hint' in item && item.hint ? <div className="mt-1 text-xs text-muted">{item.hint}</div> : null}
          </Card>
        ))}
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('kams.stockAlerts')}</div>
          {data.alerts.length === 0 ? <Empty>{t('kams.noAlerts')}</Empty> : (
            <div className="space-y-3">
              {data.alerts.map((alert) => (
                <div key={alert.key} className="flex items-center justify-between rounded-xl bg-surface p-3">
                  <div className="flex items-center gap-2 text-sm">
                    <AlertTriangle size={16} className={alert.tone === 'red' ? 'text-red-600' : 'text-amber-600'} />
                    {t(`kams.alert.${alert.key}`, { count: alert.count })}
                  </div>
                  <span className="font-semibold">{alert.count}</span>
                </div>
              ))}
            </div>
          )}
        </Card>
        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('kams.recommendations')}</div>
          {data.recommendations.length === 0 ? <Empty>{t('kams.noRecommendations')}</Empty> : (
            <div className="space-y-3">
              {data.recommendations.map((row, index) => (
                <div key={`${row.key}-${index}`} className="rounded-xl bg-surface p-3 text-sm">
                  {t(`kams.recommend.${row.key}`, {
                    product: row.product || '—',
                    from: row.from || '—',
                    to: row.to || '—',
                    quantity: row.quantity || 0,
                    agent: row.agent || '—',
                  })}
                </div>
              ))}
            </div>
          )}
        </Card>
      </div>

      <Card className="p-4 sm:p-5">
        <div className="mb-4 font-semibold">{t('kams.stockActivity')}</div>
        <div className="h-64 sm:h-72">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 11 }} />
              <YAxis width={36} allowDecimals={false} tick={{ fontSize: 11 }} />
              <Tooltip />
              <Legend />
              <Area type="monotone" dataKey="transfers" name={t('kams.transfers')} stroke="#043711" fill="#04371122" />
              <Area type="monotone" dataKey="assignments" name={t('kams.assignments')} stroke="#2d8a5a" fillOpacity={0} />
              <Area type="monotone" dataKey="sales" name={t('kams.sales')} stroke="#c9a227" fillOpacity={0} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('kams.lowStock')}</div>
        {data.low_stock.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
          <div className="space-y-3">
            {data.low_stock.map((row, index) => (
              <div key={`${row.product}-${row.location}-${index}`} className="flex items-center justify-between rounded-xl bg-surface p-3">
                <div>
                  <div className="font-semibold">{row.product}</div>
                  <div className="text-sm text-muted">{row.location}</div>
                </div>
                <div className="font-semibold">{row.quantity}</div>
              </div>
            ))}
          </div>
        )}
      </Card>
    </div>
  )
}
