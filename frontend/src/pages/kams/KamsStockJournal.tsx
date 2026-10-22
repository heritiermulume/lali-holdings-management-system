import { startTransition, useEffect, useMemo, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { intlLocale } from '../../i18n/locales'
import {
  ArrowLeftRight,
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
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { PaginationButtons } from '../../components/PaginationButtons'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
import { trackLoadSpinner } from '../../lib/dataSync'
import { cn } from '../../lib/cn'
import { initials } from '../../lib/format'
import type { OperationsOfficeFilter } from './KamsStockFlows'

type JournalEntry = {
  id: number
  action: string
  event: string
  entity: string
  auditable_id?: number | null
  subject: string
  details: string[]
  changes?: Array<{ field: string; before: string | null; after: string | null }>
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

function journalFieldLabel(field: string, t: (key: string, options?: { defaultValue?: string }) => string) {
  return t(`kams.journalFields.${field}`, { defaultValue: field.replaceAll('_', ' ') })
}

function translateJournalToken(value: string, t: (key: string, options?: { defaultValue?: string }) => string) {
  const trimmed = value.trim()
  if (!/^[a-z0-9_]+$/.test(trimmed)) return value
  const status = t(`kams.status.${trimmed}`, { defaultValue: '' })
  if (status) return status
  const field = t(`kams.journalFields.${trimmed}`, { defaultValue: '' })
  if (field && field !== trimmed.replaceAll('_', ' ')) return field
  return value
}

function translateJournalValue(value: string | null | undefined, t: (key: string, options?: { defaultValue?: string }) => string) {
  if (!value) return '—'
  return value
    .split('\n')
    .map((line) => line.split(' · ').map((part) => {
      const separator = part.indexOf(': ')
      if (separator <= 0) return translateJournalToken(part, t)
      const key = part.slice(0, separator)
      if (!/^[a-z0-9_]+$/.test(key)) return part
      return `${journalFieldLabel(key, t)} : ${translateJournalToken(part.slice(separator + 2), t)}`
    }).join(' · '))
    .join('\n')
}

function JournalDetail({ entry, locale }: { entry: JournalEntry; locale: string }) {
  const { t } = useTranslation()
  const when = entry.created_at
    ? new Date(entry.created_at).toLocaleString(locale, { dateStyle: 'full', timeStyle: 'short' })
    : '—'
  const userName = entry.user?.name || t('common.system')
  const changes = entry.changes || []
  const isUpdate = entry.action === 'update' || changes.some((change) => change.before && change.after)
  const eventLabel = t(`kams.journalEvents.${entry.event}`, { defaultValue: entry.event })
  const entityLabel = t(`kams.journalEntities.${entry.entity}`, { defaultValue: entry.entity })

  return (
    <div className="space-y-4 text-sm">
      <div className="flex flex-wrap gap-2">
        <Badge tone="slate">{eventLabel}</Badge>
        <Badge tone="slate">{entityLabel}</Badge>
      </div>
      <div className="grid gap-2 sm:grid-cols-2">
        <div className="rounded-xl border border-line px-3 py-2">
          <div className="text-xs text-muted">{t('kams.performedBy')}</div>
          <div className="font-semibold">{userName}</div>
          {entry.user?.email ? <div className="text-xs text-muted">{entry.user.email}</div> : null}
        </div>
        <div className="rounded-xl border border-line px-3 py-2">
          <div className="text-xs text-muted">{t('kams.journalWhen')}</div>
          <div className="font-semibold capitalize">{when}</div>
          {entry.ip_address ? <div className="text-xs text-muted">{entry.ip_address}</div> : null}
        </div>
      </div>
      {changes.length === 0 ? (
        <p className="text-muted">{t('kams.journalNoChanges')}</p>
      ) : (
        <div className="overflow-x-auto rounded-xl border border-line">
          <div className={cn('grid gap-2 border-b border-line bg-[#f4faf5] px-3 py-2 text-[11px] font-semibold uppercase tracking-wide text-muted', isUpdate ? 'grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]' : 'grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]')}>
            <span>{t('kams.journalField')}</span>
            {isUpdate ? <span>{t('kams.journalBefore')}</span> : null}
            <span>{isUpdate ? t('kams.journalAfter') : t('kams.journalValue')}</span>
          </div>
          {changes.map((change, index) => {
            const hideLabel = change.field === 'items' && changes[index - 1]?.field === 'items'
            return (
            <div key={`${change.field}-${index}`} className={cn('grid items-start gap-2 border-t border-line px-3 py-2', isUpdate ? 'grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,1fr)]' : 'grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]')}>
              <div className="font-semibold">{hideLabel ? '' : journalFieldLabel(change.field, t)}</div>
              {isUpdate ? <div className="whitespace-pre-line text-muted">{translateJournalValue(change.before, t)}</div> : null}
              <div className="whitespace-pre-line">{translateJournalValue(isUpdate ? change.after : (change.after || change.before), t)}</div>
            </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

export function KamsStockJournal() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const { officeId } = useOutletContext<OperationsOfficeFilter>()
  const locale = intlLocale(i18n.language)
  const [rows, setRows] = useState<JournalEntry[]>([])
  const [loading, setLoading] = useState(true)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [action, setAction] = useState<ActionFilter>('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [selected, setSelected] = useState<JournalEntry | null>(null)
  const [deleting, setDeleting] = useState<JournalEntry | null>(null)
  const [deleteError, setDeleteError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const canDelete = hasPermission('kams.stock.journal.delete')
  const hasRows = rows.length > 0
  const formatFilterDate = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString(locale)
  const periodLabel = from && to
    ? t('kams.periodRange', { start: formatFilterDate(from), end: formatFilterDate(to) })
    : from
      ? `${t('kams.dateFrom')} ${formatFilterDate(from)}`
      : to
        ? `${t('kams.dateTo')} ${formatFilterDate(to)}`
        : ''

  useEffect(() => {
    const timer = window.setTimeout(() => {
      setQuery(search.trim())
      setPage(1)
    }, 300)
    return () => window.clearTimeout(timer)
  }, [search])

  const load = () => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/stock/journal', {
      params: {
        page,
        per_page: 20,
        ...(query ? { search: query } : {}),
        ...(action ? { action } : {}),
        ...(from ? { from } : {}),
        ...(to ? { to } : {}),
        ...(officeId ? { office_id: officeId } : {}),
      },
    }).then((res) => {
      startTransition(() => {
        setRows(res.data.data || [])
        setLastPage(res.data.meta?.last_page || 1)
      })
    }).catch(() => {
      startTransition(() => {
        setRows([])
        setLastPage(1)
      })
    }).finally(stop)
  }

  useEffect(() => {
    void load()
  }, [page, query, action, from, to, officeId, reloadKey])

  useLiveData(load)

  const confirmDelete = async () => {
    if (!deleting) return
    const entry = deleting
    setDeleteError('')
    try {
      await withBusy('data', async () => {
        await api.delete(`/modules/kams/stock/journal/${entry.id}`)
      })
      setDeleting(null)
      if (selected?.id === entry.id) setSelected(null)
      setReloadKey((value) => value + 1)
    } catch (error: unknown) {
      const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
      setDeleteError(message || t('common.alertError'))
    }
  }

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
      <div className="mb-5 flex flex-col gap-3">
        <div className="flex items-center justify-between gap-2">
          {periodLabel ? (
            <p className="shrink-0 whitespace-nowrap text-[11px] text-muted sm:text-xs">{periodLabel}</p>
          ) : <span />}
          <button
            type="button"
            onClick={() => setFiltersOpen((open) => !open)}
            className={cn('shrink-0 rounded-full px-3 py-2 text-xs font-semibold sm:text-sm', filtersOpen ? 'bg-lhms text-white' : 'bg-white text-lhms')}
          >
            {filtersOpen ? t('common.hide') : t('common.filter')}
          </button>
        </div>
        {filtersOpen ? (
        <div className="grid grid-cols-2 gap-3 xl:grid-cols-4 [&>*]:min-w-0">
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
        ) : null}
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
                            <button
                              type="button"
                              onClick={() => setSelected(row)}
                              className="block max-w-full truncate text-left text-base font-semibold tracking-tight text-lhms underline-offset-2 hover:underline"
                            >
                              {row.subject && !/^#\d+$/.test(row.subject) ? row.subject : eventLabel}
                            </button>
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

                          <div className="ml-auto flex shrink-0 items-center gap-2 sm:gap-3">
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
                            {canDelete ? (
                              <button
                                type="button"
                                aria-label={t('kams.deleteJournalEntry')}
                                title={t('kams.deleteJournalEntry')}
                                className="grid h-9 w-9 place-items-center rounded-xl text-danger hover:bg-danger/10"
                                onClick={() => { setDeleteError(''); setDeleting(row) }}
                              >
                                <Trash2 size={16} />
                              </button>
                            ) : null}
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

      <Modal
        open={Boolean(selected)}
        title={selected?.subject && !/^#\d+$/.test(selected.subject) ? selected.subject : (selected ? t(`kams.journalEvents.${selected.event}`, { defaultValue: selected.event }) : '')}
        size="lg"
        onClose={() => setSelected(null)}
      >
        {selected ? <JournalDetail entry={selected} locale={locale} /> : null}
      </Modal>

      <Modal open={Boolean(deleting)} title={t('kams.deleteJournalEntry')} onClose={() => { setDeleting(null); setDeleteError('') }}>
        <div className="space-y-4">
          <p className="text-sm text-muted">{t('kams.deleteJournalEntryConfirm')}</p>
          {deleting ? (
            <p className="text-sm font-semibold">
              {deleting.subject && !/^#\d+$/.test(deleting.subject)
                ? deleting.subject
                : t(`kams.journalEvents.${deleting.event}`, { defaultValue: deleting.event })}
            </p>
          ) : null}
          {deleteError ? <p className="text-sm text-danger">{deleteError}</p> : null}
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => { setDeleting(null); setDeleteError('') }}>{t('common.cancel')}</Button>
            <Button variant="danger" onClick={() => void confirmDelete()}>{t('common.delete')}</Button>
          </div>
        </div>
      </Modal>

      {lastPage > 1 ? (
        <div className="mt-6 flex justify-end rounded-2xl border border-line bg-white px-4 py-3">
          <PaginationButtons
            page={page}
            lastPage={lastPage}
            disabled={loading}
            onPrev={() => setPage((value) => Math.max(1, value - 1))}
            onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
          />
        </div>
      ) : null}
    </div>
  )
}
