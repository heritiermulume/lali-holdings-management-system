import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Bar, BarChart, CartesianGrid, Legend, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts'
import { Card, Empty, Field } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { compact, money } from '../../lib/format'
import { canAccessModule } from '../../lib/access'
import { businessModuleIcon } from '../../modules/registry'

type Period = 'day' | 'week' | 'month' | 'custom'

type OfficePoint = {
  id: number
  name: string
  code: string
  revenue: number
  profit: number
  cash: number
  compensation?: number
  manager_sale?: number
  assistant_share?: number
  manager_office_share?: number
  divisionnaire_share?: number
  secretary_share?: number
  guy_savings?: number
  assistant_manager_savings?: number
  manager_savings?: number
}

type CompensationSummary = {
  generated: number
  posted: number
  remaining: number
  manager_sale: number
  assistant_share: number
  manager_office: number
  divisionnaire_office: number
  secretary_office: number
  salary_paid: number
}

type SavingsSummary = {
  guy_savings: number
  assistant_manager_savings: number
  manager_savings: number
}

type ModulePerformance = {
  slug: string
  name: string
  name_fr: string
  offices: OfficePoint[]
  compensation?: CompensationSummary | null
  savings?: SavingsSummary | null
}

type Payload = {
  group_by: Period
  range: { from: string; to: string }
  currency: string
  modules: ModulePerformance[]
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

export function ModuleOfficePerformance({ slug }: { slug?: string }) {
  const { t, i18n } = useTranslation()
  const { user } = useAuth()
  const initial = useMemo(() => presetRange('week'), [])
  const today = useMemo(() => isoDate(new Date()), [])
  const [period, setPeriod] = useState<Period>('week')
  const [lastPreset, setLastPreset] = useState<Exclude<Period, 'custom'>>('week')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const [data, setData] = useState<Payload | null>(null)

  useEffect(() => {
    if (!from || !to) return
    let active = true
    api.get('/dashboard/module-performance', {
      params: { group_by: period, from, to, module: slug || undefined },
    }).then((res) => {
      if (active) setData(res.data.data)
    }).catch(() => {
      if (active) setData({ group_by: period, range: { from, to }, currency: 'USD', modules: [] })
    })
    return () => { active = false }
  }, [period, from, to, slug])

  const applyPreset = (item: Exclude<Period, 'custom'>) => {
    const next = presetRange(item)
    setLastPreset(item)
    setPeriod(item)
    setFrom(next.from)
    setTo(next.to)
  }

  if (!data) return <LoadingScreen />

  const modules = data.modules.filter((item) => canAccessModule(user, item.slug))
  const currency = data.currency || 'USD'

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
        <div className="flex items-start justify-between gap-3">
          <div>
            <div className="font-semibold">{t('dashboard.officePerformance')}</div>
            <p className="mt-1 text-sm text-muted">{t('dashboard.officePerformanceHint')}</p>
          </div>
          <button
            type="button"
            onClick={() => period === 'custom' ? applyPreset(lastPreset) : setPeriod('custom')}
            className="shrink-0 rounded-full bg-white px-3 py-2 text-xs font-semibold text-lhms sm:text-sm"
          >
            {period === 'custom' ? t('dashboard.presetPeriod') : t('dashboard.customPeriod')}
          </button>
        </div>
        {period === 'custom' ? (
          <div className="grid grid-cols-2 gap-2 sm:w-80">
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
          <div className="flex rounded-full bg-white p-1">
            {(['day', 'week', 'month'] as const).map((item) => (
              <button
                key={item}
                type="button"
                onClick={() => applyPreset(item)}
                className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold sm:flex-none', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
              >
                {t(`dashboard.period.${item}`)}
              </button>
            ))}
          </div>
        )}
      </div>

      {modules.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
        <div className={cn('grid gap-4', slug ? '' : 'xl:grid-cols-2')}>
          {modules.map((module) => {
            const Icon = businessModuleIcon(module.slug)
            const title = i18n.language.startsWith('fr') ? module.name_fr : module.name
            return (
              <Card key={module.slug} className="p-5">
                <div className="mb-4 flex items-center justify-between gap-3">
                  <div className="flex min-w-0 flex-1 items-center gap-2">
                    <span className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-lhms-soft text-lhms">
                      <Icon size={16} />
                    </span>
                    <div className="truncate font-semibold">{title}</div>
                  </div>
                  {!slug ? (
                    <Link to={`/administration/dashboard/${module.slug}`} className="shrink-0 text-sm font-semibold text-lhms">
                      {t('common.view')}
                    </Link>
                  ) : null}
                </div>
                {module.offices.length === 0 ? <Empty>{t('dashboard.officePerformanceEmpty')}</Empty> : (
                  <>
                {module.savings ? (
                      <div className="mb-4 grid gap-2 sm:grid-cols-3">
                        {[
                          ['guy_savings', module.savings.guy_savings],
                          ['assistant_manager_savings', module.savings.assistant_manager_savings],
                          ['manager_savings', module.savings.manager_savings],
                        ].map(([key, value]) => (
                          <div key={String(key)} className="rounded-xl bg-surface px-3 py-2">
                            <div className="text-xs text-muted">{t(`dashboard.${String(key)}`)}</div>
                            <div className="mt-1 text-sm font-bold">{money(Number(value), currency)}</div>
                          </div>
                        ))}
                      </div>
                    ) : null}
                    {module.compensation ? (
                      <div className="mb-4 grid gap-2 sm:grid-cols-2 xl:grid-cols-4">
                        {[
                          ['generated', module.compensation.generated],
                          ['manager_sale', module.compensation.manager_sale],
                          ['assistant_share', module.compensation.assistant_share],
                          ['manager_office', module.compensation.manager_office],
                          ['divisionnaire_office', module.compensation.divisionnaire_office],
                          ['secretary_office', module.compensation.secretary_office + module.compensation.salary_paid],
                          ['posted', module.compensation.posted],
                          ['remaining', module.compensation.remaining],
                        ].map(([key, value]) => (
                          <div key={String(key)} className="rounded-xl bg-surface px-3 py-2">
                            <div className="text-xs text-muted">{t(`dashboard.compensation.${String(key)}`)}</div>
                            <div className="mt-1 text-sm font-bold">{money(Number(value), currency)}</div>
                          </div>
                        ))}
                      </div>
                    ) : null}
                    <div className="h-72">
                      <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={module.offices} margin={{ top: 8, right: 8, left: 0, bottom: 8 }}>
                          <CartesianGrid strokeDasharray="3 3" stroke="#e4ece6" />
                          <XAxis dataKey="name" tick={{ fontSize: 11 }} interval={0} />
                          <YAxis tick={{ fontSize: 11 }} tickFormatter={(value) => compact(Number(value))} domain={[0, 'auto']} />
                        <Tooltip
                          formatter={(value, key) => {
                            const labelKey = String(key)
                            const label = ['compensation', 'manager_sale', 'assistant_share', 'manager_office_share', 'divisionnaire_share', 'secretary_share'].includes(labelKey)
                              ? t(`dashboard.compensation.${labelKey === 'compensation' ? 'compensation' : labelKey}`)
                              : t(`dashboard.${labelKey}`)
                            return [money(Number(value), currency), label]
                          }}
                        />
                        <Legend formatter={(value) => {
                          const labelKey = String(value)
                          if (['compensation', 'manager_sale', 'assistant_share', 'manager_office_share', 'divisionnaire_share', 'secretary_share'].includes(labelKey)) {
                            return t(`dashboard.compensation.${labelKey === 'compensation' ? 'compensation' : labelKey}`)
                          }
                          return t(`dashboard.${labelKey}`)
                        }} />
                          <Bar dataKey="revenue" fill="#094924" radius={[6, 6, 0, 0]} />
                          <Bar dataKey="profit" fill="#c9a227" radius={[6, 6, 0, 0]} />
                          {module.slug === 'kams' ? <Bar dataKey="compensation" fill="#5b8a72" radius={[6, 6, 0, 0]} /> : null}
                        </BarChart>
                      </ResponsiveContainer>
                    </div>
                  </>
                )}
              </Card>
            )
          })}
        </div>
      )}
    </div>
  )
}
