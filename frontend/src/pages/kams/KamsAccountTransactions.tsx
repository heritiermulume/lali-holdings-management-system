import { Eye, Info, ListFilter, Printer } from 'lucide-react'
import { forwardRef, useEffect, useImperativeHandle, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { dateTime, money } from '../../lib/format'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'

const PAGE_SIZE = 8
const TYPE_FILTERS = ['deposit', 'withdrawal', 'expense', 'transfer', 'settlement', 'return', 'compensation'] as const

export type LedgerActor = { id?: number | null; name?: string } | null
export type LedgerEntry = {
  uid?: string
  id: number
  type: string
  status: string
  amount: number
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
  canPreview = true,
  canPrint = true,
}: {
  onPreview: () => void
  onPrint: () => void
  canPreview?: boolean
  canPrint?: boolean
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!menuRef.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  if (!canPreview && !canPrint) return null

  return (
    <div ref={menuRef} className="relative">
      <Button
        variant="secondary"
        aria-label={t('common.print')}
        aria-expanded={open}
        onClick={() => setOpen((value) => !value)}
      >
        <Printer size={16} /> {t('common.print')}
      </Button>
      {open ? (
        <div className="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
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
  onDecide?: (id: number, action: 'approve' | 'reject', notes: string) => void
}>(function KamsAccountTransactions({ title, subtitle, entries, showMember = true, onDecide }, ref) {
  const { t } = useTranslation()
  const [type, setType] = useState('')
  const [status, setStatus] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [showAll, setShowAll] = useState(false)
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
  }), [entries, from, onDecide, status, to, type])

  const visible = showAll ? filtered : filtered.slice(0, PAGE_SIZE)

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
      ],
      rows: filtered.map((entry) => [
        dateTime(entry.occurred_on, entry.created_at),
        t(`kams.ledger.${ledgerType(entry)}`, { defaultValue: entry.type }),
        entry.member?.full_name || '—',
        entry.notes || '—',
        entry.requested_by?.name || '—',
        entry.performed_by?.name || '—',
        t(`kams.status.${entry.status}`, { defaultValue: entry.status }),
        money(entry.amount),
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
    setShowAll(false)
  }, [entries])

  const filtersActive = Boolean(type || status || from || to)

  return (
    <Card className="p-5">
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
      <div className="mb-4 grid grid-cols-2 gap-3 [&>*]:min-w-0">
        <Select className="min-w-0" label={t('kams.type')} value={type} onChange={(e) => { setType(e.target.value); setShowAll(false) }}>
          <option value="">{t('common.all')}</option>
          {TYPE_FILTERS.map((item) => (
            <option key={item} value={item}>{t(`kams.ledger.${item}`)}</option>
          ))}
        </Select>
        <Select className="min-w-0" label={t('common.status')} value={status} onChange={(e) => { setStatus(e.target.value); setShowAll(false) }}>
          <option value="">{t('common.all')}</option>
          <option value="posted">{t('kams.status.posted')}</option>
          <option value="pending">{t('kams.status.pending')}</option>
          <option value="rejected">{t('kams.status.rejected')}</option>
        </Select>
        <Field className="min-w-0 max-w-full" label={t('kams.dateFrom')} type="date" value={from} onChange={(e) => { setFrom(e.target.value); setShowAll(false) }} />
        <Field className="min-w-0 max-w-full" label={t('kams.dateTo')} type="date" value={to} onChange={(e) => { setTo(e.target.value); setShowAll(false) }} />
      </div>
      ) : null}

      {onDecide && pending.length > 0 ? (
        <div className="mb-5 space-y-2">
          <div className="text-sm font-semibold">{t('kams.pendingRequests')} ({pending.length})</div>
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

      {filtered.length === 0 && !(onDecide && pending.length) ? <Empty>{t('common.empty')}</Empty> : filtered.length === 0 ? null : (
        <div className="space-y-2">
          {visible.map((entry) => (
            <TransactionRow
              key={entry.uid || entry.id}
              entry={entry}
              showMember={showMember}
              onDetails={() => setDetails(entry)}
              onDecide={onDecide ? openDecision : undefined}
            />
          ))}
        </div>
      )}

      {filtered.length > PAGE_SIZE ? (
        <Button variant="secondary" className="mt-4 w-full" onClick={() => setShowAll((value) => !value)}>
          {showAll ? t('kams.seeLess') : `${t('kams.seeAll')} (${filtered.length})`}
        </Button>
      ) : null}

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
    <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-surface px-4 py-3">
      <div className="min-w-0">
        <div className="font-semibold">
          {t(`kams.ledger.${kind}`, { defaultValue: entry.type })}
          {showMember && entry.member?.full_name ? ` · ${entry.member.full_name}` : ''}
        </div>
        <div className="text-sm text-muted">
          {dateTime(entry.occurred_on, entry.created_at)}
          {entry.notes ? ` · ${entry.notes}` : ''}
        </div>
        <div className="mt-1 text-xs text-muted">
          {t('kams.requestedBy')}: {entry.requested_by?.name || '—'}
          {' · '}
          {t('kams.performedBy')}: {entry.performed_by?.name || (entry.status === 'pending' ? t('kams.pendingActor') : '—')}
        </div>
      </div>
      <div className="flex items-center gap-3">
        <Badge tone={entry.status === 'posted' ? 'green' : entry.status === 'pending' ? 'amber' : 'red'}>
          {t(`kams.status.${entry.status}`, { defaultValue: entry.status })}
        </Badge>
        <div className={entry.amount < 0 ? 'font-bold text-red-600' : 'font-bold text-lhms'}>{money(entry.amount)}</div>
        <Button
          type="button"
          variant="secondary"
          className="w-11 px-0"
          aria-label={t('kams.transactionDetails')}
          onClick={onDetails}
        >
          <Info size={16} />
        </Button>
        {entry.status === 'pending' && onDecide ? (
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => onDecide(entry, 'approve')}>{t('common.approve')}</Button>
            <Button variant="ghost" onClick={() => onDecide(entry, 'reject')}>{t('common.reject')}</Button>
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
