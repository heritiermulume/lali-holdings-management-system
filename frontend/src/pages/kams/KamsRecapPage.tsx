import { CalendarCheck, ClipboardCheck, UserCheck, UserPlus, UserX, Users } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
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
import { Card, Empty } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { cn } from '../../lib/cn'
import api from '../../lib/api'

type Period = 'day' | 'week' | 'month'
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
  members: Record<string, number>
}

const COLORS = ['#094924', '#2d8a5a', '#c9a227', '#1d4ed8', '#c81e1e', '#7c3aed', '#0f766e']

function parseDate(value: string) {
  return new Date(`${value}T12:00:00`)
}

function formatDay(value: string, locale: string) {
  return parseDate(value).toLocaleDateString(locale, { day: 'numeric', month: 'short' })
}

export function KamsRecruitmentDashboard() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const [period, setPeriod] = useState<Period>('week')
  const [data, setData] = useState<RecapData | null>(null)

  useEffect(() => {
    let active = true
    api.get('/modules/kams/recruitment/dashboard', { params: { group_by: period } })
      .then((res) => {
        if (active) setData(res.data.data)
      })
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
    { key: 'applications', value: data.totals.applications, icon: UserPlus },
    { key: 'interviews', value: data.totals.interviews, icon: ClipboardCheck },
    { key: 'observations', value: data.totals.observations, icon: CalendarCheck },
    { key: 'approved', value: data.totals.approved, icon: UserCheck },
    { key: 'rejected', value: data.totals.rejected, icon: UserX },
    { key: 'newMembers', value: data.totals.new_members, icon: Users },
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
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-lg font-bold">{t('kams.statistics')}</h2>
          <p className="mt-1 text-sm text-muted">{t('kams.recapHint')}</p>
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
              className={cn(
                'min-h-10 rounded-full px-4 text-sm font-semibold',
                period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink',
              )}
            >
              {t(`kams.period.${item}`)}
            </button>
          ))}
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
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

      <div className="grid gap-3 sm:grid-cols-3">
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.conversion')}</div>
          <div className="mt-2 text-2xl font-extrabold text-lhms">{data.totals.conversion_rate}%</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.interviewAttendance')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.totals.interview_attendance}%</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.observationAttendance')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.totals.observation_attendance}%</div>
        </Card>
      </div>

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('kams.activityTrend')}</div>
        <div className="h-80">
          <ResponsiveContainer width="100%" height="100%">
            <AreaChart data={chartData}>
              <defs>
                <linearGradient id="recap-apps" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%" stopColor="#094924" stopOpacity={0.28} />
                  <stop offset="95%" stopColor="#094924" stopOpacity={0} />
                </linearGradient>
              </defs>
              <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
              <XAxis dataKey="label" tick={{ fontSize: 12 }} />
              <YAxis allowDecimals={false} tick={{ fontSize: 12 }} />
              <Tooltip />
              <Legend />
              <Area type="monotone" dataKey="applications" name={t('kams.applications')} stroke="#094924" fill="url(#recap-apps)" />
              <Area type="monotone" dataKey="interviews" name={t('kams.interviews')} stroke="#2d8a5a" fillOpacity={0} />
              <Area type="monotone" dataKey="observations" name={t('kams.observations')} stroke="#c9a227" fillOpacity={0} />
              <Area type="monotone" dataKey="new_members" name={t('kams.newMembers')} stroke="#1d4ed8" fillOpacity={0} />
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
                <Bar dataKey="approved" name={t('kams.approved')} fill="#094924" radius={[6, 6, 0, 0]} />
                <Bar dataKey="rejected" name={t('kams.rejected')} fill="#c81e1e" radius={[6, 6, 0, 0]} />
                <Bar dataKey="on_hold" name={t('kams.onHold')} fill="#c9a227" radius={[6, 6, 0, 0]} />
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
          rows={data.pipeline}
          label={statusLabel}
          empty={t('common.empty')}
        />
        <PieCard
          title={t('kams.interviewResults')}
          rows={data.interview_results}
          label={statusLabel}
          empty={t('common.empty')}
        />
        <PieCard
          title={t('kams.observationDecisions')}
          rows={data.observation_decisions}
          label={statusLabel}
          empty={t('common.empty')}
        />
      </div>

      <div className="grid gap-6 xl:grid-cols-[1.1fr_0.9fr]">
        <PieCard
          title={t('kams.sourcesBreakdown')}
          rows={data.sources}
          label={sourceLabel}
          empty={t('common.empty')}
        />
        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('kams.membersSnapshot')}</div>
          <div className="grid gap-3 sm:grid-cols-2">
            {[
              ['total', data.members.total],
              ['newMembers', data.members.new_recruit],
              ['migratedMembers', data.members.migrated],
              ['activeMembers', data.members.active],
              ['verified', data.members.verified],
              ['pendingVerification', data.members.pending_verification],
            ].map(([key, value]) => (
              <div key={key} className="rounded-xl bg-surface p-4">
                <div className="text-sm text-muted">{t(`kams.${key}`)}</div>
                <div className="mt-2 text-2xl font-extrabold">{value}</div>
              </div>
            ))}
          </div>
        </Card>
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
