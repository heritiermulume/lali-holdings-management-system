import {
  ArrowDownRight,
  ArrowUpRight,
  Building2,
  ChevronRight,
  CircleDollarSign,
  ClipboardList,
  Globe2,
  MapPin,
  TrendingUp,
  Users,
  UserPlus,
  Warehouse,
  X,
} from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet, useParams } from 'react-router-dom'
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
import { Badge, Card, Empty, Field } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { PresenceMap, type PresenceCountry } from '../../components/PresenceMap'
import { useAuth } from '../../context/AuthContext'
import { useSystemModules } from '../../context/ModulesContext'
import { canAccessModule } from '../../lib/access'
import { cn } from '../../lib/cn'
import { compact, greetingKey, money, roleLabel } from '../../lib/format'
import api from '../../lib/api'
import { KamsDashboard } from '../kams/KamsPages'
import { ModuleOfficePerformance } from './ModuleOfficePerformance'
import type { SystemModule } from '../../types'

type Period = 'day' | 'week' | 'month' | 'custom'

type FinanceBlock = {
  revenue: number
  expenses: number
  profit: number
  cash: number
  assets: number
  liabilities: number
  receivables: number
  payables: number
}

type OperationsBlock = {
  agents: number
  clients: number
  products: number
  sales_count: number
  sold_units: number
  inventory_units: number
  inventory_value: number
  commissions_payable: number
}

type CompanyPerformance = {
  id: number
  name: string
  code: string
  module_slug?: string | null
  module_name?: string | null
  module_name_fr?: string | null
  country?: string | null
  offices_count: number
  revenue: number
  profit: number
  cash: number
  receivables: number
  payables: number
  agents: number
  clients: number
  sales_count: number
  delta_revenue?: number | null
  delta_profit?: number | null
}

type OfficePerformance = {
  id: number
  name: string
  code: string
  revenue: number
  profit: number
  cash: number
  sales_count: number
  agents: number
  inventory_value: number
  commissions_payable: number
  compensation: number
}

type DashboardAlert = {
  tone: string
  key: string
  count: number
  value?: number
  company?: string
  company_id?: number
}

type DashboardData = {
  welcome: { name: string; role?: string; company?: string }
  scope: {
    level: 'group' | 'company' | 'office'
    company_id: number | null
    office_id: number | null
    companies: Array<{ id: number; name: string; code: string; module_slug?: string | null }>
    offices: Array<{ id: number; name: string; code: string }>
  }
  group_by: Period
  range: { from: string; to: string }
  previous_range: { from: string; to: string }
  currency: string
  stats: Record<string, number>
  finances?: FinanceBlock | null
  finances_delta?: Record<string, number | null>
  operations?: OperationsBlock
  operations_delta?: Record<string, number | null>
  kams_compensation?: {
    generated: number
    posted: number
    remaining: number
    manager_sale: number
    assistant_share: number
    manager_office: number
    divisionnaire_office: number
    secretary_office: number
    salary_paid: number
    range?: { from: string; to: string }
  } | null
  kams_savings?: {
    guy_savings: number
    assistant_manager_savings: number
    manager_savings: number
  } | null
  recruitment?: {
    applications: number
    interviews: number
    observations: number
    approved: number
    rejected: number
    conversion_rate: number
  } | null
  modules: SystemModule[]
  notifications: Array<{ id: number; title: string; body: string; type: string; read_at?: string | null }>
  tasks: Array<{ id: number; title: string; priority: string; due_date?: string | null; status: string }>
  activity: Array<{ id: number; action: string; auditable_type: string; created_at: string; user?: { name: string } }>
  performance: CompanyPerformance[]
  offices_performance?: OfficePerformance[] | null
  alerts: DashboardAlert[]
  presence?: PresenceCountry[]
  trend: Array<{ label: string; from: string; to: string; revenue: number; expenses: number; profit: number }>
  comparison: Array<{ id: number; name: string; revenue: number; profit: number; cash: number }>
}

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
  tone = 'default',
  onClick,
}: {
  label: string
  value: string
  delta?: number | null
  tone?: 'default' | 'danger' | 'accent'
  onClick?: () => void
}) {
  const Comp = onClick ? 'button' : 'div'
  return (
    <Comp
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      className={cn(
        'min-w-0 rounded-2xl border border-line bg-white p-3 text-left shadow-[0_10px_40px_rgba(4,55,17,0.04)] sm:p-4',
        onClick && 'transition hover:border-lhms/40 hover:bg-lhms-soft/40',
      )}
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0 text-xs text-muted sm:text-sm">{label}</div>
        <DeltaBadge value={delta} />
      </div>
      <div className={cn(
        'mt-2 break-words text-lg font-extrabold leading-tight tracking-tight tabular-nums sm:text-2xl',
        tone === 'danger' ? 'text-danger' : tone === 'accent' ? 'text-[#b45309]' : 'text-ink',
      )}>
        {value}
      </div>
    </Comp>
  )
}

export function GroupDashboardWorkspace() {
  return <Outlet />
}

function useGroupDashboard() {
  const initial = useMemo(() => presetRange('week'), [])
  const today = useMemo(() => isoDate(new Date()), [])
  const [period, setPeriod] = useState<Period>('week')
  const [lastPreset, setLastPreset] = useState<Exclude<Period, 'custom'>>('week')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [companyId, setCompanyId] = useState<number | ''>('')
  const [officeId, setOfficeId] = useState<number | ''>('')
  const [data, setData] = useState<DashboardData | null>(null)
  const [failed, setFailed] = useState(false)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!from || !to) return
    let active = true
    setLoading(true)
    api.get('/dashboard', {
      params: {
        group_by: period,
        from,
        to,
        company_id: companyId || undefined,
        office_id: officeId || undefined,
      },
    })
      .then((res) => {
        if (!active) return
        setData(res.data.data)
        setFailed(false)
      })
      .catch(() => {
        if (!active) return
        setFailed(true)
      })
      .finally(() => {
        if (active) setLoading(false)
      })
    return () => { active = false }
  }, [period, from, to, companyId, officeId])

  const applyPreset = (item: Exclude<Period, 'custom'>) => {
    const next = presetRange(item)
    setLastPreset(item)
    setPeriod(item)
    setFrom(next.from)
    setTo(next.to)
  }

  const selectCompany = (id: number | '') => {
    setCompanyId(id)
    setOfficeId('')
  }

  return {
    data,
    failed,
    loading,
    period,
    from,
    to,
    today,
    companyId,
    officeId,
    setFrom,
    setTo,
    setPeriod,
    applyPreset,
    lastPreset,
    selectCompany,
    setOfficeId,
  }
}

function ScopeFilters({
  data,
  period,
  from,
  to,
  today,
  companyId,
  officeId,
  lastPreset,
  applyPreset,
  setPeriod,
  setFrom,
  setTo,
  selectCompany,
  setOfficeId,
}: ReturnType<typeof useGroupDashboard> & { data: DashboardData }) {
  const { t } = useTranslation()

  return (
    <Card className="p-3 sm:p-5">
      <div className="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between xl:gap-4">
        <div className="grid flex-1 gap-3 sm:grid-cols-2 xl:max-w-xl">
          <label className="block text-sm">
            <span className="mb-1.5 block font-medium text-muted">{t('groupDash.scopeCompany')}</span>
            <select
              className="min-h-11 w-full max-w-full rounded-xl border border-line bg-white px-3 text-sm"
              value={companyId}
              onChange={(event) => selectCompany(event.target.value ? Number(event.target.value) : '')}
            >
              <option value="">{t('groupDash.scopeGroup')}</option>
              {data.scope.companies.map((company) => (
                <option key={company.id} value={company.id}>{company.name}</option>
              ))}
            </select>
          </label>
          <label className="block text-sm">
            <span className="mb-1.5 block font-medium text-muted">{t('groupDash.scopeOffice')}</span>
            <select
              className="min-h-11 w-full max-w-full rounded-xl border border-line bg-white px-3 text-sm disabled:opacity-50"
              value={officeId}
              disabled={!companyId || data.scope.offices.length === 0}
              onChange={(event) => setOfficeId(event.target.value ? Number(event.target.value) : '')}
            >
              <option value="">{t('groupDash.allOffices')}</option>
              {data.scope.offices.map((office) => (
                <option key={office.id} value={office.id}>{office.name}</option>
              ))}
            </select>
          </label>
        </div>

        <div className="flex w-full flex-col gap-2 sm:flex-row sm:items-end sm:justify-between xl:w-auto xl:justify-end">
          <button
            type="button"
            onClick={() => (period === 'custom' ? applyPreset(lastPreset) : setPeriod('custom'))}
            className="min-h-10 shrink-0 self-start rounded-full bg-surface px-3 py-2 text-xs font-semibold text-lhms sm:text-sm"
          >
            {period === 'custom' ? t('dashboard.presetPeriod') : t('dashboard.customPeriod')}
          </button>
          {period === 'custom' ? (
            <div className="grid w-full grid-cols-2 gap-2 sm:w-80">
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
          ) : (
            <div className="flex w-full rounded-full bg-surface p-1 sm:w-auto">
              {(['day', 'week', 'month'] as const).map((item) => (
                <button
                  key={item}
                  type="button"
                  onClick={() => applyPreset(item)}
                  className={cn('min-h-10 flex-1 rounded-full px-3 text-sm font-semibold sm:flex-none sm:px-4', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
                >
                  {t(`dashboard.period.${item}`)}
                </button>
              ))}
            </div>
          )}
        </div>
      </div>
      <div className="mt-3 flex flex-col gap-2 text-xs text-muted sm:flex-row sm:flex-wrap sm:items-center">
        <div className="flex flex-wrap items-center gap-2">
          <Badge tone="green">{t(`groupDash.level.${data.scope.level}`)}</Badge>
          <span>{t('dashboard.periodRange', { start: data.range.from, end: data.range.to })}</span>
        </div>
        <span className="sm:before:mr-1 sm:before:content-['·']">{t('groupDash.vsPrevious')}</span>
        {(companyId || officeId) ? (
          <button
            type="button"
            onClick={() => { selectCompany(''); setOfficeId('') }}
            className="inline-flex min-h-9 w-fit items-center gap-1 rounded-full bg-lhms-soft px-3 py-1.5 font-semibold text-lhms"
          >
            <X size={12} /> {t('groupDash.resetScope')}
          </button>
        ) : null}
      </div>
    </Card>
  )
}

export function GroupOverviewPage() {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const state = useGroupDashboard()
  const { data, failed, loading } = state

  if (failed) return <Empty>{t('common.empty')}</Empty>
  if (!data && loading) return <LoadingScreen />
  if (!data) return <Empty>{t('common.empty')}</Empty>

  const currency = data.currency
  const selectedCompany = data.scope.companies.find((item) => item.id === data.scope.company_id)
  const selectedOffice = data.scope.offices.find((item) => item.id === data.scope.office_id)
  const financeKeys = ['revenue', 'profit', 'cash', 'receivables', 'payables', 'expenses', 'assets', 'liabilities'] as const

  return (
    <div className="min-w-0 space-y-4 overflow-x-hidden sm:space-y-6">
      <Card className="overflow-hidden border-lhms/20 bg-[linear-gradient(135deg,#043711_0%,#0d6b36_55%,#1a8f4d_100%)] p-4 text-white sm:p-6">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div className="min-w-0 lg:max-w-md">
            <div className="text-[10px] font-semibold tracking-[0.22em] text-white/60 sm:text-xs">{t('groupDash.decision')}</div>
            <h2 className="mt-2 text-xl font-bold tracking-tight sm:text-2xl">{t(greetingKey())}, {user?.first_name?.trim()}</h2>
            <p className="mt-1 max-w-2xl text-sm text-white/75">
              {t('groupDash.pilotHint')}
              {selectedCompany ? ` · ${selectedCompany.name}` : ''}
              {selectedOffice ? ` · ${selectedOffice.name}` : ''}
            </p>
            <p className="mt-2 text-xs text-white/60">
              {roleLabel(t, user?.roles?.[0])}{data.welcome.company ? ` · ${data.welcome.company}` : ''}
            </p>
          </div>
          <div className="grid min-w-0 flex-1 grid-cols-2 gap-2 sm:grid-cols-[minmax(12rem,1.55fr)_minmax(12rem,1.55fr)_minmax(7rem,0.9fr)]">
            <div className="min-w-0 rounded-2xl bg-white/10 px-3 py-3 backdrop-blur sm:px-4 sm:py-3.5">
              <div className="text-[11px] text-white/70">{t('dashboard.revenue')}</div>
              <div className="mt-1.5 break-words text-base font-extrabold leading-snug tracking-tight tabular-nums sm:whitespace-nowrap sm:text-2xl sm:leading-none">
                {money(data.finances?.revenue, currency)}
              </div>
            </div>
            <div className="min-w-0 rounded-2xl bg-white/10 px-3 py-3 backdrop-blur sm:px-4 sm:py-3.5">
              <div className="text-[11px] text-white/70">{t('dashboard.profit')}</div>
              <div className="mt-1.5 break-words text-base font-extrabold leading-snug tracking-tight tabular-nums sm:whitespace-nowrap sm:text-2xl sm:leading-none">
                {money(data.finances?.profit, currency)}
              </div>
            </div>
            <div className="col-span-2 min-w-0 rounded-2xl bg-white/10 px-3 py-3 backdrop-blur sm:col-span-1 sm:px-4 sm:py-3.5">
              <div className="text-[11px] text-white/70">{t('groupDash.ops.sales')}</div>
              <div className="mt-1.5 text-base font-extrabold leading-snug tracking-tight tabular-nums sm:text-2xl sm:leading-none">
                {data.operations?.sales_count ?? 0}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <ScopeFilters {...state} data={data} />

      <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
        {[
          { key: 'companies', value: data.stats.companies, icon: Building2, label: t('dashboard.companies') },
          { key: 'offices', value: data.stats.offices, icon: MapPin, label: t('dashboard.offices') },
          { key: 'agents', value: data.stats.agents, icon: Users, label: t('groupDash.ops.agents') },
          { key: 'clients', value: data.stats.clients, icon: ClipboardList, label: t('groupDash.ops.clients') },
        ].map((item) => {
          const Icon = item.icon
          return (
            <Card key={item.key} className="min-w-0 p-3 sm:p-4">
              <div className="flex items-center justify-between gap-2">
                <span className="truncate text-xs text-muted sm:text-sm">{item.label}</span>
                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms sm:h-9 sm:w-9"><Icon size={16} /></span>
              </div>
              <div className="mt-2 text-2xl font-extrabold sm:mt-3 sm:text-3xl">{item.value}</div>
            </Card>
          )
        })}
      </div>

      {data.finances ? (
        <div className="min-w-0">
          <div className="mb-3 flex items-center gap-2">
            <CircleDollarSign size={16} className="shrink-0 text-lhms" />
            <h3 className="text-sm font-semibold sm:text-base">{t('groupDash.financeTitle')}</h3>
          </div>
          <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
            {financeKeys.map((key) => (
              <KpiCard
                key={key}
                label={t(`dashboard.${key}`)}
                value={money(data.finances?.[key], currency)}
                delta={data.finances_delta?.[key]}
                tone={key === 'expenses' || key === 'liabilities' || key === 'payables' ? 'danger' : key === 'receivables' ? 'accent' : 'default'}
              />
            ))}
          </div>
        </div>
      ) : null}

      <div className="min-w-0">
        <div className="mb-3 flex items-center gap-2">
          <TrendingUp size={16} className="shrink-0 text-lhms" />
          <h3 className="text-sm font-semibold sm:text-base">{t('groupDash.opsTitle')}</h3>
        </div>
        <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
          <KpiCard label={t('groupDash.ops.sales')} value={String(data.operations?.sales_count ?? 0)} delta={data.operations_delta?.sales_count} />
          <KpiCard label={t('groupDash.ops.soldUnits')} value={String(data.operations?.sold_units ?? 0)} delta={data.operations_delta?.sold_units} />
          <KpiCard label={t('groupDash.ops.inventoryValue')} value={money(data.operations?.inventory_value, currency)} delta={data.operations_delta?.inventory_value} />
          <KpiCard label={t('groupDash.ops.commissions')} value={money(data.operations?.commissions_payable, currency)} delta={data.operations_delta?.commissions_payable} tone="accent" />
          <KpiCard label={t('groupDash.ops.agents')} value={String(data.operations?.agents ?? 0)} />
          <KpiCard label={t('groupDash.ops.clients')} value={String(data.operations?.clients ?? 0)} />
          <KpiCard label={t('groupDash.ops.products')} value={String(data.operations?.products ?? 0)} />
          <KpiCard label={t('groupDash.ops.inventoryUnits')} value={String(data.operations?.inventory_units ?? 0)} />
        </div>
      </div>

      {data.recruitment ? (
        <Card className="p-4 sm:p-5">
          <div className="mb-1 flex items-center gap-2 text-sm font-semibold sm:text-base">
            <UserPlus size={16} className="shrink-0 text-lhms" />
            {t('groupDash.recruitmentTitle')}
          </div>
          <p className="mb-4 text-sm text-muted">{t('groupDash.recruitmentHint')}</p>
          <div className="grid grid-cols-2 gap-2 sm:gap-3 sm:grid-cols-3 xl:grid-cols-6">
            {[
              ['applications', data.recruitment.applications],
              ['interviews', data.recruitment.interviews],
              ['observations', data.recruitment.observations],
              ['approved', data.recruitment.approved],
              ['rejected', data.recruitment.rejected],
              ['conversion', `${data.recruitment.conversion_rate}%`],
            ].map(([key, value]) => (
              <div key={String(key)} className="rounded-xl bg-surface p-3">
                <div className="text-xs text-muted">{t(`groupDash.recruitment.${String(key)}`)}</div>
                <div className="mt-1 text-lg font-extrabold text-lhms sm:text-xl">{value}</div>
              </div>
            ))}
          </div>
        </Card>
      ) : null}

      <Card className="min-w-0 p-4 sm:p-5">
        <div className="mb-1 font-semibold">{t('dashboard.trend')}</div>
        <p className="mb-3 text-sm text-muted sm:mb-4">{t('groupDash.trendHint')}</p>
        <div className="h-56 min-w-0 sm:h-72 xl:h-80">
          {data.trend.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={data.trend} margin={{ top: 8, right: 4, left: 0, bottom: 0 }}>
                <defs>
                  <linearGradient id="groupRev" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#043711" stopOpacity={0.28} />
                    <stop offset="95%" stopColor="#043711" stopOpacity={0} />
                  </linearGradient>
                  <linearGradient id="groupProfit" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#c9a227" stopOpacity={0.22} />
                    <stop offset="95%" stopColor="#c9a227" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="label" tick={{ fontSize: 10 }} interval="preserveStartEnd" minTickGap={18} />
                <YAxis width={42} tick={{ fontSize: 10 }} tickFormatter={(value) => compact(Number(value))} />
                <Tooltip formatter={(value, key) => [money(Number(value), currency), t(`dashboard.${String(key)}`)]} />
                <Legend wrapperStyle={{ fontSize: 12 }} formatter={(value) => t(`dashboard.${String(value)}`)} />
                <Area type="monotone" dataKey="revenue" stroke="#043711" fill="url(#groupRev)" strokeWidth={2} />
                <Area type="monotone" dataKey="expenses" stroke="#c81e1e" fillOpacity={0} strokeWidth={2} />
                <Area type="monotone" dataKey="profit" stroke="#c9a227" fill="url(#groupProfit)" strokeWidth={2} />
              </AreaChart>
            </ResponsiveContainer>
          )}
        </div>
      </Card>

      {!data.scope.company_id ? (
        <div className="grid gap-4 xl:grid-cols-[1fr_1fr] xl:gap-6">
          <Card className="min-w-0 p-4 sm:p-5">
            <div className="mb-1 font-semibold">{t('groupDash.companyComparison')}</div>
            <p className="mb-3 text-sm text-muted sm:mb-4">{t('groupDash.companyComparisonHint')}</p>
            <div className="h-56 min-w-0 sm:h-72">
              {data.comparison.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={data.comparison} margin={{ top: 8, right: 4, left: 0, bottom: 28 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                    <XAxis dataKey="name" tick={{ fontSize: 10 }} interval={0} angle={-28} textAnchor="end" height={48} />
                    <YAxis width={42} tick={{ fontSize: 10 }} tickFormatter={(value) => compact(Number(value))} />
                    <Tooltip formatter={(value, key) => [money(Number(value), currency), t(`dashboard.${String(key)}`)]} />
                    <Legend wrapperStyle={{ fontSize: 12 }} formatter={(value) => t(`dashboard.${String(value)}`)} />
                    <Bar dataKey="revenue" fill="#043711" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="profit" fill="#c9a227" radius={[6, 6, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              )}
            </div>
          </Card>
          <Card className="min-w-0 p-4 sm:p-5">
            <div className="mb-3 flex items-center gap-2 font-semibold sm:mb-4">
              <Globe2 size={16} className="shrink-0 text-lhms" />
              {t('dashboard.map')}
            </div>
            <PresenceMap countries={data.presence ?? []} />
          </Card>
        </div>
      ) : null}

      <Card className="min-w-0 p-4 sm:p-5">
        <div className="mb-1 flex items-center justify-between gap-3">
          <div className="min-w-0">
            <div className="font-semibold">{t('dashboard.performance')}</div>
            <p className="mt-1 text-sm text-muted">{t('groupDash.performanceHint')}</p>
          </div>
        </div>
        <div className="mt-4 hidden overflow-x-auto md:block">
          <table className="w-full min-w-[720px] text-sm">
            <thead className="text-left text-muted">
              <tr>
                <th className="pb-3">{t('org.companies')}</th>
                <th className="pb-3">{t('org.offices')}</th>
                <th className="pb-3">{t('groupDash.ops.agents')}</th>
                <th className="pb-3">{t('groupDash.ops.sales')}</th>
                <th className="pb-3">{t('dashboard.revenue')}</th>
                <th className="pb-3">{t('dashboard.profit')}</th>
                <th className="pb-3">{t('dashboard.receivables')}</th>
                <th className="pb-3">{t('dashboard.payables')}</th>
                <th className="pb-3" />
              </tr>
            </thead>
            <tbody>
              {data.performance.map((row) => {
                const moduleLabel = i18n.language.startsWith('fr') ? (row.module_name_fr || row.module_name) : row.module_name
                return (
                  <tr key={row.id} className="border-t border-line">
                    <td className="py-3">
                      <div className="font-semibold">{row.name}</div>
                      <div className="text-xs text-muted">{moduleLabel}{row.country ? ` · ${row.country}` : ''}</div>
                    </td>
                    <td>{row.offices_count}</td>
                    <td>{row.agents}</td>
                    <td>{row.sales_count}</td>
                    <td>
                      <div>{money(row.revenue, currency)}</div>
                      <DeltaBadge value={row.delta_revenue} />
                    </td>
                    <td className="text-lhms">
                      <div>{money(row.profit, currency)}</div>
                      <DeltaBadge value={row.delta_profit} />
                    </td>
                    <td>{money(row.receivables, currency)}</td>
                    <td className="text-danger">{money(row.payables, currency)}</td>
                    <td>
                      <button
                        type="button"
                        onClick={() => state.selectCompany(row.id)}
                        className="inline-flex items-center gap-1 text-sm font-semibold text-lhms"
                      >
                        {t('groupDash.drillDown')} <ChevronRight size={14} />
                      </button>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
        <div className="mt-4 space-y-3 md:hidden">
          {data.performance.map((row) => {
            const moduleLabel = i18n.language.startsWith('fr') ? (row.module_name_fr || row.module_name) : row.module_name
            return (
              <button
                key={row.id}
                type="button"
                onClick={() => state.selectCompany(row.id)}
                className="w-full rounded-xl border border-line p-3.5 text-left"
              >
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0">
                    <div className="font-semibold">{row.name}</div>
                    <div className="mt-0.5 text-xs text-muted">{moduleLabel}{row.country ? ` · ${row.country}` : ''}</div>
                  </div>
                  <ChevronRight size={16} className="mt-0.5 shrink-0 text-lhms" />
                </div>
                <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                  <div>
                    <div className="text-xs text-muted">{t('dashboard.revenue')}</div>
                    <div className="font-semibold tabular-nums">{money(row.revenue, currency)}</div>
                    <DeltaBadge value={row.delta_revenue} />
                  </div>
                  <div>
                    <div className="text-xs text-muted">{t('dashboard.profit')}</div>
                    <div className="font-semibold text-lhms tabular-nums">{money(row.profit, currency)}</div>
                    <DeltaBadge value={row.delta_profit} />
                  </div>
                  <div>
                    <div className="text-xs text-muted">{t('org.offices')} / {t('groupDash.ops.sales')}</div>
                    <div className="font-semibold">{row.offices_count} · {row.sales_count}</div>
                  </div>
                  <div>
                    <div className="text-xs text-muted">{t('dashboard.receivables')}</div>
                    <div className="font-semibold tabular-nums">{money(row.receivables, currency)}</div>
                  </div>
                </div>
              </button>
            )
          })}
        </div>
      </Card>

      {data.offices_performance ? (
        <Card className="min-w-0 p-4 sm:p-5">
          <div className="mb-1 flex items-center gap-2 font-semibold">
            <Warehouse size={16} className="shrink-0 text-lhms" />
            {t('groupDash.officeBreakdown')}
          </div>
          <p className="mb-4 text-sm text-muted">{t('groupDash.officeBreakdownHint')}</p>
          {data.offices_performance.length === 0 ? <Empty>{t('dashboard.officePerformanceEmpty')}</Empty> : (
            <>
              <div className="mb-5 h-56 min-w-0 sm:h-72">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={data.offices_performance} margin={{ top: 8, right: 4, left: 0, bottom: 28 }}>
                    <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                    <XAxis dataKey="name" tick={{ fontSize: 10 }} interval={0} angle={-28} textAnchor="end" height={48} />
                    <YAxis width={42} tick={{ fontSize: 10 }} tickFormatter={(value) => compact(Number(value))} />
                    <Tooltip formatter={(value, key) => [money(Number(value), currency), t(`dashboard.${String(key)}`, { defaultValue: t(`groupDash.ops.${String(key)}`, { defaultValue: String(key) }) })]} />
                    <Legend wrapperStyle={{ fontSize: 12 }} />
                    <Bar dataKey="revenue" fill="#043711" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="profit" fill="#c9a227" radius={[6, 6, 0, 0]} />
                    <Bar dataKey="compensation" fill="#5b8a72" radius={[6, 6, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
              <div className="hidden overflow-x-auto md:block">
                <table className="w-full min-w-[680px] text-sm">
                  <thead className="text-left text-muted">
                    <tr>
                      <th className="pb-3">{t('org.offices')}</th>
                      <th className="pb-3">{t('groupDash.ops.agents')}</th>
                      <th className="pb-3">{t('groupDash.ops.sales')}</th>
                      <th className="pb-3">{t('dashboard.revenue')}</th>
                      <th className="pb-3">{t('dashboard.profit')}</th>
                      <th className="pb-3">{t('dashboard.cash')}</th>
                      <th className="pb-3">{t('groupDash.ops.inventoryValue')}</th>
                      <th className="pb-3" />
                    </tr>
                  </thead>
                  <tbody>
                    {data.offices_performance.map((row) => (
                      <tr key={row.id} className="border-t border-line">
                        <td className="py-3 font-semibold">{row.name}</td>
                        <td>{row.agents}</td>
                        <td>{row.sales_count}</td>
                        <td>{money(row.revenue, currency)}</td>
                        <td className="text-lhms">{money(row.profit, currency)}</td>
                        <td>{money(row.cash, currency)}</td>
                        <td>{money(row.inventory_value, currency)}</td>
                        <td>
                          <button
                            type="button"
                            onClick={() => state.setOfficeId(row.id)}
                            className="inline-flex items-center gap-1 text-sm font-semibold text-lhms"
                          >
                            {t('groupDash.drillDown')} <ChevronRight size={14} />
                          </button>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="space-y-3 md:hidden">
                {data.offices_performance.map((row) => (
                  <button
                    key={row.id}
                    type="button"
                    onClick={() => state.setOfficeId(row.id)}
                    className="w-full rounded-xl border border-line p-3.5 text-left"
                  >
                    <div className="flex items-start justify-between gap-2">
                      <div className="font-semibold">{row.name}</div>
                      <ChevronRight size={16} className="mt-0.5 shrink-0 text-lhms" />
                    </div>
                    <div className="mt-3 grid grid-cols-2 gap-2 text-sm">
                      <div>
                        <div className="text-xs text-muted">{t('dashboard.revenue')}</div>
                        <div className="font-semibold tabular-nums">{money(row.revenue, currency)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted">{t('dashboard.profit')}</div>
                        <div className="font-semibold text-lhms tabular-nums">{money(row.profit, currency)}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted">{t('groupDash.ops.agents')} / {t('groupDash.ops.sales')}</div>
                        <div className="font-semibold">{row.agents} · {row.sales_count}</div>
                      </div>
                      <div>
                        <div className="text-xs text-muted">{t('dashboard.cash')}</div>
                        <div className="font-semibold tabular-nums">{money(row.cash, currency)}</div>
                      </div>
                    </div>
                  </button>
                ))}
              </div>
            </>
          )}
        </Card>
      ) : null}

      {data.kams_savings ? (
        <Card className="min-w-0 p-4 sm:p-5">
          <div className="mb-1 font-semibold">{t('dashboard.kamsSavings')}</div>
          <p className="mb-4 text-sm text-muted">{t('dashboard.kamsSavingsHint')}</p>
          <div className="grid gap-2 sm:grid-cols-2 sm:gap-3 xl:grid-cols-3">
            {[
              ['guy_savings', data.kams_savings.guy_savings],
              ['assistant_manager_savings', data.kams_savings.assistant_manager_savings],
              ['manager_savings', data.kams_savings.manager_savings],
            ].map(([key, value]) => (
              <div key={String(key)} className="rounded-xl bg-surface p-3 sm:p-4">
                <div className="text-xs text-muted sm:text-sm">{t(`dashboard.${String(key)}`)}</div>
                <div className="mt-2 break-words text-lg font-extrabold tabular-nums text-lhms sm:text-xl">{money(Number(value), currency)}</div>
              </div>
            ))}
          </div>
          <div className="mt-4">
            <Link to="/modules/kams/finance/accounts" className="inline-flex min-h-10 items-center gap-1 text-sm font-semibold text-lhms">
              {t('dashboard.viewKamsAccounts')} <ArrowUpRight size={14} />
            </Link>
          </div>
        </Card>
      ) : null}

      {data.kams_compensation ? (
        <Card className="min-w-0 p-4 sm:p-5">
          <div className="mb-1 font-semibold">{t('dashboard.kamsCompensation')}</div>
          <p className="mb-4 text-sm text-muted">{t('dashboard.kamsCompensationHint')}</p>
          <div className="grid grid-cols-2 gap-2 sm:gap-3 xl:grid-cols-4">
            {[
              ['generated', data.kams_compensation.generated],
              ['manager_sale', data.kams_compensation.manager_sale],
              ['assistant_share', data.kams_compensation.assistant_share],
              ['manager_office', data.kams_compensation.manager_office],
              ['divisionnaire_office', data.kams_compensation.divisionnaire_office],
              ['secretary_office', data.kams_compensation.secretary_office + data.kams_compensation.salary_paid],
              ['posted', data.kams_compensation.posted],
              ['remaining', data.kams_compensation.remaining],
            ].map(([key, value]) => (
              <div key={String(key)} className="min-w-0 rounded-xl bg-surface p-3 sm:p-4">
                <div className="text-xs text-muted sm:text-sm">{t(`dashboard.compensation.${String(key)}`)}</div>
                <div className="mt-2 break-words text-lg font-extrabold tabular-nums text-lhms sm:text-xl">{money(Number(value), currency)}</div>
              </div>
            ))}
          </div>
          <div className="mt-4">
            <Link to="/administration/settings/finance" className="inline-flex min-h-10 items-center gap-1 text-sm font-semibold text-lhms">
              {t('dashboard.viewKamsRevenue')} <ArrowUpRight size={14} />
            </Link>
          </div>
        </Card>
      ) : null}

      {!data.scope.company_id ? <ModuleOfficePerformance /> : null}

      {loading ? (
        <div className="text-center text-sm text-muted">{t('common.loading')}</div>
      ) : null}
    </div>
  )
}

const moduleKpis: Record<string, string[]> = {
  'heavy-equipment': ['fleet', 'rentals', 'maintenance', 'utilization'],
  'real-estate': ['properties', 'occupancy', 'leases', 'rent'],
  'rice-mills': ['production', 'stock', 'shipments', 'yield'],
  'lali-tv': ['programs', 'audience', 'ads', 'broadcast'],
}

export function GroupModuleDashboardPage() {
  const { t, i18n } = useTranslation()
  const { slug } = useParams()
  const { user, hasPermission } = useAuth()
  const { modules } = useSystemModules()
  const module = modules.find((entry) => entry.slug === slug)

  if (!slug) return <Empty>{t('common.empty')}</Empty>
  if (!module) return <LoadingScreen />
  if (!canAccessModule(user, slug)) return <Empty>{t('modules.noAccess')}</Empty>

  if (slug === 'kams') {
    if (!hasPermission('kams.dashboard.view')) {
      return <Empty>{t('groupDash.noAccess')}</Empty>
    }

    return (
      <div className="space-y-5">
        <div className="flex justify-end">
          <Link to="/modules/kams" className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-lhms px-4 text-sm font-semibold text-white">
            {t('modules.open')} <ArrowUpRight size={16} />
          </Link>
        </div>
        <ModuleOfficePerformance slug="kams" />
        <KamsDashboard />
      </div>
    )
  }

  const keys = moduleKpis[slug] ?? ['items', 'active', 'pending', 'alerts']
  const details = (i18n.language.startsWith('fr') ? module.description_fr : module.description) || t('groupDash.moduleHint')

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-3">
        <Badge tone={module.status === 'active' ? 'green' : module.status === 'inactive' ? 'red' : 'amber'}>
          {t(`modules.${module.status}`)}
        </Badge>
        <span className="text-sm text-muted">v{module.version}</span>
      </div>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {keys.map((key) => (
          <Card key={key} className="p-5">
            <div className="text-sm text-muted">{t(`moduleDash.${slug}.${key}`, { defaultValue: t(`moduleDash.generic.${key}`, { defaultValue: key }) })}</div>
            <div className="mt-3 text-3xl font-extrabold">0</div>
          </Card>
        ))}
      </div>
      <Card className="p-5">
        <div className="mb-2 font-semibold">{t('groupDash.details')}</div>
        <p className="text-sm text-muted">{details}</p>
        <p className="mt-3 text-sm text-muted">{t('groupDash.modulePending')}</p>
      </Card>
      <ModuleOfficePerformance slug={slug} />
    </div>
  )
}
