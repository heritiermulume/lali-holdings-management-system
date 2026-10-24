import { Eye, Info, ListFilter, Printer } from 'lucide-react'
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { PaginationButtons, showListPagination } from '../../components/PaginationButtons'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { cn } from '../../lib/cn'
import { dateTime, money } from '../../lib/format'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'

const TYPE_FILTERS = ['deposit', 'withdrawal', 'expense', 'transfer', 'settlement', 'return', 'compensation'] as const

export type LedgerActor = { id?: number | null; name?: string } | null
export type LedgerEntry = {
  uid?: string
  id: number
  kind?: string | null
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
  is_commission_transfer?: boolean
  created_by?: LedgerActor
  requested_by?: LedgerActor
  performed_by?: LedgerActor
  office?: { id?: number; name?: string; code?: string } | null
  member?: { id: number; full_name: string; member_code?: string } | null
  source?: {
    type?: string
    id?: number | null
    label?: string | null
    entry_type?: string | null
    is_commission_transfer?: boolean
    rule_code?: string | null
    formula?: string | null
    beneficiary?: { id: number; full_name: string; member_code?: string | null } | null
    paid_from?: { id: number; full_name: string; member_code?: string | null } | null
  } | null
}

type Translate = (key: string, options?: Record<string, unknown>) => string

/** Re-translate system-generated ledger notes so they follow the active UI locale. */
export function formatLedgerNotes(entry: LedgerEntry, t: Translate): string {
  const notes = (entry.notes || '').trim()
  if (!notes) return ''

  const officeFallback = entry.office?.name || entry.holder || entry.source?.label || ''

  const depositMatch = notes.match(/^Deposit from (.+) to principal account$/u)
    || notes.match(/^Dépôt de (.+) vers le compte principal$/u)
    || notes.match(/^Dépôt depuis (.+) vers le compte principal$/u)
  if (depositMatch) {
    return t('kams.depositFromOfficeToPrincipal', { office: depositMatch[1] || officeFallback })
  }

  if (
    notes === 'Transfer to principal account'
    || notes === 'Transfert vers le compte principal'
    || notes === 'Transfert vers le compte central'
  ) {
    return t('kams.transferToPrincipalNote')
  }

  const transferFromMatch = notes.match(/^Transfer from (.+)$/u)
    || notes.match(/^Transfert depuis (.+)$/u)
    || notes.match(/^Transfert de (.+)$/u)
  if (transferFromMatch) {
    return t('kams.transferFromOfficeNote', { office: transferFromMatch[1] || officeFallback })
  }

  const assistantPayoutMatch = notes.match(/^Versement assistant (.+?) \((AM(?: certifié)?)\)(?: · (.+))?$/u)
    || notes.match(/^Assistant payout to (.+?) \((AM(?: certified)?)\)(?: · (.+))?$/u)
  if (assistantPayoutMatch || entry.type === 'assistant_payout') {
    const name = assistantPayoutMatch?.[1]
      || entry.source?.beneficiary?.full_name
      || ''
    const saleRef = assistantPayoutMatch?.[3] || entry.source?.label || ''
    const certified = /certifi/i.test(assistantPayoutMatch?.[2] || entry.source?.rule_code || '')
    const label = t(certified ? 'kams.assistantPayoutCertifiedNote' : 'kams.assistantPayoutNote', {
      name,
      defaultValue: notes,
    })
    return saleRef ? `${label}${t('kams.assistantPayoutSaleSuffix', { sale: saleRef })}` : label
  }

  const amRule = entry.source?.rule_code === 'assistant_manager'
    || entry.source?.rule_code === 'assistant_manager_certified'
  if (entry.type === 'revenue_share' && amRule && entry.source?.paid_from?.full_name) {
    const label = t('kams.assistantReceivedFromManagerNote', {
      manager: entry.source.paid_from.full_name,
      defaultValue: notes,
    })
    const saleRef = entry.source.label || ''
    return saleRef ? `${label}${t('kams.assistantPayoutSaleSuffix', { sale: saleRef })}` : label
  }

  return notes
}

export type KamsAccountTransactionsHandle = {
  preview: () => void
  print: () => void
}

function matchesType(entryType: string, filter: string) {
  if (!filter) return true
  if (filter === 'withdrawal') return entryType === 'withdrawal' || entryType === 'withdrawal_request'
  if (filter === 'deposit') {
    return entryType === 'deposit'
      || entryType === 'deposit_request'
      || entryType === 'commission_transfer'
      || entryType === 'transfer_deposit'
  }
  if (filter === 'transfer') return entryType === 'transfer' || entryType === 'transfer_deposit'
  if (filter === 'compensation') {
    return entryType === 'revenue_share'
      || entryType === 'salary'
      || entryType === 'compensation'
      || entryType === 'assistant_payout'
  }
  if (filter === 'settlement') return entryType === 'settlement'
  if (filter === 'return') return entryType === 'return'
  return entryType === filter
}

function ledgerType(entry: LedgerEntry) {
  if (entry.status === 'pending') {
    if (entry.type === 'commission_transfer' || entry.is_commission_transfer) {
      return 'commission_transfer'
    }
    if (entry.type === 'deposit' || entry.type === 'deposit_request' || entry.type === 'transfer_deposit') {
      return 'deposit_request'
    }
    if (entry.type === 'withdrawal' || entry.type === 'withdrawal_request') return 'withdrawal_request'
  }
  if (entry.type === 'commission_transfer' || entry.is_commission_transfer) return 'commission_transfer'
  return entry.type
}

function walletProvenance(entry: LedgerEntry, t: (key: string, options?: { defaultValue?: string }) => string) {
  if (!entry.kind) return null
  return t(`kams.wallet.${entry.kind}`, { defaultValue: entry.kind })
}

export function KamsTransactionsPrintMenu({
  onPreview,
  onPrint,
  onPreviewSimplified,
  onPrintSimplified,
  onPreviewPerformance,
  onPrintPerformance,
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
  onPreviewPerformance?: () => void
  onPrintPerformance?: () => void
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
  const hasPerformance = Boolean(onPreviewPerformance || onPrintPerformance)

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
        <div className={cn('absolute right-0 z-30 mt-1 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl', hasPerformance ? 'w-72' : hasSimplified ? 'w-60' : 'w-48')}>
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
          {onPreviewPerformance || onPrintPerformance ? (
            <button
              type="button"
              className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
              onClick={() => {
                setOpen(false)
                if (onPreviewPerformance) onPreviewPerformance()
                else onPrintPerformance?.()
              }}
            >
              <Eye size={16} className="text-lhms" /> {t('kams.performanceReport')}
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
  showProvenance?: boolean
  provenanceKinds?: string[]
  compact?: boolean
  /** Extra chips included in print/preview (e.g. Entrées / Sorties / Solde). */
  printMeta?: Array<{ label: string; value: string }>
  /** When set, keeps the table date filters aligned with an external period. */
  dateFrom?: string
  dateTo?: string
  onDecide?: (id: number, action: 'approve' | 'reject', notes: string) => void
  canDecideEntry?: (entry: LedgerEntry) => boolean
  onRowsChange?: (count: number) => void
}>(function KamsAccountTransactions({
  title,
  subtitle,
  entries,
  showMember = true,
  showProvenance = false,
  provenanceKinds = [],
  compact = true,
  printMeta = [],
  dateFrom,
  dateTo,
  onDecide,
  canDecideEntry,
  onRowsChange,
}, ref) {
  const { t } = useTranslation()
  const [type, setType] = useState('')
  const [status, setStatus] = useState('')
  const [walletKind, setWalletKind] = useState('')
  const [from, setFrom] = useState(dateFrom || '')
  const [to, setTo] = useState(dateTo || '')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [filtersOpen, setFiltersOpen] = useState(false)

  useEffect(() => {
    if (dateFrom !== undefined) setFrom(dateFrom)
  }, [dateFrom])

  useEffect(() => {
    if (dateTo !== undefined) setTo(dateTo)
  }, [dateTo])
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [details, setDetails] = useState<LedgerEntry | null>(null)
  const [decision, setDecision] = useState<{ entry: LedgerEntry; action: 'approve' | 'reject' } | null>(null)
  const [decisionNotes, setDecisionNotes] = useState('')
  const [decisionError, setDecisionError] = useState('')

  const walletOptions = useMemo(() => {
    const kinds = new Set<string>(provenanceKinds)
    for (const entry of entries) {
      if (entry.kind) kinds.add(entry.kind)
    }
    return Array.from(kinds).sort()
  }, [entries, provenanceKinds])

  // All pending lines (office + principal) stay visible above the table with their status.
  const pending = useMemo(
    () => entries.filter((entry) => entry.status === 'pending'),
    [entries],
  )
  const filtered = useMemo(() => entries.filter((entry) => {
    // Pending rows are listed in the dedicated block above; keep the table for posted/rejected.
    if (entry.status === 'pending') return false
    if (!matchesType(entry.type, type)) return false
    if (status && entry.status !== status) return false
    if (walletKind && entry.kind !== walletKind) return false
    if (from && (entry.occurred_on || '').slice(0, 10) < from) return false
    if (to && (entry.occurred_on || '').slice(0, 10) > to) return false
    return true
  }).sort((a, b) => {
    const time = (b.created_at || '').localeCompare(a.created_at || '')
    if (time !== 0) return time
    return b.id - a.id
  }), [entries, from, status, to, type, walletKind])

  useEffect(() => {
    onRowsChange?.(filtered.length)
  }, [filtered.length, onRowsChange])

  const lastPage = Math.max(1, Math.ceil(filtered.length / perPage) || 1)
  const safePage = Math.min(page, lastPage)
  const visible = showListPagination(filtered.length) ? filtered.slice((safePage - 1) * perPage, safePage * perPage) : filtered
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
      walletKind ? `${t('kams.provenance')}: ${t(`kams.wallet.${walletKind}`, { defaultValue: walletKind })}` : null,
      from ? `${t('kams.dateFrom')}: ${from}` : null,
      to ? `${t('kams.dateTo')}: ${to}` : null,
    ].filter(Boolean).join(' · ')

    return {
      title,
      module: t('kams.finance'),
      subtitle: [subtitle, filters].filter(Boolean).join(' · ') || undefined,
      meta: [
        ...printMeta,
        { label: t('common.records'), value: String(filtered.length) },
        { label: t('common.printedOn'), value: new Date().toLocaleString() },
      ],
      headers: [
        t('kams.settlementDate'),
        t('kams.type'),
        ...(showProvenance ? [t('kams.provenance')] : []),
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
        ...(showProvenance ? [walletProvenance(entry, t) || '—'] : []),
        entry.holder || entry.member?.full_name || '—',
        formatLedgerNotes(entry, t) || '—',
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
  }, [type, status, walletKind, from, to, perPage])

  const filtersActive = Boolean(type || status || walletKind || from || to)
  const colSpan = (showMember ? 8 : 7) + (showProvenance ? 1 : 0)

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
        {showProvenance ? (
          <div className="min-w-0 lg:flex-1 lg:border-r lg:border-line lg:px-4">
            <Select className="min-w-0" label={t('kams.provenance')} value={walletKind} onChange={(e) => setWalletKind(e.target.value)}>
              <option value="">{t('common.all')}</option>
              {walletOptions.map((kind) => (
                <option key={kind} value={kind}>{t(`kams.wallet.${kind}`, { defaultValue: kind })}</option>
              ))}
            </Select>
          </div>
        ) : null}
        <div className="col-span-2 grid grid-cols-2 gap-3 lg:flex lg:min-w-0 lg:flex-[2] lg:gap-3 lg:pl-4">
          <Field className="min-w-0 max-w-full lg:flex-1" label={t('kams.dateFrom')} type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
          <Field className="min-w-0 max-w-full lg:flex-1" label={t('kams.dateTo')} type="date" value={to} onChange={(e) => setTo(e.target.value)} />
        </div>
      </div>
      ) : null}

      {pending.length > 0 ? (
        <div className="mb-4 space-y-2">
          <div className="text-sm font-semibold text-amber-900">{t('kams.pendingRequests')} ({pending.length})</div>
          {pending.map((entry) => (
            <TransactionRow
              key={entry.uid || entry.id}
              entry={entry}
              onDetails={() => setDetails(entry)}
              onDecide={onDecide && (canDecideEntry?.(entry) ?? true) ? openDecision : undefined}
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
                {showProvenance ? <th className="bg-lhms px-3 py-3 font-semibold">{t('kams.provenance')}</th> : null}
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
                  <td colSpan={colSpan} className="px-3 py-8 text-center text-muted">{t('common.empty')}</td>
                </tr>
              ) : visible.map((entry, index) => {
                const rejected = entry.status === 'rejected'
                return (
                <tr
                  key={entry.uid || entry.id}
                  className={cn(
                    'border-t border-line',
                    rejected
                      ? 'bg-red-50 text-red-600'
                      : entry.status === 'pending'
                        ? 'bg-amber-50'
                        : index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white',
                  )}
                >
                  <td className={cn('w-px whitespace-nowrap px-3', compact ? 'py-1.5' : 'py-3', rejected ? 'text-red-600' : 'text-muted')}>{dateTime(entry.occurred_on, entry.created_at)}</td>
                  <td className={cn('px-3 font-semibold whitespace-nowrap', compact ? 'py-1.5' : 'py-3', rejected && 'text-red-600')}>{t(`kams.ledger.${ledgerType(entry)}`, { defaultValue: entry.type })}</td>
                  {showProvenance ? (
                    <td className={cn('px-3 whitespace-nowrap', compact ? 'py-1.5' : 'py-3', rejected && 'text-red-600')}>
                      {walletProvenance(entry, t) || '—'}
                    </td>
                  ) : null}
                  {showMember ? <td className={cn('px-3 whitespace-nowrap', compact ? 'py-1.5' : 'py-3', rejected && 'text-red-600')}>{[entry.holder, entry.member?.full_name].filter(Boolean).join(' · ') || '—'}</td> : null}
                  <td className={cn('max-w-[16rem] truncate px-3', compact ? 'py-1.5' : 'py-3', rejected && 'text-red-600')}>{formatLedgerNotes(entry, t) || '—'}</td>
                  <td className={cn('px-3 whitespace-nowrap', compact ? 'py-1.5' : 'py-3')}>
                    <Badge tone={entry.status === 'posted' ? 'green' : entry.status === 'pending' ? 'amber' : 'red'}>
                      {t(`kams.status.${entry.status}`, { defaultValue: entry.status })}
                    </Badge>
                  </td>
                  <td className={cn('min-w-[8.5rem] px-5 text-right font-bold whitespace-nowrap', compact ? 'py-1.5' : 'py-3', rejected || entry.amount < 0 ? 'text-red-600' : 'text-lhms')}>{money(entry.amount)}</td>
                  <td className={cn('min-w-[8.5rem] px-5 text-right font-bold whitespace-nowrap', compact ? 'py-1.5' : 'py-3', rejected && 'text-red-600')}>{entry.balance_after == null ? '—' : money(entry.balance_after)}</td>
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
                )
              })}
            </tbody>
          </table>
        </div>
        {showListPagination(filtered.length) ? <div className="flex flex-col gap-3 border-t border-line px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
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
        </div> : null}
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
            <DetailLine label={t('kams.provenance')} value={walletProvenance(details, t) || undefined} />
            <DetailLine label={t('common.status')} value={t(`kams.status.${details.status}`, { defaultValue: details.status })} />
            <DetailLine label={t('kams.amount')} value={money(details.amount)} />
            <DetailLine label={t('kams.balance')} value={details.balance_after == null ? undefined : money(details.balance_after)} />
            <DetailLine label={t('kams.settlementDate')} value={dateTime(details.occurred_on, details.created_at)} />
            <DetailLine label={t('kams.account')} value={details.member ? `${details.member.full_name}${details.member.member_code ? ` · ${details.member.member_code}` : ''}` : undefined} />
            <DetailLine
              label={t('kams.originLabel')}
              value={details.source ? [t(`kams.sources.${details.source.type}`, { defaultValue: details.source.type || '' }), details.source.label].filter(Boolean).join(' · ') : undefined}
            />
            <DetailLine
              label={t('kams.paidToAssistant')}
              value={details.type === 'assistant_payout' && details.source?.beneficiary
                ? `${details.source.beneficiary.full_name}${details.source.beneficiary.member_code ? ` · ${details.source.beneficiary.member_code}` : ''}`
                : undefined}
            />
            <DetailLine
              label={t('kams.receivedFromManager')}
              value={details.type === 'revenue_share' && details.source?.paid_from
                ? `${details.source.paid_from.full_name}${details.source.paid_from.member_code ? ` · ${details.source.paid_from.member_code}` : ''}`
                : undefined}
            />
            <DetailLine label={t('kams.motif')} value={formatLedgerNotes(details, t) || undefined} />
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
  onDetails,
  onDecide,
}: {
  entry: LedgerEntry
  onDetails: () => void
  onDecide?: (entry: LedgerEntry, action: 'approve' | 'reject') => void
}) {
  const { t } = useTranslation()
  const kind = ledgerType(entry)
  const motif = formatLedgerNotes(entry, t)

  const provenance = walletProvenance(entry, t)
  const meta = [
    dateTime(entry.occurred_on, entry.created_at),
    motif || null,
    provenance || null,
    `${t('kams.requestedBy')}: ${entry.requested_by?.name || '—'}`,
  ].filter(Boolean).join(' · ')

  return (
    <div className="rounded-xl border border-amber-200 bg-amber-50 px-3 py-2.5 sm:px-3.5">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="min-w-0 truncate text-sm font-semibold text-amber-950">
              {t(`kams.ledger.${kind}`, { defaultValue: entry.type })}
            </span>
            <span className="shrink-0">
              <Badge tone={entry.status === 'posted' ? 'green' : entry.status === 'pending' ? 'amber' : 'red'}>
                {t(`kams.status.${entry.status}`, { defaultValue: entry.status })}
              </Badge>
            </span>
          </div>
          {entry.member?.full_name ? (
            <div className="mt-0.5 truncate text-sm font-medium text-amber-900/85">{entry.member.full_name}</div>
          ) : null}
        </div>
        <div className={cn('shrink-0 text-right text-sm font-bold tabular-nums sm:text-base', entry.amount < 0 ? 'text-red-600' : 'text-lhms')}>
          {money(entry.amount)}
        </div>
      </div>
      {meta ? (
        <div className="mt-0.5 w-full overflow-hidden text-ellipsis whitespace-nowrap text-xs text-amber-900/70" title={meta}>
          {meta}
        </div>
      ) : null}

      <div
        className={cn(
          'mt-2 grid gap-2',
          entry.status === 'pending' && onDecide
            ? 'grid-cols-[2.75rem_1fr_1fr] sm:flex sm:flex-wrap sm:justify-end'
            : 'grid-cols-1 sm:flex sm:justify-end',
        )}
      >
        <Button
          type="button"
          variant="secondary"
          className="h-11 min-h-11 w-11 shrink-0 !gap-0 !px-0 sm:h-9 sm:min-h-9 sm:w-9"
          aria-label={t('kams.transactionDetails')}
          onClick={onDetails}
        >
          <Info className="size-[1.15rem] sm:size-5" strokeWidth={2.25} />
        </Button>
        {entry.status === 'pending' && onDecide ? (
          <>
            <Button
              variant="secondary"
              className="h-11 min-h-11 w-full px-3 text-sm sm:h-9 sm:min-h-9 sm:w-auto sm:text-xs"
              onClick={() => onDecide(entry, 'approve')}
            >
              {t('common.approve')}
            </Button>
            <Button
              variant="ghost"
              className="h-11 min-h-11 w-full px-3 text-sm sm:h-9 sm:min-h-9 sm:w-auto sm:text-xs"
              onClick={() => onDecide(entry, 'reject')}
            >
              {t('common.reject')}
            </Button>
          </>
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
