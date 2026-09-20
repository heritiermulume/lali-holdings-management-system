import { AlertTriangle, Package, TrendingUp, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Outlet } from 'react-router-dom'
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
import { Card, Empty } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

type Period = 'day' | 'week' | 'month'
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
}

function parseDate(value: string) {
  return new Date(`${value}T12:00:00`)
}

function formatDay(value: string, locale: string) {
  return parseDate(value).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
}

export function KamsStockWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const canStock = hasPermission('kams.products.view')

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/stock', end: true, label: t('kams.statistics'), show: canStock || hasPermission('kams.orders.view') },
          { to: '/modules/kams/stock/warehouses', end: true, label: t('kams.warehouses'), show: canStock },
          { to: '/modules/kams/stock/depots', end: true, label: t('kams.depots'), show: canStock },
          { to: '/modules/kams/stock/assignments', label: t('kams.commercialOperations'), show: canStock },
        ]}
      />
      <Outlet />
    </div>
  )
}

export function KamsStockStats() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const [period, setPeriod] = useState<Period>('week')
  const [data, setData] = useState<StockData | null>(null)

  useEffect(() => {
    let active = true
    api.get('/modules/kams/stock/dashboard', { params: { group_by: period } })
      .then((res) => { if (active) setData(res.data.data) })
    return () => { active = false }
  }, [period])

  const chartData = useMemo(() => {
    if (!data) return []
    return data.series.map((row) => ({
      ...row,
      label: period === 'day'
        ? formatDay(row.from, locale)
        : period === 'month'
          ? parseDate(row.from).toLocaleDateString(locale, { month: 'short', year: 'numeric' })
          : `${formatDay(row.from, locale)} – ${formatDay(row.to, locale)}`,
    }))
  }, [data, locale, period])

  if (!data) return <LoadingScreen />

  const kpis = [
    { key: 'availableStock', value: data.totals.units, icon: Package },
    { key: 'soldUnits', value: data.totals.sold_units, icon: TrendingUp },
    { key: 'activeSellers', value: data.totals.active_sellers, icon: Users },
    { key: 'openAssignments', value: data.totals.open_assignments, icon: Package },
  ]

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-lg font-bold">{t('kams.statistics')}</h2>
          <p className="mt-1 text-sm text-muted">{t('kams.stockOpsHint')}</p>
          <p className="mt-1 text-xs text-muted">
            {t('kams.periodRange', {
              from: parseDate(data.range.from).toLocaleDateString(locale),
              to: parseDate(data.range.to).toLocaleDateString(locale),
            })}
          </p>
        </div>
        <div className="flex rounded-full bg-white p-1">
          {(['day', 'week', 'month'] as Period[]).map((item) => (
            <button
              key={item}
              type="button"
              onClick={() => setPeriod(item)}
              className={cn('min-h-10 rounded-full px-4 text-sm font-semibold', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
            >
              {t(`kams.period.${item}`)}
            </button>
          ))}
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {kpis.map((item) => {
          const Icon = item.icon
          return (
            <Card key={item.key} className="p-4">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm text-muted">{t(`kams.${item.key}`)}</span>
                <span className="grid h-9 w-9 place-items-center rounded-xl bg-lhms-soft text-lhms"><Icon size={16} /></span>
              </div>
              <div className="mt-3 text-3xl font-extrabold">{item.value}</div>
            </Card>
          )
        })}
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.warehouses')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.totals.warehouse_units}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.depots')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.totals.depot_units}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.withAgents')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.totals.with_agents}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.inTransit')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.totals.in_transit}</div>
          <div className="mt-1 text-xs text-muted">{t('kams.quarantine')}: {data.totals.quarantine}</div>
        </Card>
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

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('kams.stockActivity')}</div>
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
              <Tooltip />
              <Legend />
              <Area type="monotone" dataKey="transfers" name={t('kams.transfers')} stroke="#094924" fill="#09492422" />
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
