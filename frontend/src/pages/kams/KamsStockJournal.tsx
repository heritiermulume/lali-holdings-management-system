import { startTransition, useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import {
  ArrowLeftRight,
  ChevronLeft,
  ChevronRight,
  Package,
  PackageMinus,
  PackagePlus,
  Search,
  Store,
  Trash2,
  Truck,
  UserRound,
  Warehouse,
  ClipboardCheck,
  CircleDollarSign,
  Pencil,
  RotateCcw,
  ScrollText,
  type LucideIcon,
} from 'lucide-react'
import { Badge, Card, Empty, Field, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { initials } from '../../lib/format'

type JournalEntry = {
  id: number
  action: string
  event: string
  entity: string
  auditable_id?: number | null
  subject: string
  details: string[]
  created_at?: string | null
  ip_address?: string | null
  user?: { id: number; name: string; email?: string } | null
}

type ActionFilter = '' | 'create' | 'update' | 'delete'
type Tone = 'green' | 'amber' | 'red' | 'slate'

function eventTone(event: string): Tone {
  if (event.includes('delete') || event.includes('cancel') || event.includes('remove')) return 'red'
  if (
    event.includes('create')
    || event.includes('receive')
    || event.includes('accept')
    || event.includes('settle')
    || event.includes('close')
    || event.includes('deposit')
    || event.includes('pay')
  ) return 'green'
  if (event.includes('update') || event.includes('reassign') || event.includes('change') || event.includes('add')) return 'amber'
  return 'slate'
}

function eventIcon(event: string, entity: string): LucideIcon {
  if (event.includes('delete') || event.includes('remove') || event.includes('cancel')) return Trash2
  if (event.includes('settle') || event.includes('close')) return ClipboardCheck
  if (event.includes('transfer') || entity === 'KamsStockTransfer') return ArrowLeftRight
  if (event.includes('receipt') || entity === 'KamsStockReceipt') return Truck
  if (event.includes('return') || entity === 'KamsStockReturn') return RotateCcw
  if (event.includes('warehouse') || (entity === 'KamsDepot' && event.includes('warehouse'))) return Warehouse
  if (entity === 'KamsDepot') return Store
  if (entity === 'KamsProduct') return Package
  if (event.includes('add') || event.includes('create')) return PackagePlus
  if (event.includes('surplus') || event.includes('commission') || event.includes('sale') || event.includes('deposit')) return CircleDollarSign
  if (event.includes('update') || event.includes('reassign') || event.includes('change')) return Pencil
  if (event.includes('assignment')) return PackageMinus
  return ScrollText
}

const toneStyles: Record<Tone, { icon: string; rail: string; chip: string }> = {
  green: {
    icon: 'bg-lhms-soft text-lhms ring-lhms/15',
    rail: 'bg-lhms/25',
    chip: 'bg-lhms-soft text-lhms',
  },
  amber: {
    icon: 'bg-amber-50 text-warn ring-amber-100',
    rail: 'bg-amber-200/70',
    chip: 'bg-amber-50 text-warn',
  },
  red: {
    icon: 'bg-danger-soft text-danger ring-red-100',
    rail: 'bg-red-200/70',
    chip: 'bg-danger-soft text-danger',
  },
  slate: {
    icon: 'bg-slate-100 text-slate-600 ring-slate-200',
    rail: 'bg-slate-200',
    chip: 'bg-slate-100 text-slate-600',
  },
}

function dayKey(value?: string | null) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value.slice(0, 10)
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`
}

function formatDayLabel(key: string, locale: string, t: (key: string) => string) {
  if (!key) return '—'
  const today = dayKey(new Date().toISOString())
  const yesterdayDate = new Date()
  yesterdayDate.setDate(yesterdayDate.getDate() - 1)
  const yesterday = dayKey(yesterdayDate.toISOString())
  if (key === today) return t('kams.journalToday')
  if (key === yesterday) return t('kams.journalYesterday')
  return new Date(`${key}T12:00:00`).toLocaleDateString(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

function formatClock(value?: string | null, locale?: string) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '—'
  return date.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit', hour12: false })
}

export function KamsStockJournal() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language.startsWith('en') ? 'en-GB' : 'fr-FR'
  const [rows, setRows] = useState<JournalEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [action, setAction] = useState<ActionFilter>('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const hasRows = rows.length > 0

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    let active = true
    setLoading(true)
    api.get('/modules/kams/stock/journal', {
      params: {
        page,
        per_page: 20,
        ...(query ? { search: query } : {}),
        ...(action ? { action } : {}),
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
      },
    }).then((res) => {
      if (!active) return
      startTransition(() => {
        setRows(res.data.data || [])
        setLastPage(res.data.meta?.last_page || 1)
        setTotal(res.data.meta?.total || 0)
      })
    }).catch(() => {
      if (!active) return
      startTransition(() => {
        setRows([])
        setLastPage(1)
        setTotal(0)
      })
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [page, query, action, from, to])

  const groups = useMemo(() => {
    const map = new Map<string, JournalEntry[]>()
    rows.forEach((row) => {
      const key = dayKey(row.created_at) || 'unknown'
      const list = map.get(key) || []
      list.push(row)
      map.set(key, list)
    })
    return Array.from(map.entries())
  }, [rows])

  return (
    <div>
      <div className="mb-5 overflow-hidden rounded-2xl border border-line bg-white">
        <div className="border-b border-line bg-[linear-gradient(135deg,rgba(9,73,36,0.06),rgba(255,255,255,0.9)_42%)] px-5 py-4">
          <div className="flex flex-wrap items-end justify-between gap-3">
            <div>
              <div className="text-sm font-semibold text-ink">{t('kams.stockJournal')}</div>
              <p className="mt-1 max-w-2xl text-sm text-muted">{t('kams.stockJournalHint')}</p>
            </div>
            {!loading && total > 0 ? (
              <div className="rounded-full bg-lhms-soft px-3 py-1 text-xs font-semibold text-lhms">
                {total} {t('kams.journalEntries').toLowerCase()}
              </div>
            ) : null}
          </div>
        </div>

        <div className="grid grid-cols-1 gap-3 p-4 sm:grid-cols-2 xl:grid-cols-4 [&>*]:min-w-0">
          <label className="block min-w-0 space-y-1.5">
            <span className="text-sm font-medium text-ink">{t('common.search')}</span>
            <div className="relative">
              <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted" />
              <input
                className="min-h-11 w-full rounded-xl border border-line bg-white pr-3 pl-9 text-sm outline-none ring-lhms/20 transition focus:border-lhms focus:ring-4"
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder={t('common.search')}
              />
            </div>
          </label>
          <Select
            className="min-w-0"
            label={t('kams.journalActionFilter')}
            value={action}
            onChange={(e) => { setAction(e.target.value as ActionFilter); setPage(1) }}
          >
            <option value="">{t('common.all')}</option>
            <option value="create">{t('kams.journalActions.create')}</option>
            <option value="update">{t('kams.journalActions.update')}</option>
            <option value="delete">{t('kams.journalActions.delete')}</option>
          </Select>
          <Field
            className="min-w-0 max-w-full"
            label={t('kams.dateFrom')}
            type="date"
            value={from}
            onChange={(e) => { setFrom(e.target.value); setPage(1) }}
          />
          <Field
            className="min-w-0 max-w-full"
            label={t('kams.dateTo')}
            type="date"
            value={to}
            onChange={(e) => { setTo(e.target.value); setPage(1) }}
          />
        </div>
      </div>

      {loading && !hasRows ? <LoadingScreen /> : null}

      {!loading && rows.length === 0 ? (
        <Card className="p-10">
          <Empty>{t('common.empty')}</Empty>
        </Card>
      ) : null}

      {hasRows ? (
        <div className="space-y-8">
          {groups.map(([day, items]) => (
            <section key={day}>
              <div className="mb-4 flex items-center gap-3">
                <div className="text-sm font-semibold capitalize text-ink">{formatDayLabel(day, locale, t)}</div>
                <div className="h-px flex-1 bg-line" />
                <div className="text-xs font-medium text-muted">{items.length}</div>
              </div>

              <div className="relative space-y-3 pl-2 sm:pl-3">
                {items.map((row, index) => {
                  const tone = eventTone(row.event)
                  const styles = toneStyles[tone]
                  const Icon = eventIcon(row.event, row.entity)
                  const eventLabel = t(`kams.journalEvents.${row.event}`, { defaultValue: row.event })
                  const entityLabel = t(`kams.journalEntities.${row.entity}`, { defaultValue: row.entity })
                  const userName = row.user?.name || t('common.system')
                  const isLast = index === items.length - 1

                  return (
                    <article key={row.id} className="relative flex gap-3 sm:gap-4">
                      <div className="relative flex w-10 shrink-0 flex-col items-center sm:w-12">
                        {!isLast ? (
                          <span className={cn('absolute top-11 bottom-[-12px] w-px', styles.rail)} />
                        ) : null}
                        <span className={cn('relative z-10 grid h-10 w-10 place-items-center rounded-2xl ring-4 ring-white sm:h-11 sm:w-11', styles.icon)}>
                          <Icon size={18} strokeWidth={2.2} />
                        </span>
                      </div>

                      <div className="min-w-0 flex-1 rounded-2xl border border-line bg-white p-4 shadow-[0_1px_0_rgba(15,23,42,0.03)] transition hover:border-lhms/25 hover:shadow-[0_10px_30px_rgba(15,23,42,0.05)] sm:p-5">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                          <div className="min-w-0 space-y-2">
                            <div className="flex flex-wrap items-center gap-2">
                              <span className={cn('inline-flex rounded-full px-2.5 py-1 text-xs font-semibold', styles.chip)}>
                                {eventLabel}
                              </span>
                              <Badge tone="slate">{entityLabel}</Badge>
                            </div>
                            {row.subject && !/^#\d+$/.test(row.subject) ? (
                              <h3 className="truncate text-base font-semibold tracking-tight text-ink">{row.subject}</h3>
                            ) : null}
                            {row.details.length > 0 ? (
                              <div className="flex flex-wrap gap-2">
                                {row.details.map((detail) => (
                                  <span
                                    key={`${row.id}-${detail}`}
                                    className="inline-flex max-w-full truncate rounded-lg bg-surface px-2.5 py-1 text-xs font-medium text-muted"
                                  >
                                    {detail}
                                  </span>
                                ))}
                              </div>
                            ) : null}
                          </div>

                          <div className="ml-auto flex shrink-0 items-center gap-3">
                            <div className="hidden text-right sm:block">
                              <div className="text-sm font-semibold text-ink">{formatClock(row.created_at, locale)}</div>
                              {row.ip_address ? <div className="mt-0.5 text-[11px] text-muted">{row.ip_address}</div> : null}
                            </div>
                            <div className="flex items-center gap-2 rounded-full bg-surface py-1.5 pr-3 pl-1.5">
                              <span className="grid h-8 w-8 place-items-center rounded-full bg-lhms text-[11px] font-bold text-white">
                                {row.user ? initials(userName) : <UserRound size={14} />}
                              </span>
                              <div className="min-w-0">
                                <div className="max-w-[9rem] truncate text-xs font-semibold text-ink">{userName}</div>
                                <div className="text-[11px] text-muted sm:hidden">{formatClock(row.created_at, locale)}</div>
                              </div>
                            </div>
                          </div>
                        </div>
                      </div>
                    </article>
                  )
                })}
              </div>
            </section>
          ))}
        </div>
      ) : null}

      {lastPage > 1 ? (
        <div className="mt-6 flex items-center justify-between gap-3 rounded-2xl border border-line bg-white px-4 py-3 text-sm">
          <span className="text-muted">
            {t('kams.journalPage', { page, last: lastPage, defaultValue: `${page} / ${lastPage}` })}
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              className={cn(
                'inline-flex h-10 items-center gap-1 rounded-full px-3.5 font-semibold transition',
                loading || page <= 1 ? 'cursor-not-allowed bg-surface text-muted' : 'bg-lhms text-white hover:opacity-95',
              )}
              disabled={loading || page <= 1}
              onClick={() => setPage((value) => Math.max(1, value - 1))}
            >
              <ChevronLeft size={16} />
              {t('common.prev')}
            </button>
            <button
              type="button"
              className={cn(
                'inline-flex h-10 items-center gap-1 rounded-full px-3.5 font-semibold transition',
                loading || page >= lastPage ? 'cursor-not-allowed bg-surface text-muted' : 'bg-lhms text-white hover:opacity-95',
              )}
              disabled={loading || page >= lastPage}
              onClick={() => setPage((value) => Math.min(lastPage, value + 1))}
            >
              {t('common.next')}
              <ChevronRight size={16} />
            </button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
