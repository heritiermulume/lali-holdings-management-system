import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { intlLocale } from '../../i18n/locales'
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
import { useLiveData } from '../../hooks/useLiveData'
import { usePayloadOfficeFilter } from '../../hooks/useOfficeScope'
import { cn } from '../../lib/cn'
import api from '../../lib/api'

type Period = 'day' | 'week' | 'month' | 'custom'

function isoDate(value: Date) {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}
type CountRow = { key: string; count: number }
type SeriesPoint = {
  key: string
  from: string
  to: string
  applications: number
  interviews: number
  observations: number
  approved: number
  rejected: number
  on_hold: number
  abandoned: number
  new_members: number
}
type PromotionEligibility = {
  chef: number
  assistant_manager: number
  criteria?: {
    chef?: { daily_units?: number; consecutive_days?: number }
    assistant_manager?: {
      team_size?: number
      chef_coachees?: number
      daily_units?: number
      consecutive_days?: number
    }
  }
}

type RecapData = {
  group_by: Period
  range: { from: string; to: string }
  totals: {
    applications: number
    interviews: number
    observations: number
    approved: number
    rejected: number
    on_hold: number
    abandoned: number
    new_members: number
    conversion_rate: number
    interview_attendance: number
    observation_attendance: number
  }
  series: SeriesPoint[]
  pipeline: CountRow[]
  interview_results: CountRow[]
  observation_decisions: CountRow[]
  sources: CountRow[]
  promotion_eligibility?: PromotionEligibility
}

const COLORS = ['#043711', '#2d8a5a', '#c9a227', '#1d4ed8', '#c81e1e', '#7c3aed', '#0f766e']

function parseDate(value: string) {
  return new Date(`${value}T12:00:00`)
}

function formatDay(value: string, locale: string) {
  return parseDate(value).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
}

export function KamsRecruitmentDashboard() {
  const { t, i18n } = useTranslation()
  const locale = intlLocale(i18n.language)
  const today = isoDate(new Date())
  const [period, setPeriod] = useState<Period>('week')
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const { officeId, setOfficeId, syncFromPayload } = usePayloadOfficeFilter()
  const [data, setData] = useState<(RecapData & {
    can_filter_offices?: boolean
    office_id?: number | null
    filter_offices?: Array<{ id: number; name: string; code?: string }>
  }) | null>(null)

  const load = () => {
    if (period === 'custom' && (!from || !to)) return
    return api.get('/modules/kams/recruitment/dashboard', {
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
    { key: 'applications', value: data.totals.applications },
    { key: 'interviews', value: data.totals.interviews },
    { key: 'observations', value: data.totals.observations },
    { key: 'approved', value: data.totals.approved },
    { key: 'rejected', value: data.totals.rejected },
    { key: 'conversion', value: `${data.totals.conversion_rate}%`, accent: true },
  ]

  const funnel = [
    { key: 'applications', value: data.totals.applications },
    { key: 'interviews', value: data.totals.interviews },
    { key: 'observations', value: data.totals.observations },
    { key: 'approved', value: data.totals.approved },
  ]
  const funnelMax = Math.max(...funnel.map((item) => item.value), 1)

  const statusLabel = (key: string) => t(`kams.status.${key}`, { defaultValue: key })
  const sourceLabel = (key: string) => t(`kams.source.${key}`, { defaultValue: key })

  return (
    <div className="space-y-5">
      <div className="flex w-full flex-col gap-2">
        <p className="min-w-0 truncate text-xs text-muted whitespace-nowrap">
          {t('kams.periodRange', {
            start: parseDate(data.range.from).toLocaleDateString(locale),
            end: parseDate(data.range.to).toLocaleDateString(locale),
          })}
        </p>
        <div className="flex w-full min-w-0 items-center justify-between gap-2">
          {data.can_filter_offices ? (
            <OfficeFilterSelect
              className="!w-auto min-w-[7.5rem] max-w-[12rem] shrink-0"
              value={officeId}
              offices={data.filter_offices || []}
              onChange={(e) => setOfficeId(e.target.value)}
            />
          ) : <span />}
          <div className="inline-flex w-fit max-w-full shrink-0 overflow-x-auto rounded-full bg-white p-1">
            {(['day', 'week', 'month'] as const).map((item) => (
              <button
                key={item}
                type="button"
                onClick={() => setPeriod(item)}
                className={cn('min-h-10 shrink-0 rounded-full px-4 text-sm font-semibold whitespace-nowrap', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
              >
                {t(`kams.period.${item}`)}
              </button>
            ))}
            <button
              type="button"
              onClick={() => setPeriod('custom')}
              className={cn('min-h-10 shrink-0 rounded-full px-4 text-sm font-semibold whitespace-nowrap', period === 'custom' ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
            >
              {t('dashboard.customPeriod')}
            </button>
          </div>
        </div>
        {period === 'custom' ? (
          <div className="ml-auto grid w-full max-w-80 grid-cols-2 gap-2">
            <Field className="min-w-0 max-w-full" label={t('dashboard.dateFrom')} type="date" value={from} max={to || today} onChange={(event) => setFrom(event.target.value)} />
            <Field className="min-w-0 max-w-full" label={t('dashboard.dateTo')} type="date" value={to} min={from} max={today} onChange={(event) => setTo(event.target.value)} />
          </div>
        ) : null}
      </div>

      <div className="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-3">
        {kpis.map((item) => (
          <Card key={item.key} className="min-w-0 p-4 sm:p-5">
            <div className="text-xs text-muted sm:text-sm">{t(`kams.${item.key}`)}</div>
            <div className={cn('mt-2 text-2xl font-extrabold sm:mt-3 sm:text-3xl', item.accent && 'text-lhms')}>{item.value}</div>
          </Card>
        ))}
      </div>

      <div className="space-y-3">
        <div>
          <div className="font-semibold">{t('kams.promotionEligibility')}</div>
          <p className="mt-1 text-sm text-muted">{t('kams.promotionEligibilityHint')}</p>
        </div>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 sm:gap-4">
          <Card className="min-w-0 p-4 sm:p-5">
            <div className="text-xs text-muted sm:text-sm">{t('kams.eligibleForChef')}</div>
            <div className="mt-2 text-2xl font-extrabold text-lhms sm:mt-3 sm:text-3xl">
              {data.promotion_eligibility?.chef ?? 0}
            </div>
            <p className="mt-2 text-xs text-muted">
              {t('kams.eligibleForChefCriteria', {
                units: data.promotion_eligibility?.criteria?.chef?.daily_units ?? 15,
                days: data.promotion_eligibility?.criteria?.chef?.consecutive_days ?? 3,
              })}
            </p>
          </Card>
          <Card className="min-w-0 p-4 sm:p-5">
            <div className="text-xs text-muted sm:text-sm">{t('kams.eligibleForAssistantManager')}</div>
            <div className="mt-2 text-2xl font-extrabold text-lhms sm:mt-3 sm:text-3xl">
              {data.promotion_eligibility?.assistant_manager ?? 0}
            </div>
            <p className="mt-2 text-xs text-muted">
              {t('kams.eligibleForAssistantManagerCriteria', {
                team: data.promotion_eligibility?.criteria?.assistant_manager?.team_size ?? 15,
                chefs: data.promotion_eligibility?.criteria?.assistant_manager?.chef_coachees ?? 5,
                units: data.promotion_eligibility?.criteria?.assistant_manager?.daily_units ?? 150,
                days: data.promotion_eligibility?.criteria?.assistant_manager?.consecutive_days ?? 3,
              })}
            </p>
          </Card>
        </div>
      </div>

      <Card className="p-4 sm:p-5">
        <div className="mb-4 font-semibold">{t('kams.activityTrend')}</div>
        <div className="h-64 sm:h-72">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData}>
              <defs>
                <linearGradient id="recap-apps" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#043711" stopOpacity={0.28} />
                  <stop offset="95%" stopColor="#043711" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 11 }} />
              <YAxis width={36} allowDecimals={false} tick={{ fontSize: 11 }} />
              <Tooltip />
              <Legend />
              <Area type="monotone" dataKey="applications" name={t('kams.applications')} stroke="#043711" fill="url(#recap-apps)" />
              <Area type="monotone" dataKey="interviews" name={t('kams.interviews')} stroke="#2d8a5a" fillOpacity={0} />
              <Area type="monotone" dataKey="observations" name={t('kams.observations')} stroke="#c9a227" fillOpacity={0} />
            </AreaChart>
          </ResponsiveContainer>
        </div>
      </Card>

      <div className="grid gap-6 xl:grid-cols-2">
        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('kams.outcomes')}</div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <BarChart data={chartData}>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
                <Tooltip />
                <Legend />
                <Bar dataKey="approved" name={t('kams.approved')} fill="#043711" radius={[6, 6, 0, 0]} />
                <Bar dataKey="rejected" name={t('kams.rejected')} fill="#c81e1e" radius={[6, 6, 0, 0]} />
                <Bar dataKey="abandoned" name={t('kams.status.abandoned')} fill="#64748b" radius={[6, 6, 0, 0]} />
              </BarChart>
            </ResponsiveContainer>
          </div>
        </Card>

        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('kams.funnel')}</div>
          <div className="space-y-3">
            {funnel.map((item) => (
              <div key={item.key}>
                <div className="mb-1 flex items-center justify-between text-sm">
                  <span className="text-muted">{t(`kams.${item.key}`)}</span>
                  <span className="font-semibold">{item.value}</span>
                </div>
                <div className="h-3 overflow-hidden rounded-full bg-surface">
                  <div className="h-full rounded-full bg-lhms" style={{ width: `${Math.max((item.value / funnelMax) * 100, item.value ? 6 : 0)}%` }} />
                </div>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <div className="grid gap-6 xl:grid-cols-3">
        <BreakdownCard
          title={t('kams.pipelineNow')}
          rows={data.pipeline.filter((row) => row.key !== 'on_hold')}
          label={statusLabel}
          empty={t('common.empty')}
        />
        <PieCard
          title={t('kams.interviewResults')}
          rows={data.interview_results.filter((row) => row.key !== 'on_hold')}
          label={statusLabel}
          empty={t('common.empty')}
        />
        <PieCard
          title={t('kams.observationDecisions')}
          rows={data.observation_decisions.filter((row) => row.key !== 'on_hold')}
          label={statusLabel}
          empty={t('common.empty')}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-2">
        <PieCard
          title={t('kams.sourcesBreakdown')}
          rows={data.sources}
          label={sourceLabel}
          empty={t('common.empty')}
        />
      </div>
    </div>
  )
}

function BreakdownCard({
  title,
  rows,
  label,
  empty,
}: {
  title: string
  rows: CountRow[]
  label: (key: string) => string
  empty: string
}) {
  const total = rows.reduce((sum, row) => sum + row.count, 0) || 1
  return (
    <Card className="p-5">
      <div className="mb-4 font-semibold">{title}</div>
      {rows.length === 0 ? <Empty>{empty}</Empty> : (
        <div className="space-y-3">
          {rows.map((row) => (
            <div key={row.key}>
              <div className="mb-1 flex items-center justify-between text-sm">
                <span className="text-muted">{label(row.key)}</span>
                <span className="font-semibold">{row.count}</span>
              </div>
              <div className="h-2 overflow-hidden rounded-full bg-surface">
                <div className="h-full rounded-full bg-lhms" style={{ width: `${(row.count / total) * 100}%` }} />
              </div>
            </div>
          ))}
        </div>
      )}
    </Card>
  )
}

function PieCard({
  title,
  rows,
  label,
  empty,
}: {
  title: string
  rows: CountRow[]
  label: (key: string) => string
  empty: string
}) {
  const chartRows = rows.map((row) => ({ ...row, name: label(row.key) }))
  return (
    <Card className="p-5">
      <div className="mb-4 font-semibold">{title}</div>
      {chartRows.length === 0 ? <Empty>{empty}</Empty> : (
        <div className="h-64">
          <ResponsiveContainer width="100%" height="100%">
            <PieChart>
              <Pie data={chartRows} dataKey="count" nameKey="name" innerRadius={48} outerRadius={80} paddingAngle={2}>
                {chartRows.map((row, index) => (
                  <Cell key={row.key} fill={COLORS[index % COLORS.length]} />
                ))}
              </Pie>
              <Tooltip />
              <Legend />
            </PieChart>
          </ResponsiveContainer>
        </div>
      )}
    </Card>
  )
}
