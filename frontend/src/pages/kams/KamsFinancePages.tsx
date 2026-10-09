import {
  ArrowDownRight,
  ArrowUpRight,
  Building2,
  CircleDollarSign,
  Landmark,
  Layers3,
  PieChart as PieIcon,
  Scale,
  TrendingUp,
  Vault,
  Wallet,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { syncOfficeIdFromPayload } from '../../hooks/useOfficeScope'
import { Outlet, Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import {
  Area,
  AreaChart,
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
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
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { compact, money, setActiveCurrency } from '../../lib/format'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

type Period = 'day' | 'week' | 'month' | 'custom'

type Position = {
  revenue: number
  expenses: number
  profit: number
  cash: number
  assets: number
  liabilities: number
  receivables: number
  payables: number
  equity?: number
  inventory_value?: number
  patrimoine_value?: number
  net_margin?: number
  treasury_balance?: number
}

type CompensationTotals = {
  generated: number
  posted: number
  accrued?: number
  remaining: number
  assistant_share: number
  manager_sale: number
  manager_office: number
  manager_certified_office?: number
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
  manager_certified_share?: number
  divisionnaire_share?: number
  secretary_share?: number
  salary_paid?: number
}

type TreasuryAccount = {
  id: number
  code: string
  name: string
  is_central: boolean
  balance: number
  deposits: number
  withdrawals: number
  expenses: number
  office?: { id: number; name: string; code: string } | null
}

type FinanceData = {
  group_by: Period
  range: { from: string; to: string }
  previous_range?: { from: string; to: string }
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
  position?: Position
  position_delta?: Record<string, number | null>
  trend?: Array<{ from: string; to: string; revenue: number; expenses: number; profit: number }>
  cash_flow?: Array<{ from: string; to: string; deposits: number; withdrawals: number; expenses: number; net: number }>
  cash_flow_totals?: { deposits: number; withdrawals: number; expenses: number; net: number }
  treasury?: { balance: number; accounts: TreasuryAccount[] }
  savings?: {
    guy: number
    manager: number
    assistant_manager: number
    secretary: number
    divisionnaire: number
    total: number
  }
  compensation?: CompensationTotals
  series: Array<{ key: string; from: string; to: string; sales: number; cash: number; profit: number; loss: number }>
  offices: OfficeFinance[]
  currency?: string
  can_filter_offices?: boolean
  office_id?: number | null
  filter_offices?: Array<{ id: number; name: string; code: string }>
}

const CHART_COLORS = ['#043711', '#c9a227', '#1d4ed8', '#0f766e', '#b45309', '#7c3aed', '#c81e1e']

function isoDate(value: Date) {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function startOfWeekMonday(value: Date) {
  const date = new Date(value.getFullYear(), value.getMonth(), value.getDate())
  const weekday = date.getDay()
  date.setDate(date.getDate() - (weekday === 0 ? 6 : weekday - 1))
  return date
}

function presetRange(period: Exclude<Period, 'custom'>, now = new Date()) {
  const to = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (period === 'day') {
    const day = isoDate(to)
    return { from: day, to: day }
  }
  if (period === 'month') {
    return { from: isoDate(new Date(to.getFullYear(), to.getMonth() - 11, 1)), to: isoDate(to) }
  }
  const from = startOfWeekMonday(to)
  from.setDate(from.getDate() - 49)
  return { from: isoDate(from), to: isoDate(to) }
}

function parseDate(value: string) {
  return new Date(`${value}T12:00:00`)
}

function formatDay(value: string, locale: string) {
  return parseDate(value).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
}

function formatRangeLabel(from: string, to: string, locale: string) {
  if (from === to) return formatDay(from, locale)
  const start = parseDate(from)
  const end = parseDate(to)
  if (start.getFullYear() === end.getFullYear() && start.getMonth() === end.getMonth() && start.getDate() === 1 && end.getDate() >= 28) {
    return start.toLocaleDateString(locale, { month: 'short', year: 'numeric' })
  }
  return `${formatDay(from, locale)} – ${formatDay(to, locale)}`
}

function DeltaBadge({ value }: { value?: number | null }) {
  if (value === undefined || value === null) {
    return <span className="text-xs text-muted">—</span>
  }
  const up = value >= 0
  const Icon = up ? ArrowUpRight : ArrowDownRight
  return (
    <span className={cn('inline-flex items-center gap-0.5 text-xs font-semibold', up ? 'text-lhms' : 'text-danger')}>
      <Icon size={12} />
      {Math.abs(value).toLocaleString(undefined, { maximumFractionDigits: 1 })}%
    </span>
  )
}

function KpiCard({
  label,
  value,
  delta,
  hint,
  tone = 'default',
  icon: Icon,
}: {
  label: string
  value: string
  delta?: number | null
  hint?: string
  tone?: 'default' | 'danger' | 'accent' | 'success'
  icon?: typeof Wallet
}) {
  return (
    <Card className="min-w-0 p-3 sm:p-4">
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <div className="text-xs text-muted sm:text-sm">{label}</div>
          {hint ? <div className="mt-0.5 text-[11px] text-muted/80">{hint}</div> : null}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <DeltaBadge value={delta} />
          {Icon ? (
            <span className="grid h-8 w-8 place-items-center rounded-xl bg-lhms-soft text-lhms sm:h-9 sm:w-9">
              <Icon size={15} />
            </span>
          ) : null}
        </div>
      </div>
      <div className={cn(
        'mt-2 break-words text-lg font-extrabold leading-tight tracking-tight tabular-nums sm:text-2xl',
        tone === 'danger' ? 'text-danger' : tone === 'accent' ? 'text-[#b45309]' : tone === 'success' ? 'text-lhms' : 'text-ink',
      )}>
        {value}
      </div>
    </Card>
  )
}

function SectionTitle({ icon: Icon, title, hint }: { icon: typeof Wallet; title: string; hint?: string }) {
  return (
    <div className="mb-3 flex items-start gap-2">
      <span className="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms">
        <Icon size={15} />
      </span>
      <div className="min-w-0">
        <h3 className="text-sm font-semibold sm:text-base">{title}</h3>
        {hint ? <p className="mt-0.5 text-xs text-muted sm:text-sm">{hint}</p> : null}
      </div>
    </div>
  )
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
          { to: '/modules/kams/finance/accounts', end: true, label: t('kams.agentAccounts'), show: hasPermission('kams.finance.accounts.view') },
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
  if (hasPermission('kams.finance.accounts.view')) return <Navigate to="/modules/kams/finance/accounts" replace />
  if (hasPermission('kams.finance.account.view')) return <Navigate to="/modules/kams/finance/account" replace />
  if (hasPermission('kams.finance.company.view')) return <Navigate to="/modules/kams/finance/company" replace />
  return <Navigate to="/modules/kams" replace />
}

export function KamsFinanceDashboard() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const initial = useMemo(() => presetRange('week'), [])
  const today = useMemo(() => isoDate(new Date()), [])
  const [period, setPeriod] = useState<Period>('week')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [officeId, setOfficeId] = useState('')
  const [data, setData] = useState<FinanceData | null>(null)
  const [openOffice, setOpenOffice] = useState<number | null>(null)
  const [failed, setFailed] = useState(false)

  const load = () => {
    if (!from || !to) return
    return api.get('/modules/kams/finance/dashboard', {
      params: {
        group_by: period,
        from,
        to,
        office_id: officeId || undefined,
      },
    })
      .then((res) => {
        const payload = res.data.data
        setData(payload)
        syncOfficeIdFromPayload(setOfficeId, payload)
        if (payload?.currency) setActiveCurrency(payload.currency)
        setFailed(false)
      })
      .catch(() => {
        setFailed(true)
      })
  }

  useEffect(() => {
    void load()
  }, [period, from, to, officeId])

  useLiveData(load, { enabled: Boolean(from && to) })

  const applyPreset = (item: Exclude<Period, 'custom'>) => {
    const next = presetRange(item)
    setPeriod(item)
    setFrom(next.from)
    setTo(next.to)
  }

  const trendData = useMemo(() => {
    if (!data?.trend?.length) return []
    return data.trend.map((row) => ({
      ...row,
      label: formatRangeLabel(row.from, row.to, locale),
    }))
  }, [data, locale])

  const cashFlowData = useMemo(() => {
    if (!data?.cash_flow?.length) return []
    return data.cash_flow.map((row) => ({
      ...row,
      label: formatRangeLabel(row.from, row.to, locale),
    }))
  }, [data, locale])

  const collectionData = useMemo(() => {
    if (!data?.series?.length) return []
    return data.series.map((row) => ({
      ...row,
      label: formatRangeLabel(row.from, row.to, locale),
    }))
  }, [data, locale])

  if (failed) return <Empty>{t('common.empty')}</Empty>
  if (!data) return <LoadingScreen />

  const position = data.position
  const delta = data.position_delta || {}
  const savings = data.savings
  const compensation = data.compensation || data.totals.compensation
  const treasuryAccounts = data.treasury?.accounts || []
  const selectedOffice = (data.filter_offices || []).find((office) => String(office.id) === officeId)

  const composition = [
    { key: 'cash', label: t('dashboard.cash'), value: position?.cash || 0 },
    { key: 'inventory', label: t('kams.inventoryValue'), value: position?.inventory_value || data.totals.inventory_value || 0 },
    { key: 'patrimoine', label: t('kams.patrimoineValue'), value: position?.patrimoine_value || 0 },
    { key: 'receivables', label: t('dashboard.receivables'), value: position?.receivables || 0 },
    { key: 'savings', label: t('kams.totalSavings'), value: savings?.total || 0 },
    { key: 'payables', label: t('dashboard.payables'), value: position?.payables || 0 },
  ].filter((row) => row.value > 0)

  const savingsBreakdown = [
    { key: 'guy', label: t('kams.guySavings'), value: savings?.guy || 0 },
    { key: 'assistant_manager', label: t('kams.assistantManagerSavings'), value: savings?.assistant_manager || 0 },
    { key: 'manager', label: t('kams.managerSavings'), value: savings?.manager || 0 },
    { key: 'secretary', label: t('kams.secretarySavings'), value: savings?.secretary || 0 },
    { key: 'divisionnaire', label: t('kams.divisionnaireSavings'), value: savings?.divisionnaire || 0 },
  ]

  const compensationKpis = compensation ? [
    { key: 'generated', value: compensation.generated },
    { key: 'postedShares', value: compensation.posted },
    { key: 'managerRemaining', value: compensation.remaining },
    { key: 'assistantShare', value: compensation.assistant_share },
    { key: 'managerSaleShare', value: compensation.manager_sale },
    { key: 'managerCertifiedShare', value: compensation.manager_certified_office || 0 },
    { key: 'managerOfficeShare', value: compensation.manager_office },
    { key: 'divisionnaireShare', value: compensation.divisionnaire_office },
    { key: 'secretaryShare', value: compensation.secretary_office + compensation.salary_paid },
  ] : []

  const marginRate = position && position.revenue > 0
    ? ((position.profit / position.revenue) * 100)
    : null
  const expenseRate = position && position.revenue > 0
    ? ((position.expenses / position.revenue) * 100)
    : null

  return (
    <div className="min-w-0 space-y-4 overflow-x-hidden sm:space-y-6">
      <Card className="overflow-hidden border-lhms/20 bg-[linear-gradient(135deg,#043711_0%,#0d6b36_55%,#1a8f4d_100%)] p-4 text-white sm:p-6">
        <div className="min-w-0">
          <div className="text-[10px] font-semibold tracking-[0.22em] text-white/60 sm:text-xs">KAMS · FINANCE</div>
          <h2 className="mt-2 text-xl font-bold tracking-tight sm:text-2xl">{t('kams.financeDashboard')}</h2>
          <p className="mt-1 max-w-2xl text-sm text-white/75">
            {t('kams.financeDashboardHint')}
            {selectedOffice ? ` · ${selectedOffice.name}` : ` · ${t('kams.allOffices')}`}
          </p>
          <p className="mt-2 text-xs text-white/60">
            {t('kams.periodRange', {
              start: parseDate(data.range.from).toLocaleDateString(locale),
              end: parseDate(data.range.to).toLocaleDateString(locale),
            })}
            {data.previous_range ? ` · ${t('kams.vsPreviousPeriod')}` : ''}
          </p>
        </div>
      </Card>

      <Card className="p-3 sm:p-4">
        <div className="flex min-w-0 flex-col gap-2">
          <div className="flex w-full min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            {data.can_filter_offices ? (
              <OfficeFilterSelect
                value={officeId}
                offices={data.filter_offices || []}
                onChange={(e) => {
                  setOfficeId(e.target.value)
                  setOpenOffice(null)
                }}
              />
            ) : null}
            <div className="flex min-w-0 w-full overflow-x-auto rounded-full bg-surface p-1 sm:w-auto sm:ml-auto">
              {(['day', 'week', 'month'] as const).map((item) => (
                <button
                  key={item}
                  type="button"
                  onClick={() => applyPreset(item)}
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
              <Field className="min-w-0" label={t('dashboard.dateFrom')} type="date" value={from} max={to || today} onChange={(e) => setFrom(e.target.value)} />
              <Field className="min-w-0" label={t('dashboard.dateTo')} type="date" value={to} min={from} max={today} onChange={(e) => setTo(e.target.value)} />
            </div>
          ) : null}
        </div>
      </Card>

      <div>
        <SectionTitle icon={CircleDollarSign} title={t('kams.financePlTitle')} hint={t('kams.financePlHint')} />
        <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
          <KpiCard label={t('dashboard.revenue')} value={money(position?.revenue)} delta={delta.revenue} icon={TrendingUp} tone="success" />
          <KpiCard label={t('dashboard.expenses')} value={money(position?.expenses)} delta={delta.expenses} icon={Landmark} tone="danger" />
          <KpiCard label={t('dashboard.profit')} value={money(position?.profit)} delta={delta.profit} icon={CircleDollarSign} tone="success" />
          <KpiCard label={t('kams.cashCollected')} value={money(data.totals.cash)} icon={Wallet} hint={t('kams.cashCollectedHint')} />
          <KpiCard label={t('kams.companyShare')} value={money(data.totals.company_share || 0)} />
          <KpiCard label={t('kams.commissionsPayable')} value={money(data.totals.commissions_payable)} tone="accent" />
          <KpiCard label={t('kams.marginRate')} value={marginRate === null ? '—' : `${marginRate.toFixed(1)}%`} hint={t('kams.marginRateHint')} />
          <KpiCard label={t('kams.expenseRate')} value={expenseRate === null ? '—' : `${expenseRate.toFixed(1)}%`} tone="danger" />
        </div>
      </div>

      <div>
        <SectionTitle icon={Scale} title={t('kams.financeBalanceTitle')} hint={t('kams.financeBalanceHint')} />
        <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
          <KpiCard label={t('dashboard.assets')} value={money(position?.assets)} delta={delta.assets} tone="success" />
          <KpiCard label={t('dashboard.liabilities')} value={money(position?.liabilities)} delta={delta.liabilities} tone="danger" />
          <KpiCard label={t('kams.equity')} value={money(position?.equity)} tone={(position?.equity || 0) >= 0 ? 'success' : 'danger'} />
          <KpiCard label={t('dashboard.cash')} value={money(position?.cash)} delta={delta.cash} icon={Vault} />
          <KpiCard label={t('dashboard.receivables')} value={money(position?.receivables)} delta={delta.receivables} tone="accent" />
          <KpiCard label={t('dashboard.payables')} value={money(position?.payables)} delta={delta.payables} tone="danger" />
          <KpiCard label={t('kams.inventoryValue')} value={money(position?.inventory_value || data.totals.inventory_value)} />
          <KpiCard label={t('kams.patrimoineValue')} value={money(position?.patrimoine_value || 0)} />
          <KpiCard label={t('kams.totalSavings')} value={money(savings?.total || 0)} hint={t('kams.savingsAsLiability')} />
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card className="p-4 sm:p-5">
          <SectionTitle icon={TrendingUp} title={t('kams.revenueExpenseTrend')} hint={t('kams.revenueExpenseTrendHint')} />
          <div className="h-72 sm:h-80">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={trendData}>
                <defs>
                  <linearGradient id="kams-fin-revenue" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#043711" stopOpacity={0.28} />
                    <stop offset="95%" stopColor="#043711" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="kams-fin-profit" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#c9a227" stopOpacity={0.22} />
                    <stop offset="95%" stopColor="#c9a227" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis width={48} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
                <Tooltip formatter={(value, key) => [money(Number(value)), t(`dashboard.${String(key)}`, { defaultValue: String(key) })]} />
                <Legend />
                <Area type="monotone" dataKey="revenue" name={t('dashboard.revenue')} stroke="#043711" fill="url(#kams-fin-revenue)" />
                <Area type="monotone" dataKey="expenses" name={t('dashboard.expenses')} stroke="#c81e1e" fillOpacity={0} />
                <Area type="monotone" dataKey="profit" name={t('dashboard.profit')} stroke="#c9a227" fill="url(#kams-fin-profit)" />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-4 sm:p-5">
          <SectionTitle icon={Wallet} title={t('kams.cashFlowTitle')} hint={t('kams.cashFlowHint')} />
          <div className="mb-3 grid grid-cols-2 gap-2 sm:grid-cols-4">
            {[
              { label: t('kams.deposits'), value: data.cash_flow_totals?.deposits || 0, tone: 'text-lhms' },
              { label: t('kams.withdrawals'), value: data.cash_flow_totals?.withdrawals || 0, tone: 'text-danger' },
              { label: t('kams.expenses'), value: data.cash_flow_totals?.expenses || 0, tone: 'text-danger' },
              { label: t('kams.netCashFlow'), value: data.cash_flow_totals?.net || 0, tone: (data.cash_flow_totals?.net || 0) >= 0 ? 'text-lhms' : 'text-danger' },
            ].map((item) => (
              <div key={item.label} className="rounded-xl bg-surface px-3 py-2">
                <div className="text-[11px] text-muted">{item.label}</div>
                <div className={cn('mt-1 break-words text-sm font-bold tabular-nums', item.tone)}>{money(item.value)}</div>
              </div>
            ))}
          </div>
          <div className="h-56 sm:h-64">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={cashFlowData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis width={48} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
                <Tooltip formatter={(value) => money(Number(value))} />
                <Legend />
                <Bar dataKey="deposits" name={t('kams.deposits')} fill="#043711" radius={[4, 4, 0, 0]} />
                <Bar dataKey="withdrawals" name={t('kams.withdrawals')} fill="#c9a227" radius={[4, 4, 0, 0]} />
                <Bar dataKey="expenses" name={t('kams.expenses')} fill="#c81e1e" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card className="p-4 sm:p-5">
          <SectionTitle icon={PieIcon} title={t('kams.financeCompositionTitle')} hint={t('kams.financeCompositionHint')} />
          {composition.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
            <div className="grid gap-4 sm:grid-cols-[1fr_12rem] sm:items-center">
              <div className="h-64">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie data={composition} dataKey="value" nameKey="label" innerRadius={55} outerRadius={90} paddingAngle={2}>
                      {composition.map((row, index) => (
                        <Cell key={row.key} fill={CHART_COLORS[index % CHART_COLORS.length]} />
                      ))}
                    </Pie>
                    <Tooltip formatter={(value) => money(Number(value))} />
                  </PieChart>
                </ResponsiveContainer>
              </div>
              <div className="space-y-2">
                {composition.map((row, index) => (
                  <div key={row.key} className="flex items-center justify-between gap-2 text-sm">
                    <div className="flex min-w-0 items-center gap-2">
                      <span className="h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: CHART_COLORS[index % CHART_COLORS.length] }} />
                      <span className="truncate text-muted">{row.label}</span>
                    </div>
                    <span className="shrink-0 font-semibold tabular-nums">{money(row.value)}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
        </Card>

        <Card className="p-4 sm:p-5">
          <SectionTitle icon={Layers3} title={t('kams.cashTrend')} hint={t('kams.collectionsTrendHint')} />
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={collectionData}>
                <defs>
                  <linearGradient id="kams-fin-cash" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#043711" stopOpacity={0.28} />
                    <stop offset="95%" stopColor="#043711" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                <YAxis width={48} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
                <Tooltip formatter={(value, key) => [key === 'sales' ? Number(value) : money(Number(value)), key === 'sales' ? t('kams.sales') : t('kams.cashCollected')]} />
                <Legend />
                <Area type="monotone" dataKey="cash" name={t('kams.cashCollected')} stroke="#043711" fill="url(#kams-fin-cash)" />
                <Area type="monotone" dataKey="sales" name={t('kams.sales')} stroke="#c9a227" fillOpacity={0} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card className="p-4 sm:p-5">
          <SectionTitle icon={Vault} title={t('kams.treasuryOverview')} hint={t('kams.treasuryOverviewHint')} />
          <div className="mb-4 rounded-2xl bg-lhms-soft/60 px-4 py-3">
            <div className="text-xs text-muted">{t('kams.treasuryBalance')}</div>
            <div className="mt-1 text-2xl font-extrabold text-lhms tabular-nums">{money(data.treasury?.balance || position?.cash || 0)}</div>
          </div>
          {treasuryAccounts.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
            <div className="max-h-80 space-y-2 overflow-y-auto pr-1">
              {treasuryAccounts.map((account) => (
                <div key={account.id} className="flex items-start justify-between gap-3 rounded-xl border border-line bg-white px-3 py-3">
                  <div className="min-w-0">
                    <div className="truncate font-semibold">{account.name}</div>
                    <div className="text-xs text-muted">
                      {account.is_central ? t('kams.centralAccount') : (account.office?.name || t('kams.officeAccount'))}
                      {account.code ? ` · ${account.code}` : ''}
                    </div>
                  </div>
                  <div className="shrink-0 text-right">
                    <div className="font-extrabold tabular-nums text-lhms">{money(account.balance)}</div>
                    <div className="text-[11px] text-muted">{t('kams.deposits')} {money(account.deposits)}</div>
                  </div>
                </div>
              ))}
            </div>
          )}
        </Card>

        <Card className="p-4 sm:p-5">
          <SectionTitle icon={Landmark} title={t('kams.savingsBreakdown')} hint={t('kams.savingsBreakdownHint')} />
          <div className="mb-4 rounded-2xl bg-surface px-4 py-3">
            <div className="text-xs text-muted">{t('kams.totalSavings')}</div>
            <div className="mt-1 text-2xl font-extrabold tabular-nums">{money(savings?.total || 0)}</div>
          </div>
          <div className="space-y-2">
            {savingsBreakdown.map((row) => {
              const share = savings?.total ? (row.value / savings.total) * 100 : 0
              return (
                <div key={row.key}>
                  <div className="mb-1 flex items-center justify-between gap-2 text-sm">
                    <span className="text-muted">{row.label}</span>
                    <span className="font-semibold tabular-nums">{money(row.value)}</span>
                  </div>
                  <div className="h-2 overflow-hidden rounded-full bg-line/70">
                    <div className="h-full rounded-full bg-lhms transition-all" style={{ width: `${Math.min(100, share)}%` }} />
                  </div>
                </div>
              )
            })}
          </div>
        </Card>
      </div>

      {compensationKpis.length > 0 ? (
        <div>
          <SectionTitle icon={CircleDollarSign} title={t('kams.revenueTitle')} hint={t('kams.financeCompensationHint')} />
          <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
            {compensationKpis.map((item) => (
              <KpiCard key={item.key} label={t(`kams.${item.key}`)} value={money(item.value)} />
            ))}
          </div>
        </div>
      ) : null}

      {(data.offices || []).length > 1 ? (
        <Card className="p-4 sm:p-5">
          <SectionTitle icon={Building2} title={t('kams.officeComparison')} hint={t('kams.officeComparisonHint')} />
          <div className="h-72 sm:h-80">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={[...data.offices].sort((a, b) => b.expected - a.expected)}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="name" tick={{ fontSize: 11 }} interval={0} angle={-20} textAnchor="end" height={60} />
                <YAxis width={48} tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} />
                <Tooltip formatter={(value) => money(Number(value))} />
                <Legend />
                <Bar dataKey="expected" name={t('dashboard.revenue')} fill="#043711" radius={[4, 4, 0, 0]} />
                <Bar dataKey="profit" name={t('dashboard.profit')} fill="#c9a227" radius={[4, 4, 0, 0]} />
                <Bar dataKey="cash" name={t('kams.cashCollected')} fill="#1d4ed8" radius={[4, 4, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>
      ) : null}

      {(data.offices || []).length > 1 ? (
        <div>
          <SectionTitle icon={Building2} title={t('kams.officeFinance')} hint={t('kams.officeFinanceHint')} />
          <div className="grid gap-3">
            {(data.offices || []).map((office) => {
              const open = openOffice === office.id
              return (
                <Card key={office.id} className="overflow-hidden">
                  <button
                    type="button"
                    onClick={() => setOpenOffice(open ? null : office.id)}
                    className="flex w-full items-start justify-between gap-3 p-4 text-left sm:p-5"
                  >
                    <div className="flex min-w-0 items-start gap-3">
                      <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms"><Building2 size={18} /></span>
                      <div className="min-w-0">
                        <div className="truncate font-semibold">{office.name}</div>
                        <div className="text-sm text-muted">{office.code} · {office.agents} {t('kams.agents').toLowerCase()} · {office.sales_count} {t('kams.sales').toLowerCase()}</div>
                      </div>
                    </div>
                    <div className="shrink-0 text-right">
                      <div className="text-base font-extrabold text-lhms tabular-nums sm:text-lg">{money(office.expected)}</div>
                      <div className="text-xs text-muted">{t('dashboard.revenue')} · {money(office.profit)}</div>
                    </div>
                  </button>
                  {open ? (
                    <div className="grid gap-3 border-t border-line bg-surface px-4 py-4 sm:grid-cols-2 sm:px-5 xl:grid-cols-4">
                      {[
                        [t('kams.cashCollected'), money(office.cash)],
                        [t('dashboard.profit'), money(office.profit)],
                        [t('kams.companyShare'), money(office.company_share)],
                        [t('kams.expected'), money(office.expected)],
                        [t('kams.sales'), String(office.sales_count)],
                        [t('kams.guySavings'), money(office.guy_savings)],
                        [t('kams.assistantManagerSavings'), money(office.assistant_manager_savings || 0)],
                        [t('kams.managerSavings'), money(office.manager_savings)],
                        [t('kams.commissionsPayable'), money(office.commissions_payable)],
                        [t('kams.inventoryValue'), money(office.inventory_value)],
                        [t('kams.generated'), money(office.compensation_generated || 0)],
                        [t('kams.managerSaleShare'), money(office.manager_sale || 0)],
                        [t('kams.rule.manager_certified_office'), money(office.manager_certified_share || 0)],
                        [t('kams.assistantShare'), money(office.assistant_share || 0)],
                        [t('kams.managerOfficeShare'), money(office.manager_office_share || 0)],
                        [t('kams.divisionnaireShare'), money(office.divisionnaire_share || 0)],
                        [t('kams.secretaryShare'), money((office.secretary_share || 0) + (office.salary_paid || 0))],
                        [t('kams.postedShares'), money(office.compensation_posted || 0)],
                        [t('kams.managerRemaining'), money(office.compensation_remaining || 0)],
                      ].map(([label, value]) => (
                        <div key={String(label)}>
                          <div className="text-xs text-muted">{label}</div>
                          <div className="mt-1 font-bold tabular-nums">{value}</div>
                        </div>
                      ))}
                    </div>
                  ) : null}
                </Card>
              )
            })}
          </div>
        </div>
      ) : null}
    </div>
  )
}
