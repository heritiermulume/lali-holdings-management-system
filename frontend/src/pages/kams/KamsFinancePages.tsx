import { Building2, Landmark, TrendingDown, TrendingUp, Wallet } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Outlet, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { Card, Empty, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { compact, money } from '../../lib/format'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

type Period = 'day' | 'week' | 'month'
type SeriesPoint = {
  key: string
  from: string
  to: string
  sales: number
  cash: number
  profit: number
  loss: number
}
type FinanceData = {
  group_by: Period
  range: { from: string; to: string }
  totals: {
    inventory_value: number
    commissions_payable: number
    guy_savings?: number
    manager_savings?: number
    assistant_manager_savings?: number
    cash: number
    profit: number
    loss: number
    expected: number
    company_share?: number
    sold_units: number
    compensation?: CompensationTotals
  }
  compensation?: CompensationTotals
  series: SeriesPoint[]
  offices: OfficeFinance[]
  can_filter_offices?: boolean
  office_id?: number | null
  filter_offices?: Array<{ id: number; name: string; code: string }>
}
type CompensationTotals = {
  generated: number
  posted: number
  accrued?: number
  remaining: number
  assistant_share: number
  manager_sale: number
  manager_office: number
  divisionnaire_office: number
  secretary_office: number
  salary_paid: number
}
type OfficeFinance = {
  id: number
  name: string
  code: string
  cash: number
  profit: number
  expected: number
  company_share: number
  sales_count: number
  agents: number
  guy_savings: number
  manager_savings: number
  assistant_manager_savings?: number
  commissions_payable: number
  inventory_value: number
  compensation_generated?: number
  compensation_posted?: number
  compensation_remaining?: number
  manager_sale?: number
  assistant_share?: number
  manager_office_share?: number
  divisionnaire_share?: number
  secretary_share?: number
  salary_paid?: number
}

function parseDate(value: string) {
  return new Date(`${value}T12:00:00`)
}

function formatDay(value: string, locale: string) {
  return parseDate(value).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
}

export function KamsFinanceWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/finance', end: true, label: t('nav.dashboard'), show: hasPermission('kams.finance.dashboard.view') },
          { to: '/modules/kams/finance/account', end: true, label: t('kams.myAccount'), show: hasPermission('kams.finance.account.view') },
          { to: '/modules/kams/finance/accounts', end: true, label: t('kams.memberAccounts'), show: hasPermission('kams.finance.dashboard.view') },
          { to: '/modules/kams/finance/company', end: true, label: t('kams.companyAccount'), show: hasPermission('kams.finance.company.view') },
        ]}
      />
      <Outlet />
    </div>
  )
}

export function KamsFinanceIndex() {
  const { hasPermission } = useAuth()
  if (hasPermission('kams.finance.dashboard.view')) return <KamsFinanceDashboard />
  if (hasPermission('kams.finance.account.view')) return <Navigate to="/modules/kams/finance/account" replace />
  if (hasPermission('kams.finance.company.view')) return <Navigate to="/modules/kams/finance/company" replace />
  return <Navigate to="/modules/kams" replace />
}

export function KamsFinanceDashboard() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const [period, setPeriod] = useState<Period>('week')
  const [officeId, setOfficeId] = useState('')
  const [data, setData] = useState<FinanceData | null>(null)
  const [openOffice, setOpenOffice] = useState<number | null>(null)

  useEffect(() => {
    let active = true
    api.get('/modules/kams/finance/dashboard', {
      params: { group_by: period, office_id: officeId || undefined },
    }).then((res) => { if (active) setData(res.data.data) })
    return () => { active = false }
  }, [period, officeId])

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

  const compensation = data.compensation || data.totals.compensation
  const kpis = [
    { key: 'cashCollected', value: money(data.totals.cash), icon: Wallet },
    { key: 'profit', value: money(data.totals.profit), icon: TrendingUp },
    { key: 'loss', value: money(data.totals.loss), icon: TrendingDown },
    { key: 'commissionsPayable', value: money(data.totals.commissions_payable), icon: Landmark },
  ]

  const compensationKpis = compensation ? [
    { key: 'generated', value: money(compensation.generated) },
    { key: 'postedShares', value: money(compensation.posted) },
    { key: 'managerRemaining', value: money(compensation.remaining) },
    { key: 'assistantShare', value: money(compensation.assistant_share) },
    { key: 'managerSaleShare', value: money(compensation.manager_sale) },
    { key: 'managerOfficeShare', value: money(compensation.manager_office) },
    { key: 'divisionnaireShare', value: money(compensation.divisionnaire_office) },
    { key: 'secretaryShare', value: money(compensation.secretary_office + compensation.salary_paid) },
  ] : []

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-lg font-bold">{t('kams.financeDashboard')}</h2>
          <p className="mt-1 text-sm text-muted">{t('kams.financeDashboardHint')}</p>
          <p className="mt-1 truncate text-xs text-muted whitespace-nowrap">
            {t('kams.periodRange', {
              start: parseDate(data.range.from).toLocaleDateString(locale),
              end: parseDate(data.range.to).toLocaleDateString(locale),
            })}
          </p>
        </div>
        <div className="flex flex-col gap-3 sm:items-end">
          {data.can_filter_offices ? (
            <Select
              className="w-full min-w-0 sm:w-72"
              label={t('kams.filterOffice')}
              value={officeId}
              onChange={(e) => {
                setOfficeId(e.target.value)
                setOpenOffice(null)
              }}
            >
              <option value="">{t('kams.allOffices')}</option>
              {(data.filter_offices || []).map((office) => (
                <option key={office.id} value={office.id}>{office.name}{office.code ? ` · ${office.code}` : ''}</option>
              ))}
            </Select>
          ) : null}
          <div className="flex w-full overflow-x-auto rounded-full bg-white p-1 sm:w-auto">
            {(['day', 'week', 'month'] as Period[]).map((item) => (
              <button
                key={item}
                type="button"
                onClick={() => setPeriod(item)}
                className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
              >
                {t(`kams.period.${item}`)}
              </button>
            ))}
          </div>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        {kpis.map((item) => {
          const Icon = item.icon
          return (
            <Card key={item.key} className="p-4">
              <div className="flex items-center justify-between gap-2">
                <span className="text-sm text-muted">{t(`kams.${item.key}`)}</span>
                <span className="grid h-9 w-9 place-items-center rounded-xl bg-lhms-soft text-lhms"><Icon size={16} /></span>
              </div>
              <div className="mt-3 break-words text-xl font-extrabold sm:text-3xl">{item.value}</div>
            </Card>
          )
        })}
      </div>

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-5">
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.guySavings')}</div>
          <div className="mt-2 break-words text-lg font-extrabold sm:text-2xl">{money(data.totals.guy_savings || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.assistantManagerSavings')}</div>
          <div className="mt-2 break-words text-lg font-extrabold sm:text-2xl">{money(data.totals.assistant_manager_savings || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.managerSavings')}</div>
          <div className="mt-2 break-words text-lg font-extrabold sm:text-2xl">{money(data.totals.manager_savings || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.inventoryValue')}</div>
          <div className="mt-2 break-words text-lg font-extrabold text-lhms sm:text-2xl">{money(data.totals.inventory_value)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.companyShare')}</div>
          <div className="mt-2 break-words text-lg font-extrabold sm:text-2xl">{money(data.totals.company_share || 0)}</div>
        </Card>
      </div>

      {compensationKpis.length > 0 ? (
        <div>
          <h3 className="mb-1 text-base font-bold">{t('kams.revenueTitle')}</h3>
          <p className="mb-4 text-sm text-muted">{t('kams.financeCompensationHint')}</p>
          <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
            {compensationKpis.map((item) => (
              <Card key={item.key} className="p-4">
                <div className="text-sm text-muted">{t(`kams.${item.key}`)}</div>
                <div className="mt-2 break-words text-lg font-extrabold sm:text-2xl">{item.value}</div>
              </Card>
            ))}
          </div>
        </div>
      ) : null}

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('kams.cashTrend')}</div>
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} />
              <YAxis width={44} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
              <Tooltip />
              <Legend />
              <Area type="monotone" dataKey="cash" name={t('kams.cashCollected')} stroke="#094924" fill="#09492422" />
              <Area type="monotone" dataKey="sales" name={t('kams.sales')} stroke="#c9a227" fillOpacity={0} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('kams.profitLossTrend')}</div>
        <div className="h-72">
          <ResponsiveContainer width="100%" height="100%">
            <BarChart data={chartData}>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} />
              <YAxis width={44} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
              <Tooltip />
              <Legend />
              <Bar dataKey="profit" name={t('kams.profit')} fill="#094924" radius={[6, 6, 0, 0]} />
              <Bar dataKey="loss" name={t('kams.loss')} fill="#c81e1e" radius={[6, 6, 0, 0]} />
            </BarChart>
          </ResponsiveContainer>
        </div>
      </Card>

      {(data.offices || []).length > 1 ? (
        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('kams.officeComparison')}</div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={data.offices}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="name" tick={{ fontSize: 12 }} />
                <YAxis width={44} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
                <Tooltip />
                <Legend />
                <Bar dataKey="cash" name={t('kams.cashCollected')} fill="#094924" radius={[6, 6, 0, 0]} />
                <Bar dataKey="profit" name={t('kams.profit')} fill="#c9a227" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>
      ) : null}

      <div>
        <h3 className="text-base font-bold">{t('kams.officeFinance')}</h3>
        <p className="mt-1 mb-4 text-sm text-muted">{t('kams.officeFinanceHint')}</p>
        {(data.offices || []).length === 0 ? <Empty>{t('common.empty')}</Empty> : (
        <div className="grid gap-3">
          {(data.offices || []).map((office) => {
            const open = openOffice === office.id
            return (
              <Card key={office.id} className="overflow-hidden">
                <button
                  type="button"
                  onClick={() => setOpenOffice(open ? null : office.id)}
                  className="flex w-full items-start justify-between gap-3 p-5 text-left"
                >
                  <div className="flex min-w-0 items-start gap-3">
                    <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms"><Building2 size={18} /></span>
                    <div className="min-w-0">
                      <div className="truncate font-semibold">{office.name}</div>
                      <div className="text-sm text-muted">{office.code} · {office.agents} {t('kams.agents').toLowerCase()}</div>
                    </div>
                  </div>
                  <div className="shrink-0 text-right">
                    <div className="text-base font-extrabold text-lhms sm:text-lg">{money(office.cash)}</div>
                    <div className="text-xs text-muted">{t('kams.cashCollected')}</div>
                  </div>
                </button>
                {open ? (
                  <div className="grid gap-3 border-t border-line bg-surface px-5 py-4 sm:grid-cols-2 xl:grid-cols-4">
                    <div>
                      <div className="text-xs text-muted">{t('kams.profit')}</div>
                      <div className="mt-1 font-bold">{money(office.profit)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.companyShare')}</div>
                      <div className="mt-1 font-bold">{money(office.company_share)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.expected')}</div>
                      <div className="mt-1 font-bold">{money(office.expected)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.sales')}</div>
                      <div className="mt-1 font-bold">{office.sales_count}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.guySavings')}</div>
                      <div className="mt-1 font-bold">{money(office.guy_savings)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.assistantManagerSavings')}</div>
                      <div className="mt-1 font-bold">{money(office.assistant_manager_savings || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.managerSavings')}</div>
                      <div className="mt-1 font-bold">{money(office.manager_savings)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.commissionsPayable')}</div>
                      <div className="mt-1 font-bold">{money(office.commissions_payable)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.inventoryValue')}</div>
                      <div className="mt-1 font-bold">{money(office.inventory_value)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.generated')}</div>
                      <div className="mt-1 font-bold">{money(office.compensation_generated || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.managerSaleShare')}</div>
                      <div className="mt-1 font-bold">{money(office.manager_sale || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.assistantShare')}</div>
                      <div className="mt-1 font-bold">{money(office.assistant_share || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.managerOfficeShare')}</div>
                      <div className="mt-1 font-bold">{money(office.manager_office_share || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.divisionnaireShare')}</div>
                      <div className="mt-1 font-bold">{money(office.divisionnaire_share || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.secretaryShare')}</div>
                      <div className="mt-1 font-bold">{money((office.secretary_share || 0) + (office.salary_paid || 0))}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.postedShares')}</div>
                      <div className="mt-1 font-bold">{money(office.compensation_posted || 0)}</div>
                    </div>
                    <div>
                      <div className="text-xs text-muted">{t('kams.managerRemaining')}</div>
                      <div className="mt-1 font-bold">{money(office.compensation_remaining || 0)}</div>
                    </div>
                  </div>
                ) : null}
              </Card>
            )
          })}
        </div>
        )}
      </div>
    </div>
  )
}
