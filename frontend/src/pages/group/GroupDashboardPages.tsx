import { ArrowUpRight, Building2, Globe2, MapPin, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet, useLocation, useParams } from 'react-router-dom'
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Badge, Card, Empty } from '../../components/ui'
import { PresenceMap, type PresenceCountry } from '../../components/PresenceMap'
import { useAuth } from '../../context/AuthContext'
import { useSystemModules } from '../../context/ModulesContext'
import { canAccessModule } from '../../lib/access'
import { greetingKey, money, roleLabel } from '../../lib/format'
import api from '../../lib/api'
import { KamsWorkspaceTabs } from '../kams/KamsWorkspaceTabs'
import { KamsDashboard } from '../kams/KamsPages'
import { ModuleOfficePerformance } from './ModuleOfficePerformance'
import type { SystemModule } from '../../types'

export function GroupDashboardWorkspace() {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const nested = /^\/administration\/dashboard\/(?!actions(?:\/|$))[^/]+/.test(pathname)

  if (nested) return <Outlet />

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/administration/dashboard', end: true, label: t('groupDash.overview') },
          { to: '/administration/dashboard/actions', label: t('groupDash.actions') },
        ]}
      />
      <Outlet />
    </div>
  )
}

type DashboardData = {
  welcome: { name: string; role?: string; company?: string }
  stats: Record<string, number>
  finances?: Record<string, number> | null
  modules: SystemModule[]
  notifications: Array<{ id: number; title: string; body: string; type: string; read_at?: string | null }>
  tasks: Array<{ id: number; title: string; priority: string; due_date?: string | null; status: string }>
  activity: Array<{ id: number; action: string; auditable_type: string; created_at: string; user?: { name: string } }>
  performance: Array<{ id: number; name: string; code: string; offices_count: number; revenue: number; profit: number }>
  presence?: PresenceCountry[]
  trend: Array<{ month: string; revenue: number; expenses: number }>
}

function useGroupDashboard() {
  const [data, setData] = useState<DashboardData | null>(null)

  useEffect(() => {
    api.get('/dashboard').then((res) => setData(res.data.data)).catch(() => undefined)
  }, [])

  return { data }
}

export function GroupOverviewPage() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { data } = useGroupDashboard()

  if (!data) return <p className="text-sm text-muted">{t('common.loading')}</p>

  const unread = data.notifications.filter((item) => !item.read_at).length
  const openTasks = data.tasks.length
  const pendingModules = data.modules.filter((module) => module.status !== 'active').length

  const statCards = [
    { key: 'countries', value: data.stats.countries, icon: Globe2 },
    { key: 'companies', value: data.stats.companies, icon: Building2 },
    { key: 'offices', value: data.stats.offices, icon: MapPin },
    { key: 'users', value: data.stats.users, icon: Users },
  ]

  return (
    <div className="space-y-6">
      <Card className="border-lhms/20 bg-lhms p-5 text-white">
        <div className="text-xs font-semibold tracking-[0.22em] text-white/60">{t('groupDash.decision')}</div>
        <h2 className="mt-2 text-xl font-bold">{t(greetingKey())}, {user?.first_name?.trim()}</h2>
        <p className="mt-1 text-sm text-white/75">
          {roleLabel(t, user?.roles?.[0])}{data.welcome.company ? ` · ${data.welcome.company}` : ''} · {t('dashboard.overview')}
        </p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <div className="rounded-2xl bg-white/10 p-4">
            <div className="text-xs text-white/70">{t('dashboard.alerts')}</div>
            <div className="mt-1 text-2xl font-extrabold">{unread}</div>
          </div>
          <div className="rounded-2xl bg-white/10 p-4">
            <div className="text-xs text-white/70">{t('dashboard.tasks')}</div>
            <div className="mt-1 text-2xl font-extrabold">{openTasks}</div>
          </div>
          <div className="rounded-2xl bg-white/10 p-4">
            <div className="text-xs text-white/70">{t('groupDash.watch')}</div>
            <div className="mt-1 text-2xl font-extrabold">{pendingModules}</div>
          </div>
        </div>
      </Card>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {statCards.map((item) => {
          const Icon = item.icon
          return (
            <Card key={item.key} className="p-5">
              <div className="flex items-center justify-between">
                <span className="text-sm text-muted">{t(`dashboard.${item.key}`)}</span>
                <span className="grid h-10 w-10 place-items-center rounded-xl bg-lhms-soft text-lhms"><Icon size={18} /></span>
              </div>
              <div className="mt-4 text-3xl font-extrabold">{item.value}</div>
            </Card>
          )
        })}
      </div>

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('dashboard.map')}</div>
        <PresenceMap countries={data.presence ?? []} />
      </Card>

      {data.finances ? (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {['revenue', 'expenses', 'profit', 'cash', 'assets', 'liabilities', 'receivables', 'payables'].map((key) => (
            <Card key={key} className="p-5">
              <div className="text-sm text-muted">{t(`dashboard.${key}`)}</div>
              <div className={`mt-3 text-2xl font-bold ${key === 'expenses' || key === 'liabilities' || key === 'payables' ? 'text-danger' : 'text-lhms'}`}>
                {money(data.finances?.[key])}
              </div>
            </Card>
          ))}
        </div>
      ) : null}

      <ModuleOfficePerformance />

      <div className="grid gap-6 xl:grid-cols-[1.4fr_0.8fr]">
        <Card className="p-5">
          <div className="mb-4 font-semibold">{t('dashboard.trend')}</div>
          <div className="h-72">
            <ResponsiveContainer width="100%" height="100%">
              <AreaChart data={data.trend}>
                <defs>
                  <linearGradient id="rev" x1="0" y1="0" x2="0" y2="1">
                    <stop offset="5%" stopColor="#094924" stopOpacity={0.25} />
                    <stop offset="95%" stopColor="#094924" stopOpacity={0} />
                  </linearGradient>
                </defs>
                <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                <XAxis dataKey="month" />
                <YAxis />
                <Tooltip />
                <Area type="monotone" dataKey="revenue" stroke="#094924" fill="url(#rev)" />
                <Area type="monotone" dataKey="expenses" stroke="#c81e1e" fillOpacity={0} />
              </AreaChart>
            </ResponsiveContainer>
          </div>
        </Card>
        <Card className="p-5">
          <div className="mb-4 flex items-center justify-between">
            <div className="font-semibold">{t('groupDash.attention')}</div>
            <Link to="/administration/dashboard/actions" className="text-sm font-semibold text-lhms">{t('common.view')}</Link>
          </div>
          <div className="space-y-3">
            {data.notifications.slice(0, 4).length === 0 ? <Empty>{t('notifications.empty')}</Empty> : data.notifications.slice(0, 4).map((item) => (
              <div key={item.id} className="rounded-xl bg-surface p-3">
                <div className="flex items-center justify-between gap-2">
                  <div className="font-semibold">{item.title}</div>
                  <Badge tone={item.type === 'alert' ? 'red' : 'green'}>{item.type}</Badge>
                </div>
                <p className="mt-1 text-sm text-muted">{item.body}</p>
              </div>
            ))}
          </div>
        </Card>
      </div>

      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('dashboard.performance')}</div>
        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-sm">
            <thead className="text-left text-muted">
              <tr>
                <th className="pb-3">{t('org.companies')}</th>
                <th className="pb-3">{t('org.offices')}</th>
                <th className="pb-3">{t('dashboard.revenue')}</th>
                <th className="pb-3">{t('dashboard.profit')}</th>
              </tr>
            </thead>
            <tbody>
              {data.performance.map((row) => (
                <tr key={row.id} className="border-t border-line">
                  <td className="py-3 font-semibold">{row.name}</td>
                  <td>{row.offices_count}</td>
                  <td>{money(row.revenue)}</td>
                  <td className="text-lhms">{money(row.profit)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="space-y-3 md:hidden">
          {data.performance.map((row) => (
            <div key={row.id} className="rounded-xl border border-line p-4">
              <div className="font-semibold">{row.name}</div>
              <div className="mt-2 text-sm text-muted">{t('dashboard.revenue')}: {money(row.revenue)}</div>
              <div className="text-sm text-lhms">{t('dashboard.profit')}: {money(row.profit)}</div>
            </div>
          ))}
        </div>
      </Card>
    </div>
  )
}

export function GroupActionsPage() {
  const { t } = useTranslation()
  const { data } = useGroupDashboard()

  if (!data) return <p className="text-sm text-muted">{t('common.loading')}</p>

  return (
    <div className="grid gap-6 xl:grid-cols-3">
      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('dashboard.alerts')}</div>
        <div className="space-y-3">
          {data.notifications.length === 0 ? <Empty>{t('notifications.empty')}</Empty> : data.notifications.map((item) => (
            <div key={item.id} className="rounded-xl bg-surface p-3">
              <div className="flex items-center justify-between gap-2">
                <div className="font-semibold">{item.title}</div>
                <Badge tone={item.type === 'alert' ? 'red' : 'green'}>{item.type}</Badge>
              </div>
              <p className="mt-1 text-sm text-muted">{item.body}</p>
            </div>
          ))}
        </div>
      </Card>
      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('dashboard.tasks')}</div>
        <div className="space-y-3">
          {data.tasks.length === 0 ? <Empty>{t('common.empty')}</Empty> : data.tasks.map((task) => (
            <div key={task.id} className="rounded-xl bg-surface p-3">
              <div className="font-medium">{task.title}</div>
              <div className="mt-1 text-xs text-muted">{task.due_date} · {task.priority}</div>
            </div>
          ))}
        </div>
      </Card>
      <Card className="p-5">
        <div className="mb-4 font-semibold">{t('dashboard.activity')}</div>
        <div className="space-y-3">
          {data.activity.map((item) => (
            <div key={item.id} className="text-sm">
              <span className="font-semibold">{item.user?.name || t('common.system')}</span> → {item.action} → {item.auditable_type.split('\\').pop()}
              <div className="text-xs text-muted">{new Date(item.created_at).toLocaleString()}</div>
            </div>
          ))}
        </div>
      </Card>
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
  if (!module) return <p className="text-sm text-muted">{t('common.loading')}</p>
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
