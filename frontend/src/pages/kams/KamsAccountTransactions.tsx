import { Eye, Info, ListFilter, Printer } from 'lucide-react'
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { PaginationButtons } from '../../components/PaginationButtons'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { cn } from '../../lib/cn'
import { dateTime, money } from '../../lib/format'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'

const TYPE_FILTERS = ['deposit', 'withdrawal', 'expense', 'transfer', 'settlement', 'return', 'compensation'] as const

export type LedgerActor = { id?: number | null; name?: string } | null
export type LedgerEntry = {
  uid?: string
  id: number
  type: string
  status: string
  amount: number
  balance_after?: number | null
  holder?: string | null
  occurred_on?: string | null
  created_at?: string | null
  updated_at?: string | null
  notes?: string | null
  decision_notes?: string | null
  created_by?: LedgerActor
  requested_by?: LedgerActor
  performed_by?: LedgerActor
  member?: { id: number; full_name: string; member_code?: string } | null
  source?: { type?: string; id?: number | null; label?: string | null } | null
}

export type KamsAccountTransactionsHandle = {
  preview: () => void
  print: () => void
}

function matchesType(entryType: string, filter: string) {
  if (!filter) return true
  if (filter === 'withdrawal') return entryType === 'withdrawal' || entryType === 'withdrawal_request'
  if (filter === 'deposit') return entryType === 'deposit' || entryType === 'deposit_request'
  if (filter === 'compensation') return entryType === 'revenue_share' || entryType === 'salary' || entryType === 'compensation'
  if (filter === 'settlement') return entryType === 'settlement'
  if (filter === 'return') return entryType === 'return'
  return entryType === filter
}

function ledgerType(entry: LedgerEntry) {
  if (entry.status === 'pending') {
    if (entry.type === 'deposit' || entry.type === 'deposit_request') return 'deposit_request'
    if (entry.type === 'withdrawal' || entry.type === 'withdrawal_request') return 'withdrawal_request'
  }
  return entry.type
}

export function KamsTransactionsPrintMenu({
  onPreview,
  onPrint,
  onPreviewSimplified,
  onPrintSimplified,
  canPreview = true,
  canPrint = true,
  disabled = false,
  className,
  buttonClassName,
  iconOnly = false,
}: {
  onPreview: () => void
  onPrint: () => void
  onPreviewSimplified?: () => void
  onPrintSimplified?: () => void
  canPreview?: boolean
  canPrint?: boolean
  disabled?: boolean
  className?: string
  buttonClassName?: string
  iconOnly?: boolean
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const hasSimplified = Boolean(onPreviewSimplified || onPrintSimplified)

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!menuRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  if (!canPreview && !canPrint && !hasSimplified) return null

  return (
    <div ref={menuRef} className={cn('relative', className)} onMouseDown={(event) => event.stopPropagation()}>
      <Button
        variant="secondary"
        className={buttonClassName}
        aria-label={t('common.print')}
        aria-expanded={open}
        disabled={disabled}
        onClick={(event) => {
          event.stopPropagation()
          if (disabled) return
          setOpen((value) => !value)
        }}
      >
        <Printer size={16} /> {iconOnly ? null : t('common.print')}
      </Button>
      {open ? (
        <div className={cn('absolute right-0 z-30 mt-1 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl', hasSimplified ? 'w-60' : 'w-48')}>
          {canPreview ? (
            <button
              type="button"
              className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
              onClick={() => {
                setOpen(false)
                onPreview()
              }}
            >
              <Eye size={16} className="text-lhms" /> {t('common.preview')}
            </button>
          ) : null}
          {canPrint ? (
            <button
              type="button"
              className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
              onClick={() => {
                setOpen(false)
                onPrint()
              }}
            >
              <Printer size={16} className="text-lhms" /> {t('common.print')}
            </button>
          ) : null}
          {onPreviewSimplified || onPrintSimplified ? (
            <button
              type="button"
              className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
              onClick={() => {
                setOpen(false)
                if (onPreviewSimplified) onPreviewSimplified()
                else onPrintSimplified?.()
              }}
            >
              <Eye size={16} className="text-lhms" /> {t('kams.simplifiedReport')}
            </button>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}

export const KamsAccountTransactions = forwardRef<KamsAccountTransactionsHandle, {
  title: string
  subtitle?: string
  entries: LedgerEntry[]
  showMember?: boolean
  compact?: boolean
  onDecide?: (id: number, action: 'approve' | 'reject', notes: string) => void
  onRowsChange?: (count: number) => void
}>(function KamsAccountTransactions({ title, subtitle, entries, showMember = true, compact = true, onDecide, onRowsChange }, ref) {
  const { t } = useTranslation()
  const [type, setType] = useState('')
  const [status, setStatus] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [details, setDetails] = useState<LedgerEntry | null>(null)
  const [decision, setDecision] = useState<{ entry: LedgerEntry; action: 'approve' | 'reject' } | null>(null)
  const [decisionNotes, setDecisionNotes] = useState('')
  const [decisionError, setDecisionError] = useState('')

  const pending = useMemo(() => entries.filter((entry) => entry.status === 'pending'), [entries])
  const filtered = useMemo(() => entries.filter((entry) => {
    if (onDecide && entry.status === 'pending') return false
    if (!matchesType(entry.type, type)) return false
    if (status && entry.status !== status) return false
    if (from && (entry.occurred_on || '').slice(0, 10) < from) return false
    if (to && (entry.occurred_on || '').slice(0, 10) > to) return false
    return true
  }).sort((a, b) => {
    const time = (b.created_at || '').localeCompare(a.created_at || '')
    if (time !== 0) return time
    return b.id - a.id
  }), [entries, from, onDecide, status, to, type])

  useEffect(() => {
    onRowsChange?.(filtered.length)
  }, [filtered.length, onRowsChange])

  const lastPage = Math.max(1, Math.ceil(filtered.length / perPage) || 1)
  const safePage = Math.min(page, lastPage)
  const visible = filtered.slice((safePage - 1) * perPage, safePage * perPage)
  const rangeStart = filtered.length === 0 ? 0 : (safePage - 1) * perPage + 1
  const rangeEnd = Math.min(safePage * perPage, filtered.length)

  function openDecision(entry: LedgerEntry, action: 'approve' | 'reject') {
    setDecision({ entry, action })
    setDecisionNotes('')
    setDecisionError('')
  }

  function confirmDecision() {
    if (!decision || !onDecide) return
    if (decision.action === 'reject' && !decisionNotes.trim()) {
      setDecisionError(t('kams.decisionMotifRequired'))
      return
    }
    onDecide(decision.entry.id, decision.action, decisionNotes.trim())
    setDecision(null)
    setDecisionNotes('')
  }

  function printOptions() {
    const filters = [
      type ? `${t('kams.type')}: ${t(`kams.ledger.${type}`)}` : null,
      status ? `${t('common.status')}: ${t(`kams.status.${status}`)}` : null,
      from ? `${t('kams.dateFrom')}: ${from}` : null,
      to ? `${t('kams.dateTo')}: ${to}` : null,
    ].filter(Boolean).join(' · ')

    return {
      title,
      module: t('kams.finance'),
      subtitle: [subtitle, filters].filter(Boolean).join(' · ') || undefined,
      meta: [
        { label: t('common.records'), value: String(filtered.length) },
        { label: t('common.printedOn'), value: new Date().toLocaleString() },
      ],
      headers: [
        t('kams.settlementDate'),
        t('kams.type'),
        t('kams.account'),
        t('kams.motif'),
        t('kams.requestedBy'),
        t('kams.performedBy'),
        t('common.status'),
        t('kams.amount'),
        t('kams.balance'),
      ],
      rows: filtered.map((entry) => [
        dateTime(entry.occurred_on, entry.created_at),
        t(`kams.ledger.${ledgerType(entry)}`, { defaultValue: entry.type }),
        entry.holder || entry.member?.full_name || '—',
        entry.notes || '—',
        entry.requested_by?.name || '—',
        entry.performed_by?.name || '—',
        t(`kams.status.${entry.status}`, { defaultValue: entry.status }),
        money(entry.amount),
        entry.balance_after == null ? '—' : money(entry.balance_after),
      ]),
      emptyLabel: t('common.empty'),
      footerNote: t('common.confidential'),
      orientation: 'landscape' as const,
    }
  }

  useImperativeHandle(ref, () => ({
    preview() {
      setPrintPreviewHtml(buildBrandedTableHtml(printOptions()))
    },
    print() {
      printHtml(buildBrandedTableHtml(printOptions()))
    },
  }))

  useEffect(() => {
    setPage(1)
  }, [entries, type, status, from, to, perPage])

  const filtersActive = Boolean(type || status || from || to)

  return (
    <Card className="p-3 sm:p-5">
      <div className="mb-4 flex items-center justify-between gap-3">
        <div className="font-semibold">{title}</div>
        <Button
          variant="secondary"
          aria-expanded={filtersOpen}
          aria-label={filtersOpen ? t('kams.hideFilters') : t('kams.showFilters')}
          onClick={() => setFiltersOpen((open) => !open)}
          className={filtersActive && !filtersOpen ? 'w-11 px-0 ring-1 ring-lhms' : 'w-11 px-0'}
        >
          <ListFilter size={16} />
        </Button>
      </div>

      {filtersOpen ? (
      <div className="mb-4 grid grid-cols-2 gap-3 lg:flex lg:items-end lg:gap-0">
        <div className="min-w-0 lg:flex-1 lg:border-r lg:border-line lg:pr-4">
          <Select className="min-w-0" label={t('kams.type')} value={type} onChange={(e) => setType(e.target.value)}>
            <option value="">{t('common.all')}</option>
            {TYPE_FILTERS.map((item) => (
              <option key={item} value={item}>{t(`kams.ledger.${item}`)}</option>
            ))}
          </Select>
        </div>
        <div className="min-w-0 lg:flex-1 lg:border-r lg:border-line lg:px-4">
          <Select className="min-w-0" label={t('common.status')} value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t('common.all')}</option>
            <option value="posted">{t('kams.status.posted')}</option>
            <option value="pending">{t('kams.status.pending')}</option>
            <option value="rejected">{t('kams.status.rejected')}</option>
          </Select>
        </div>
        <div className="col-span-2 grid grid-cols-2 gap-3 lg:flex lg:min-w-0 lg:flex-[2] lg:gap-3 lg:pl-4">
          <Field className="min-w-0 max-w-full lg:flex-1" label={t('kams.dateFrom')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          <Field className="min-w-0 max-w-full lg:flex-1" label={t('kams.dateTo')} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </div>
      ) : null}

      {onDecide && pending.length > 0 ? (
        <div className="mb-5 space-y-3">
          <div className="text-sm font-semibold text-amber-900">{t('kams.pendingRequests')} ({pending.length})</div>
          {pending.map((entry) => (
            <TransactionRow
              key={entry.uid || entry.id}
              entry={entry}
              showMember={showMember}
              onDetails={() => setDetails(entry)}
              onDecide={openDecision}
            />
          ))}
        </div>
      ) : null}

      <div className="overflow-hidden rounded-2xl border border-line">
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-lhms text-white">
              <tr className="text-left text-xs font-semibold tracking-wide">
                <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.settlementDate')}</th>
                <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.type')}</th>
                {showMember ? <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.account')}</th> : null}
                <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.motif')}</th>
                <th className="bg-lhms px-3 py-3 font-semibold">{t('common.status')}</th>
                <th className="min-w-[8.5rem] bg-lhms px-5 py-3 text-right font-semibold">{t('kams.amount')}</th>
                <th className="min-w-[8.5rem] bg-lhms px-5 py-3 text-right font-semibold">{t('kams.balance')}</th>
                <th className="w-px bg-lhms px-3 py-3 text-right font-semibold">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {visible.length === 0 ? (
                <tr>
                  <td colSpan={showMember ? 8 : 7} className="px-3 py-8 text-center text-muted">{t('common.empty')}</td>
                </tr>
              ) : visible.map((entry, index) => (
                <tr
                  key={entry.uid || entry.id}
                  className={cn(
                    'border-t border-line',
                    entry.status === 'pending'
                      ? 'bg-amber-50'
                      : index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white',
                  )}
                >
                  <td className={cn('w-px whitespace-nowrap px-3 text-muted', compact ? 'py-1.5' : 'py-3')}>{dateTime(entry.occurred_on, entry.created_at)}</td>
                  <td className={cn('px-3 font-semibold whitespace-nowrap', compact ? 'py-1.5' : 'py-3')}>{t(`kams.ledger.${ledgerType(entry)}`, { defaultValue: entry.type })}</td>
                  {showMember ? <td className={cn('px-3 whitespace-nowrap', compact ? 'py-1.5' : 'py-3')}>{[entry.holder, entry.member?.full_name].filter(Boolean).join(' · ') || '—'}</td> : null}
                  <td className={cn('max-w-[16rem] truncate px-3', compact ? 'py-1.5' : 'py-3')}>{entry.notes || '—'}</td>
                  <td className={cn('px-3 whitespace-nowrap', compact ? 'py-1.5' : 'py-3')}>
                    <Badge tone={entry.status === 'posted' ? 'green' : entry.status === 'pending' ? 'amber' : 'red'}>
                      {t(`kams.status.${entry.status}`, { defaultValue: entry.status })}
                    </Badge>
                  </td>
                  <td className={cn('min-w-[8.5rem] px-5 text-right font-bold whitespace-nowrap', compact ? 'py-1.5' : 'py-3', entry.amount < 0 ? 'text-red-600' : 'text-lhms')}>{money(entry.amount)}</td>
                  <td className={cn('min-w-[8.5rem] px-5 text-right font-bold whitespace-nowrap', compact ? 'py-1.5' : 'py-3')}>{entry.balance_after == null ? '—' : money(entry.balance_after)}</td>
                  <td className={cn('w-px px-3 text-right', compact ? 'py-1' : 'py-2')}>
                    <div className="inline-flex items-center justify-end gap-2">
                      <Button
                        type="button"
                        variant="secondary"
                        className={cn('shrink-0 overflow-hidden p-0 px-0', compact ? 'min-h-0' : 'h-11 w-11')}
                        style={compact ? { width: 28, height: 28, minHeight: 0, padding: 0 } : undefined}
                        aria-label={t('kams.transactionDetails')}
                        onClick={() => setDetails(entry)}
                      >
                        <Info className={compact ? 'size-4 shrink-0' : 'size-5 shrink-0'} />
                      </Button>
                      {entry.status === 'pending' && onDecide ? (
                        <>
                          <Button variant="secondary" className="h-9 px-3" onClick={() => openDecision(entry, 'approve')}>{t('common.approve')}</Button>
                          <Button variant="ghost" className="h-9 px-3" onClick={() => openDecision(entry, 'reject')}>{t('common.reject')}</Button>
                        </>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex flex-col gap-3 border-t border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">{t('kams.pageRange', { from: rangeStart, to: rangeEnd, total: filtered.length })}</p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2 text-sm text-muted">
              {t('kams.rowsPerPage')}
              <select value={perPage} onChange={(event) => setPerPage(Number(event.target.value))} className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink">
                {[10, 20, 50].map((size) => <option key={size} value={size}>{size}</option>)}
              </select>
            </label>
            <PaginationButtons
              page={safePage}
              lastPage={lastPage}
              onPrev={() => setPage((value) => Math.max(1, value - 1))}
              onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
            />
          </div>
        </div>
      </div>

      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />

      <Modal open={Boolean(details)} title={t('kams.transactionDetails')} onClose={() => setDetails(null)}>
        {details ? (
          <div className="space-y-0">
            <DetailLine label={t('kams.type')} value={t(`kams.ledger.${ledgerType(details)}`, { defaultValue: details.type })} />
            <DetailLine label={t('common.status')} value={t(`kams.status.${details.status}`, { defaultValue: details.status })} />
            <DetailLine label={t('kams.amount')} value={money(details.amount)} />
            <DetailLine label={t('kams.balance')} value={details.balance_after == null ? undefined : money(details.balance_after)} />
            <DetailLine label={t('kams.settlementDate')} value={dateTime(details.occurred_on, details.created_at)} />
            <DetailLine label={t('kams.account')} value={details.member ? `${details.member.full_name}${details.member.member_code ? ` · ${details.member.member_code}` : ''}` : undefined} />
            <DetailLine
              label={t('kams.originLabel')}
              value={details.source ? [t(`kams.sources.${details.source.type}`, { defaultValue: details.source.type || '' }), details.source.label].filter(Boolean).join(' · ') : undefined}
            />
            <DetailLine label={t('kams.motif')} value={details.notes || undefined} />
            <DetailLine label={t('kams.decisionMotif')} value={details.decision_notes || undefined} />
            <DetailLine label={t('kams.requestedBy')} value={details.requested_by?.name} />
            <DetailLine label={t('kams.performedBy')} value={details.performed_by?.name || (details.status === 'pending' ? t('kams.pendingActor') : undefined)} />
            <DetailLine label={t('kams.recordedAt')} value={details.created_at ? dateTime(details.occurred_on, details.created_at) : undefined} />
            <DetailLine label={t('kams.reference')} value={details.uid || `#${details.id}`} />
          </div>
        ) : null}
      </Modal>
      <Modal open={Boolean(decision)} title={decision?.action === 'reject' ? t('kams.rejectRequest') : t('kams.approveRequest')} onClose={() => setDecision(null)}>
        {decision ? (
          <form className="space-y-3" onSubmit={(event) => { event.preventDefault(); confirmDecision() }}>
            <p className="text-sm text-muted">
              {t(`kams.ledger.${ledgerType(decision.entry)}`, { defaultValue: decision.entry.type })}
              {decision.entry.member?.full_name ? ` · ${decision.entry.member.full_name}` : ''}
              {` · ${money(decision.entry.amount)}`}
            </p>
            {decision.action === 'approve' ? (
              <p className="text-sm">{t('kams.confirmApprove')}</p>
            ) : null}
            {decisionError ? <p className="text-sm text-red-600">{decisionError}</p> : null}
            {decision.action === 'reject' ? (
              <Field
                label={t('kams.decisionMotif')}
                value={decisionNotes}
                onChange={(e) => { setDecisionNotes(e.target.value); setDecisionError('') }}
                required
              />
            ) : null}
            <Button type="submit" className="w-full" variant={decision.action === 'reject' ? 'danger' : 'primary'}>
              {decision.action === 'reject' ? t('common.reject') : t('common.approve')}
            </Button>
          </form>
        ) : null}
      </Modal>
    </Card>
  )
})

function TransactionRow({
  entry,
  showMember,
  onDetails,
  onDecide,
}: {
  entry: LedgerEntry
  showMember: boolean
  onDetails: () => void
  onDecide?: (entry: LedgerEntry, action: 'approve' | 'reject') => void
}) {
  const { t } = useTranslation()
  const kind = ledgerType(entry)

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-3 sm:px-4">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="font-semibold text-amber-950">
            {t(`kams.ledger.${kind}`, { defaultValue: entry.type })}
            {showMember && entry.member?.full_name ? ` · ${entry.member.full_name}` : ''}
          </div>
          <div className="mt-1 text-sm text-amber-900/75">
            {dateTime(entry.occurred_on, entry.created_at)}
            {entry.notes ? ` · ${entry.notes}` : ''}
          </div>
          <div className="mt-1 text-xs text-amber-900/65">
            <div>{t('kams.requestedBy')}: {entry.requested_by?.name || '—'}</div>
            <div className="mt-0.5">
              {t('kams.performedBy')}: {entry.performed_by?.name || (entry.status === 'pending' ? t('kams.pendingActor') : '—')}
            </div>
          </div>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1.5">
          <Badge tone={entry.status === 'posted' ? 'green' : entry.status === 'pending' ? 'amber' : 'red'}>
            {t(`kams.status.${entry.status}`, { defaultValue: entry.status })}
          </Badge>
          <div className={cn('text-base font-bold tabular-nums sm:text-lg', entry.amount < 0 ? 'text-red-600' : 'text-lhms')}>
            {money(entry.amount)}
          </div>
        </div>
      </div>

      <div className="mt-3 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
        <Button
          type="button"
          variant="secondary"
          className="h-11 w-full sm:w-11 sm:shrink-0 sm:px-0"
          aria-label={t('kams.transactionDetails')}
          onClick={onDetails}
        >
          <Info size={20} />
          <span className="sm:hidden">{t('kams.transactionDetails')}</span>
        </Button>
        {entry.status === 'pending' && onDecide ? (
          <div className="grid grid-cols-2 gap-2 sm:flex sm:w-auto">
            <Button variant="secondary" className="h-11 w-full sm:w-auto" onClick={() => onDecide(entry, 'approve')}>
              {t('common.approve')}
            </Button>
            <Button variant="ghost" className="h-11 w-full sm:w-auto" onClick={() => onDecide(entry, 'reject')}>
              {t('common.reject')}
            </Button>
          </div>
        ) : null}
      </div>
    </div>
  )
}

function DetailLine({ label, value }: { label: string; value?: string | null }) {
  if (!value) return null
  return (
    <div className="flex items-start justify-between gap-4 border-b border-line py-2.5 text-sm last:border-b-0">
      <span className="text-muted">{label}</span>
      <span className="max-w-[60%] text-right font-medium">{value}</span>
    </div>
  )
}
