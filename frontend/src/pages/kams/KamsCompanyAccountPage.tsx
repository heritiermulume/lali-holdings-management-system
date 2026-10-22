import { ArrowRightLeft, ChevronLeft, ChevronRight, Info, Pencil, Plus, Trash2, User } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Empty, Field, Modal } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money, roleLabel } from '../../lib/format'
import { KamsAccountTransactions, KamsTransactionsPrintMenu, type KamsAccountTransactionsHandle } from './KamsAccountTransactions'
import { KamsBankCard } from './KamsBankCard'

type Period = 'day' | 'week' | 'month' | 'custom'

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
    return { from: isoDate(new Date(to.getFullYear(), to.getMonth(), 1)), to: isoDate(to) }
  }
  const from = startOfWeekMonday(to)
  return { from: isoDate(from), to: isoDate(to) }
}

function entryDate(entry: { occurred_on?: string | null; created_at?: string | null }) {
  if (entry.occurred_on) return entry.occurred_on.slice(0, 10)
  if (entry.created_at) return entry.created_at.slice(0, 10)
  return ''
}

type Entry = {
  id: number
  type: string
  status: string
  amount: number
  occurred_on?: string | null
  created_at?: string | null
  notes?: string | null
  member?: { id: number; full_name: string; member_code?: string } | null
  created_by?: { name?: string } | null
}
type Summary = {
  id: number
  code: string
  name: string
  is_central: boolean
  office?: { id: number; name: string; code: string } | null
  balance: number
  available?: number
  pending_deposits?: number
  deposits: number
  withdrawals: number
  expenses: number
}
type MemberOption = {
  id: number
  full_name: string
  member_code: string
  balance: number
  office?: { id: number; name: string; code: string } | null
}
type Statement = {
  account: {
    id: number
    code: string
    name: string
    is_central: boolean
    office?: { id: number; name: string; code: string } | null
  }
  balance: number
  available?: number
  pending_deposits?: number
  deposits: number
  withdrawals: number
  expenses: number
  entries: Entry[]
  members: MemberOption[]
  can_operate: boolean
  can_decide: boolean
  can_deposit?: boolean
  can_withdraw?: boolean
  can_expense?: boolean
}

type Action = 'deposit' | 'withdraw' | 'expense'
type OfficeTab = 'transactions' | 'commissions' | 'charges'

type FixedCharge = {
  id: number
  name: string
  amount: number
  notes?: string | null
}

type CommissionMember = {
  id: number
  full_name: string
  member_code: string
  photo_url?: string | null
  rank?: string | null
  role?: { id: number; name: string; slug: string } | null
  office?: { id: number; name: string; code: string } | null
}

type CommissionBeneficiary = {
  member: CommissionMember
  amount: number
  share_count: number
  pending_transfer: boolean
  can_transfer: boolean
}

type CommissionEntry = {
  id: number
  rule_code: string
  amount: number
  base_amount: number
  percent: number
  formula?: string | null
  status: string
  occurred_on?: string | null
  sale?: { id: number; reference: string } | null
  product?: { id: number; name: string; sku?: string | null } | null
}

type CommissionsPayload = {
  beneficiaries: CommissionBeneficiary[]
  total: number
  can_transfer: boolean
}

function todayIso() {
  const now = new Date()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${now.getFullYear()}-${month}-${day}`
}

export function KamsCompanyAccountPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Summary[]>([])
  const [data, setData] = useState<Statement | null>(null)
  const [selected, setSelected] = useState<number | null>(null)
  const [officeTab, setOfficeTab] = useState<OfficeTab>('transactions')
  const [commissions, setCommissions] = useState<CommissionsPayload | null>(null)
  const [charges, setCharges] = useState<FixedCharge[]>([])
  const [chargesMonthly, setChargesMonthly] = useState(0)
  const [canManageCharges, setCanManageCharges] = useState(false)
  const [chargeForm, setChargeForm] = useState<FixedCharge | null>(null)
  const [detailMember, setDetailMember] = useState<CommissionMember | null>(null)
  const [detailEntries, setDetailEntries] = useState<CommissionEntry[]>([])
  const [detailAmount, setDetailAmount] = useState(0)
  const [detailLoading, setDetailLoading] = useState(false)
  const [transferTarget, setTransferTarget] = useState<CommissionMember | null>(null)
  const [transferAmount, setTransferAmount] = useState('')
  const [transferAvailable, setTransferAvailable] = useState(0)
  const initialRange = useMemo(() => presetRange('week'), [])
  const today = useMemo(() => isoDate(new Date()), [])
  const [period, setPeriod] = useState<Period>('week')
  const [from, setFrom] = useState(initialRange.from)
  const [to, setTo] = useState(initialRange.to)
  const [action, setAction] = useState<Action | null>(null)
  const [memberId, setMemberId] = useState('')
  const [amount, setAmount] = useState('')
  const [occurredOn, setOccurredOn] = useState(todayIso())
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const scrollerRef = useRef<HTMLDivElement>(null)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)
  const [hasPrintableRows, setHasPrintableRows] = useState(false)
  const [canScrollLeft, setCanScrollLeft] = useState(false)
  const [canScrollRight, setCanScrollRight] = useState(false)

  const loadList = () => {
    return api.get('/modules/kams/finance/company-accounts').then((res) => {
      const next = (res.data.data || []) as Summary[]
      setRows(next)
      const stillVisible = selected != null && next.some((row) => row.id === selected)
      if (!stillVisible) {
        setSelected(next[0]?.id ?? null)
      }
    }).catch(() => setRows([]))
  }

  const loadSelected = () => {
    if (!selected) {
      setData(null)
      setCommissions(null)
      return
    }
    return api.get(`/modules/kams/finance/company-accounts/${selected}`)
      .then((res) => setData(res.data.data))
      .catch(() => setData(null))
  }

  useEffect(() => { void loadList() }, [])

  const selectedRow = rows.find((row) => row.id === selected) || null
  const isOfficeAccount = Boolean(selectedRow && !selectedRow.is_central)

  useEffect(() => {
    void loadSelected()
  }, [selected])

  const reloadCommissions = () => {
    if (!selected || !isOfficeAccount) return
    return api.get(`/modules/kams/finance/company-accounts/${selected}/commissions`)
      .then((res) => setCommissions(res.data.data))
      .catch(() => setCommissions({ beneficiaries: [], total: 0, can_transfer: false }))
  }

  useEffect(() => {
    if (!selected || !isOfficeAccount) {
      setCommissions(null)
      if (!isOfficeAccount && officeTab === 'commissions') setOfficeTab('transactions')
      return
    }
    void reloadCommissions()
  }, [selected, isOfficeAccount])

  const reloadCharges = () => {
    if (!selected) return
    return api.get(`/modules/kams/finance/company-accounts/${selected}/fixed-charges`)
      .then((res) => {
        setCharges(res.data.data?.charges || [])
        setChargesMonthly(Number(res.data.data?.monthly_total || 0))
        setCanManageCharges(Boolean(res.data.data?.can_manage))
      })
      .catch(() => {
        setCharges([])
        setChargesMonthly(0)
      })
  }

  useEffect(() => {
    void reloadCharges()
  }, [selected])

  useEffect(() => {
    if (period === 'custom') return
    const range = presetRange(period)
    setFrom(range.from)
    setTo(range.to)
  }, [period])

  useLiveData(() => {
    void loadList()
    void loadSelected()
    if (selected && isOfficeAccount) void reloadCommissions()
  })

  const openCommissionDetail = (member: CommissionMember) => {
    if (!selected) return
    setDetailMember(member)
    setDetailEntries([])
    setDetailAmount(0)
    setDetailLoading(true)
    setError('')
    api.get(`/modules/kams/finance/company-accounts/${selected}/commissions/${member.id}`)
      .then((res) => {
        setDetailEntries(res.data.data.entries || [])
        setDetailAmount(Number(res.data.data.amount || 0))
      })
      .catch(() => {
        setDetailEntries([])
        setDetailAmount(0)
      })
      .finally(() => setDetailLoading(false))
  }

  const openTransfer = (member: CommissionMember, available: number) => {
    setError('')
    setTransferTarget(member)
    setTransferAvailable(available)
    setTransferAmount(available > 0 ? available.toFixed(2) : '')
  }

  const saveCharge = async (event: FormEvent) => {
    event.preventDefault()
    if (!selected || !chargeForm) return
    setSaving(true)
    setError('')
    try {
      const payload = {
        name: chargeForm.name,
        amount: Number(chargeForm.amount),
        notes: chargeForm.notes || null,
      }
      const url = `/modules/kams/finance/company-accounts/${selected}/fixed-charges`
      await (chargeForm.id
        ? api.put(`${url}/${chargeForm.id}`, payload)
        : api.post(url, payload))
      setChargeForm(null)
      void reloadCharges()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const removeCharge = async (charge: FixedCharge) => {
    if (!selected) return
    setSaving(true)
    setError('')
    try {
      await api.delete(`/modules/kams/finance/company-accounts/${selected}/fixed-charges/${charge.id}`)
      void reloadCharges()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const transferCommission = async (event: FormEvent) => {
    event.preventDefault()
    if (!selected || !transferTarget) return
    const value = Number(transferAmount)
    if (!Number.isFinite(value) || value <= 0) {
      setError(t('kams.amount'))
      return
    }
    if (value > transferAvailable + 0.001) {
      setError(t('kams.commissionTransferExceeds', { balance: money(transferAvailable) }))
      return
    }
    setError('')
    setSaving(true)
    try {
      const res = await api.post(`/modules/kams/finance/company-accounts/${selected}/commissions/${transferTarget.id}/transfer`, {
        amount: value,
      })
      if (res.data.data?.statement) setData(res.data.data.statement)
      if (res.data.data?.commissions) setCommissions(res.data.data.commissions)
      else reloadCommissions()
      loadList()
      setTransferTarget(null)
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const cardButtons = (el: HTMLDivElement) =>
    Array.from(el.children).filter((node): node is HTMLButtonElement => node instanceof HTMLButtonElement)

  const updateScrollButtons = () => {
    const el = scrollerRef.current
    if (!el) return
    const max = Math.max(0, el.scrollWidth - el.clientWidth)
    setCanScrollLeft(el.scrollLeft > 4)
    setCanScrollRight(el.scrollLeft < max - 4)
  }

  useEffect(() => {
    const el = scrollerRef.current
    if (!el) return
    const update = () => updateScrollButtons()
    update()
    const frame = requestAnimationFrame(update)
    const timer = window.setTimeout(update, 120)
    el.addEventListener('scroll', update, { passive: true })
    window.addEventListener('resize', update)
    const observer = new ResizeObserver(update)
    observer.observe(el)
    cardButtons(el).forEach((card) => observer.observe(card))
    return () => {
      cancelAnimationFrame(frame)
      window.clearTimeout(timer)
      el.removeEventListener('scroll', update)
      window.removeEventListener('resize', update)
      observer.disconnect()
    }
  }, [rows.length])

  const scrollCards = (direction: -1 | 1) => {
    const el = scrollerRef.current
    if (!el) return
    const cards = cardButtons(el)
    if (cards.length === 0) return
    const pad = Number.parseFloat(getComputedStyle(el).paddingLeft) || 0
    const current = cards.reduce((best, card, index) => {
      const dist = Math.abs(card.offsetLeft - pad - el.scrollLeft)
      return dist < best.dist ? { index, dist } : best
    }, { index: 0, dist: Number.POSITIVE_INFINITY })
    const next = Math.min(cards.length - 1, Math.max(0, current.index + direction))
    el.scrollTo({ left: Math.max(0, cards[next].offsetLeft - pad), behavior: 'smooth' })
  }

  const ledgerBalance = Math.max(0, Number(data?.balance ?? 0))
  const pendingDeposits = Math.max(0, Number(data?.pending_deposits ?? 0))
  // Withdraw/expense/deposit-to-principal cannot use amounts reserved by pending principal deposits.
  const spendable = data?.account.is_central
    ? Math.max(0, Number(data.available ?? data.balance ?? 0))
    : Math.max(0, Math.min(
      Number(data?.available ?? ledgerBalance),
      ledgerBalance - pendingDeposits,
    ))

  const cashSummary = useMemo(() => {
    const rangeFrom = from || ''
    const rangeTo = to || ''
    let cashIn = 0
    let cashOut = 0
    for (const entry of data?.entries || []) {
      const day = entryDate(entry)
      if (rangeFrom && day && day < rangeFrom) continue
      if (rangeTo && day && day > rangeTo) continue
      const amount = Number(entry.amount || 0)
      const status = entry.status || 'posted'
      if (status === 'rejected' || status === 'void') continue

      // Posted credits / debits. A commission transfer is posted, and deducted, only when the beneficiary accepts it.
      if (status === 'posted') {
        if (amount > 0) cashIn += amount
        else if (amount < 0) cashOut += Math.abs(amount)
      }
    }

    // Unpaid office commissions (not yet transferred) count as outflows.
    if (isOfficeAccount) {
      cashOut += Math.max(0, Number(commissions?.total || 0))
    }

    cashIn = Math.round(cashIn * 100) / 100
    cashOut = Math.round(cashOut * 100) / 100
    return {
      cashIn,
      cashOut,
      balance: Math.round((cashIn - cashOut) * 100) / 100,
    }
  }, [data?.entries, commissions?.total, from, to, isOfficeAccount])

  const applyPreset = (next: Exclude<Period, 'custom'>) => {
    const range = presetRange(next)
    setPeriod(next)
    setFrom(range.from)
    setTo(range.to)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!action || !selected || !data) return
    const member = data.members?.find((item) => String(item.id) === memberId)
    const cap = member ? Math.min(spendable, member.balance) : spendable
    if (action === 'deposit' && Number(amount) > spendable + 0.001) {
      setError(t('kams.depositExceedsOfficeBalance', { balance: money(spendable) }))
      return
    }
    if ((action === 'expense' || action === 'withdraw') && Number(amount) > cap + 0.001) {
      setError(member && Number(amount) > member.balance + 0.001
        ? t('kams.memberExceedsBalance', { balance: money(member.balance) })
        : t('kams.expenseExceedsBalance', { balance: money(spendable) }))
      return
    }
    setError('')
    setSaving(true)
    try {
      const payload = action === 'deposit'
        ? {
            amount: Number(amount),
            occurred_on: occurredOn,
            notes: notes.trim() || undefined,
          }
        : {
            amount: Number(amount),
            notes,
            member_id: memberId || undefined,
          }
      const res = await api.post(`/modules/kams/finance/company-accounts/${selected}/${action}`, payload)
      setData(res.data.data)
      setAction(null)
      setMemberId('')
      setAmount('')
      setOccurredOn(todayIso())
      setNotes('')
      loadList()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const decide = async (id: number, decision: 'approve' | 'reject', notesValue: string) => {
    if (!selected) return
    setError('')
    try {
      const res = await api.post(`/modules/kams/finance/company-accounts/${selected}/entries/${id}/${decision}`, {
        notes: notesValue,
      })
      setData(res.data.data)
      loadList()
      if (isOfficeAccount) reloadCommissions()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const titles: Record<Action, string> = {
    deposit: t('kams.depositToPrincipal'),
    withdraw: t('kams.makeWithdrawal'),
    expense: t('kams.recordExpense'),
  }
  const selectedMember = data?.members?.find((item) => String(item.id) === memberId)
  const operationCap = action === 'deposit'
    ? spendable
    : selectedMember
      ? Math.min(spendable, selectedMember.balance)
      : spendable
  const amountTooHigh = Boolean(action) && amount !== '' && Number(amount) > operationCap + 0.001
  const officeItem: SearchComboboxItem = {
    id: 'office',
    label: data?.account.is_central ? t('kams.centralPoolAccount') : t('kams.officePoolAccount'),
  }
  const memberItems: SearchComboboxItem[] = [
    officeItem,
    ...(data?.members || []).map((item) => ({
      id: String(item.id),
      label: item.full_name,
      hint: `${item.member_code}${item.office?.name ? ` · ${item.office.name}` : ''} · ${money(item.balance)}`,
    })),
  ]
  const memberValue = memberItems.find((item) => item.id === (memberId || 'office')) ?? officeItem

  const openAction = (next: Action) => {
    setAction(next)
    setError('')
    setAmount('')
    setOccurredOn(todayIso())
    setNotes('')
    setMemberId('')
  }

  return (
    <div className="space-y-5">
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
        <div className="relative">
          <button
            type="button"
            aria-label={t('kams.scrollCardsLeft')}
            disabled={!canScrollLeft}
            onClick={() => scrollCards(-1)}
            className="absolute left-2 top-1/2 z-20 hidden h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-lhms shadow-[0_8px_24px_rgba(4,55,17,0.16)] disabled:pointer-events-none disabled:opacity-30 md:grid"
          >
            <ChevronLeft size={20} />
          </button>
          <div
            ref={scrollerRef}
            className="flex gap-4 overflow-x-auto overflow-y-hidden scroll-smooth rounded-[1.4rem] border border-line bg-white px-5 py-5 snap-x snap-proximity scroll-px-5 md:px-16 md:scroll-px-16"
          >
            {rows.map((row) => {
              const active = selected === row.id
              return (
                <button
                  key={row.id}
                  type="button"
                  onClick={() => setSelected(row.id)}
                  className={cn(
                    'min-h-56 w-[min(24rem,calc(100vw-7rem))] shrink-0 snap-start rounded-[1.4rem] text-left',
                    active && 'ring-2 ring-lhms ring-offset-2 ring-offset-surface',
                  )}
                >
                  <KamsBankCard
                    kind={row.is_central ? 'central' : 'office'}
                    holder={row.is_central ? 'KAMS' : (row.office?.name || row.name)}
                    subtitle={row.is_central ? t('kams.centralAccount') : t('kams.officeAccount')}
                    balance={row.balance}
                    pendingDeposits={
                      data?.account.id === row.id
                        ? (data.pending_deposits || row.pending_deposits || 0)
                        : (row.pending_deposits || 0)
                    }
                    pendingDepositsLabel={t('kams.pendingDepositRequest')}
                  />
                </button>
              )
            })}
          </div>
          <button
            type="button"
            aria-label={t('kams.scrollCardsRight')}
            disabled={!canScrollRight}
            onClick={() => scrollCards(1)}
            className="absolute right-2 top-1/2 z-20 hidden h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-lhms shadow-[0_8px_24px_rgba(4,55,17,0.16)] disabled:pointer-events-none disabled:opacity-30 md:grid"
          >
            <ChevronRight size={20} />
          </button>
        </div>
      )}

      {data ? (
        <>
          <div className="space-y-3">
            <div className="flex w-full min-w-0 flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-muted">{t('kams.cashPeriodHint')}</p>
              <div className="flex min-w-0 w-full overflow-x-auto rounded-full bg-surface p-1 sm:w-auto sm:ml-auto">
                {(['day', 'week', 'month'] as const).map((item) => (
                  <button
                    key={item}
                    type="button"
                    onClick={() => applyPreset(item)}
                    className={cn(
                      'min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none',
                      period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink',
                    )}
                  >
                    {t(`kams.period.${item}`)}
                  </button>
                ))}
                <button
                  type="button"
                  onClick={() => setPeriod('custom')}
                  className={cn(
                    'min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none',
                    period === 'custom' ? 'bg-lhms text-white' : 'text-muted hover:text-ink',
                  )}
                >
                  {t('dashboard.customPeriod')}
                </button>
              </div>
            </div>
            {period === 'custom' ? (
              <div className="grid w-full grid-cols-2 gap-2 sm:ml-auto sm:w-80">
                <Field className="min-w-0" label={t('dashboard.dateFrom')} type="date" value={from} max={to || today} onChange={(e) => setFrom(e.target.value)} />
                <Field className="min-w-0" label={t('dashboard.dateTo')} type="date" value={to} min={from} max={today} onChange={(e) => setTo(e.target.value)} />
              </div>
            ) : null}
            <div className="grid grid-cols-2 gap-2 sm:gap-3">
              <Card className="p-3 sm:p-4">
                <div className="text-xs text-muted sm:text-sm">{t('kams.cashIn')}</div>
                <div className="mt-1 text-lg font-extrabold tabular-nums text-lhms sm:mt-2 sm:text-2xl">{money(cashSummary.cashIn)}</div>
              </Card>
              <Card className="p-3 sm:p-4">
                <div className="text-xs text-muted sm:text-sm">{t('kams.cashOut')}</div>
                <div className="mt-1 text-lg font-extrabold tabular-nums text-danger sm:mt-2 sm:text-2xl">{money(cashSummary.cashOut)}</div>
              </Card>
              <Card className="p-3 sm:p-4">
                <div className="text-xs text-muted sm:text-sm">{t('kams.balance')}</div>
                <div className={cn(
                  'mt-1 text-lg font-extrabold tabular-nums sm:mt-2 sm:text-2xl',
                  cashSummary.balance >= 0 ? 'text-lhms' : 'text-danger',
                )}>
                  {money(cashSummary.balance)}
                </div>
              </Card>
              <Card className="p-3 sm:p-4">
                <div className="text-xs text-muted sm:text-sm">{t('kams.fixedCharges')}</div>
                <div className="mt-1 text-lg font-extrabold tabular-nums sm:mt-2 sm:text-2xl">{money(chargesMonthly)}</div>
              </Card>
            </div>
          </div>

          {selected ? (
            <nav className="flex flex-wrap gap-2">
              {([
                { id: 'transactions' as const, label: t('kams.transactions') },
                ...(isOfficeAccount ? [{ id: 'commissions' as const, label: t('kams.officeCommissions') }] : []),
                { id: 'charges' as const, label: t('kams.fixedCharges') },
              ]).map((tab) => (
                <button
                  key={tab.id}
                  type="button"
                  onClick={() => setOfficeTab(tab.id)}
                  className={cn(
                    'inline-flex min-h-10 items-center rounded-full px-3 text-xs font-semibold whitespace-nowrap sm:px-4 sm:text-sm',
                    officeTab === tab.id ? 'bg-lhms text-white' : 'bg-white text-muted',
                  )}
                >
                  {tab.label}
                </button>
              ))}
            </nav>
          ) : null}

          {officeTab === 'charges' ? (
            <div className="space-y-4">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                  <h3 className="text-base font-semibold text-ink">{t('kams.fixedCharges')}</h3>
                </div>
                <div className="flex items-center gap-2">
                  {canManageCharges ? (
                    <Button onClick={() => { setError(''); setChargeForm({ id: 0, name: '', amount: 0, notes: '' }) }}>
                      <Plus size={16} /> {t('kams.fixedChargeAdd')}
                    </Button>
                  ) : null}
                </div>
              </div>
              {error && !action && !chargeForm ? <p className="text-sm text-red-600">{error}</p> : null}
              {charges.length === 0 ? (
                <Empty>{t('kams.fixedChargesEmpty')}</Empty>
              ) : (
                <div className="overflow-hidden rounded-2xl border border-line bg-white">
                  <table className="w-full text-sm">
                    <thead className="bg-emerald-50/80 text-left text-xs uppercase tracking-wide text-muted">
                      <tr>
                        <th className="px-4 py-3 font-semibold">{t('kams.fixedChargeName')}</th>
                        <th className="px-4 py-3 text-right font-semibold">{t('kams.amount')}</th>
                        <th className="px-4 py-3 font-semibold">{t('kams.notes')}</th>
                        <th className="px-4 py-3 text-right font-semibold">{t('common.actions')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {charges.map((charge) => (
                        <tr key={charge.id} className="border-t border-line">
                          <td className="px-4 py-3 font-semibold">{charge.name}</td>
                          <td className="px-4 py-3 text-right tabular-nums">{money(charge.amount)}</td>
                          <td className="px-4 py-3 text-muted">{charge.notes || '—'}</td>
                          <td className="px-4 py-3">
                            {canManageCharges ? (
                              <div className="flex justify-end gap-1">
                                <Button variant="ghost" className="!h-8 !min-h-0 w-8 !p-0" aria-label={t('common.edit')} onClick={() => { setError(''); setChargeForm(charge) }}>
                                  <Pencil size={16} />
                                </Button>
                                <Button variant="ghost" className="!h-8 !min-h-0 w-8 !p-0 text-danger" aria-label={t('common.delete')} onClick={() => void removeCharge(charge)} disabled={saving}>
                                  <Trash2 size={16} />
                                </Button>
                              </div>
                            ) : null}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          ) : (!isOfficeAccount || officeTab === 'transactions') ? (
            <>
              <div className="flex flex-wrap items-center gap-2">
                {data.can_deposit && hasPermission('kams.finance.company.deposit') ? (
                  <Button
                    disabled={spendable <= 0.001}
                    onClick={() => openAction('deposit')}
                  >
                    {t('kams.depositToPrincipal')}
                  </Button>
                ) : null}
                {data.can_withdraw && hasPermission('kams.finance.company.withdraw') ? (
                  <Button variant="secondary" disabled={spendable <= 0.001} onClick={() => openAction('withdraw')}>{t('kams.makeWithdrawal')}</Button>
                ) : null}
                {data.can_expense && hasPermission('kams.finance.company.expense') ? (
                  <Button variant="accent" disabled={spendable <= 0.001} onClick={() => openAction('expense')}>{t('kams.ledger.expense')}</Button>
                ) : null}
                <div className="ml-auto">
                  <KamsTransactionsPrintMenu
                    canPreview={hasPermission('kams.finance.company.preview')}
                    canPrint={hasPermission('kams.finance.company.print')}
                    onPreview={() => transactionsRef.current?.preview()}
                    onPrint={() => transactionsRef.current?.print()}
                    disabled={!hasPrintableRows}
                  />
                </div>
              </div>

              {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

              <KamsAccountTransactions
                ref={transactionsRef}
                key={data.account.id}
                title={t('kams.transactionsFor', { holder: data.account.is_central ? t('kams.principalBank') : (data.account.office?.name || data.account.name) })}
                subtitle={data.account.is_central ? t('kams.centralAccount') : data.account.name}
                entries={data.entries}
                dateFrom={from}
                dateTo={to}
                printMeta={[
                  {
                    label: period === 'custom'
                      ? t('dashboard.customPeriod')
                      : t(`kams.period.${period}`),
                    value: t('kams.periodRange', {
                      start: from ? new Date(`${from}T12:00:00`).toLocaleDateString() : '—',
                      end: to ? new Date(`${to}T12:00:00`).toLocaleDateString() : '—',
                    }),
                  },
                  { label: t('kams.cashIn'), value: money(cashSummary.cashIn) },
                  { label: t('kams.cashOut'), value: money(cashSummary.cashOut) },
                  { label: t('kams.balance'), value: money(cashSummary.balance) },
                  { label: t('kams.fixedCharges'), value: money(chargesMonthly) },
                ]}
                onDecide={data.can_decide && hasPermission('kams.finance.company.approve') ? (id, decision, notesValue) => void decide(id, decision, notesValue) : undefined}
                canDecideEntry={(entry) => {
                  if (entry.type === 'commission_transfer') return false
                  return entry.type !== 'transfer_deposit' || data.account.is_central
                }}
                onRowsChange={(count) => setHasPrintableRows(count > 0)}
              />
            </>
          ) : (
            <div className="space-y-4">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                  <h3 className="text-base font-semibold text-ink">{t('kams.officeCommissions')}</h3>
                  <p className="text-sm text-muted">{t('kams.officeCommissionsHint')}</p>
                </div>
                <Card className="px-4 py-3 text-sm">
                  {t('kams.officeCommissionsTotal')}: <b className="tabular-nums">{money(commissions?.total || 0)}</b>
                </Card>
              </div>

              {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

              {!commissions ? <LoadingScreen /> : (commissions.beneficiaries || []).length === 0 ? (
                <Empty>{t('kams.commissionNoBeneficiaries')}</Empty>
              ) : (
                <div className="overflow-hidden rounded-2xl border border-line bg-white">
                  <table className="w-full text-sm">
                    <thead className="bg-emerald-50/80 text-left text-xs uppercase tracking-wide text-muted">
                      <tr>
                        <th className="px-4 py-3 font-semibold">{t('kams.cardHolder')}</th>
                        <th className="px-4 py-3 font-semibold">{t('kams.commissionProfile')}</th>
                        <th className="px-4 py-3 text-right font-semibold">{t('kams.commissionAmountDue')}</th>
                        <th className="px-4 py-3 text-right font-semibold">{t('common.actions')}</th>
                      </tr>
                    </thead>
                    <tbody>
                      {commissions.beneficiaries.map((row) => {
                        const profile = row.member.role
                          ? roleLabel(t, row.member.role)
                          : (row.member.rank || '—')
                        return (
                          <tr key={row.member.id} className="border-t border-line">
                            <td className="px-4 py-3">
                              <div className="flex items-center gap-3">
                                <span className="grid h-10 w-10 shrink-0 place-items-center overflow-hidden rounded-full bg-lhms-soft text-lhms">
                                  {row.member.photo_url ? (
                                    <img src={row.member.photo_url} alt="" className="h-full w-full object-cover" />
                                  ) : (
                                    <User size={18} />
                                  )}
                                </span>
                                <div className="min-w-0">
                                  <div className="truncate font-semibold text-ink">{row.member.full_name}</div>
                                  <div className="truncate text-xs text-muted">{row.member.member_code}</div>
                                </div>
                              </div>
                            </td>
                            <td className="px-4 py-3 text-muted">{profile}</td>
                            <td className="px-4 py-3 text-right font-semibold tabular-nums">{money(row.amount)}</td>
                            <td className="px-4 py-3">
                              <div className="flex justify-end gap-1.5">
                                <Button
                                  variant="ghost"
                                  className="!h-8 !min-h-0 w-8 !p-0"
                                  aria-label={t('kams.commissionDetails')}
                                  onClick={() => openCommissionDetail(row.member)}
                                >
                                  <Info size={18} />
                                </Button>
                                {commissions.can_transfer && hasPermission('kams.finance.company.deposit') ? (
                                  <Button
                                    variant="secondary"
                                    className="!h-8 !min-h-0 gap-1.5 !px-2.5"
                                    disabled={!row.can_transfer || saving}
                                    title={row.pending_transfer ? t('kams.commissionTransferPending') : t('kams.commissionTransfer')}
                                    onClick={() => openTransfer(row.member, row.amount)}
                                  >
                                    <ArrowRightLeft size={16} />
                                    <span className="hidden sm:inline">{row.pending_transfer ? t('kams.commissionTransferPending') : t('kams.commissionTransfer')}</span>
                                  </Button>
                                ) : null}
                              </div>
                            </td>
                          </tr>
                        )
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}
        </>
      ) : selected ? <LoadingScreen /> : null}

      <Modal open={Boolean(action)} title={action ? titles[action] : ''} onClose={() => setAction(null)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {action === 'deposit' ? (
            <p className="text-sm text-muted">{t('kams.depositToPrincipalHint')}</p>
          ) : (
            <SearchCombobox
              label={t('kams.account')}
              value={memberValue}
              items={memberItems}
              placeholder={t('kams.account')}
              emptyLabel={t('common.empty')}
              onSelect={(item) => {
                setMemberId(!item || item.id === 'office' ? '' : item.id)
                setError('')
              }}
              required
            />
          )}
          <Field label={t('kams.amount')}
            type="number"
            min="0.01"
            step="0.01"
            max={Math.max(0, operationCap).toFixed(2)}
            value={amount}
            onChange={(e) => {
              const next = e.target.value
              setAmount(next)
              if (next !== '' && Number(next) > operationCap + 0.001) {
                setError(action === 'deposit'
                  ? t('kams.depositExceedsOfficeBalance', { balance: money(spendable) })
                  : selectedMember && Number(next) > selectedMember.balance + 0.001
                    ? t('kams.memberExceedsBalance', { balance: money(selectedMember.balance) })
                    : t('kams.expenseExceedsBalance', { balance: money(spendable) }))
              } else {
                setError('')
              }
            }}
            required
          />
          {action ? (
            <p className="text-xs text-muted">
              {action === 'deposit'
                ? t('kams.officeBalanceAvailable', { balance: money(spendable) })
                : t('kams.spendableAvailable', { balance: money(spendable) })}
              {pendingDeposits > 0.001 && !data?.account.is_central
                ? ` · ${t('kams.spendableAvailableHint', { balance: money(ledgerBalance), pending: money(pendingDeposits) })}`
                : null}
            </p>
          ) : null}
          {action === 'deposit' ? (
            <Field
              label={t('common.date')}
              type="date"
              value={occurredOn}
              onChange={(e) => setOccurredOn(e.target.value)}
              required
            />
          ) : null}
          <Field
            label={t('kams.motif')}
            value={notes}
            onChange={(e) => setNotes(e.target.value)}
            required={action !== 'deposit'}
          />
          <Button type="submit" className="w-full" disabled={saving || amountTooHigh || (action === 'deposit' && spendable <= 0.001)}>
            {saving ? t('common.loading') : action === 'deposit' ? t('kams.depositAction') : t('common.save')}
          </Button>
        </form>
      </Modal>

      <Modal
        open={Boolean(detailMember)}
        title={t('kams.commissionDetails')}
        onClose={() => setDetailMember(null)}
      >
        {detailMember ? (
          <div className="space-y-4">
            <div className="flex items-center gap-3">
              <span className="grid h-12 w-12 shrink-0 place-items-center overflow-hidden rounded-full bg-lhms-soft text-lhms">
                {detailMember.photo_url ? (
                  <img src={detailMember.photo_url} alt="" className="h-full w-full object-cover" />
                ) : (
                  <User size={20} />
                )}
              </span>
              <div className="min-w-0">
                <div className="font-semibold text-ink">{detailMember.full_name}</div>
                <div className="text-sm text-muted">
                  {detailMember.role ? roleLabel(t, detailMember.role) : (detailMember.rank || detailMember.member_code)}
                </div>
              </div>
              <div className="ml-auto text-right">
                <div className="text-xs text-muted">{t('kams.commissionAmountDue')}</div>
                <div className="text-lg font-bold tabular-nums">{money(detailAmount)}</div>
              </div>
            </div>

            {detailLoading ? <LoadingScreen /> : detailEntries.length === 0 ? (
              <Empty>{t('common.empty')}</Empty>
            ) : (
              <div className="max-h-[50vh] space-y-2 overflow-y-auto">
                {detailEntries.map((entry) => (
                  <Card key={entry.id} className="space-y-1 p-3">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0">
                        <div className="font-medium text-ink">
                          {t(`kams.rule.${entry.rule_code}`, { defaultValue: entry.rule_code })}
                        </div>
                        <div className="text-xs text-muted">
                          {[
                            entry.occurred_on,
                            entry.sale?.reference ? `${t('kams.commissionSale')} ${entry.sale.reference}` : null,
                            entry.product?.name,
                          ].filter(Boolean).join(' · ')}
                        </div>
                        {entry.formula ? <div className="text-xs text-muted">{entry.formula}</div> : null}
                      </div>
                      <div className="shrink-0 text-right">
                        <div className="font-semibold tabular-nums">{money(entry.amount)}</div>
                        <div className="text-xs text-muted">
                          {t('kams.commissionBase')}: {money(entry.base_amount)} · {entry.percent}%
                        </div>
                      </div>
                    </div>
                  </Card>
                ))}
              </div>
            )}
          </div>
        ) : null}
      </Modal>

      <Modal
        open={Boolean(transferTarget)}
        title={t('kams.commissionTransfer')}
        onClose={() => { if (!saving) setTransferTarget(null) }}
      >
        <form className="space-y-3" onSubmit={(event) => void transferCommission(event)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <p className="text-sm text-muted">
            {t('kams.commissionTransferHint', { name: transferTarget?.full_name || '' })}
          </p>
          <Field
            label={t('kams.amount')}
            type="number"
            min="0.01"
            step="0.01"
            max={Math.max(0, transferAvailable).toFixed(2)}
            value={transferAmount}
            onChange={(e) => {
              const next = e.target.value
              setTransferAmount(next)
              if (next !== '' && Number(next) > transferAvailable + 0.001) {
                setError(t('kams.commissionTransferExceeds', { balance: money(transferAvailable) }))
              } else {
                setError('')
              }
            }}
            required
          />
          <p className="text-xs text-muted">{t('kams.commissionTransferAvailable', { balance: money(transferAvailable) })}</p>
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" disabled={saving} onClick={() => setTransferTarget(null)}>{t('common.cancel')}</Button>
            <Button type="submit" disabled={saving || transferAvailable <= 0.001}>{t('kams.commissionTransfer')}</Button>
          </div>
        </form>
      </Modal>

      <Modal
        open={Boolean(chargeForm)}
        title={chargeForm?.id ? t('kams.fixedChargeEdit') : t('kams.fixedChargeAdd')}
        onClose={() => { if (!saving) setChargeForm(null) }}
      >
        <form className="space-y-3" onSubmit={(event) => void saveCharge(event)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          <Field label={t('kams.fixedChargeName')} value={chargeForm?.name || ''} onChange={(e) => setChargeForm((current) => current ? { ...current, name: e.target.value } : current)} required />
          <Field label={t('kams.amount')} type="number" min="0" step="0.01" value={chargeForm ? String(chargeForm.amount) : ''} onChange={(e) => setChargeForm((current) => current ? { ...current, amount: Number(e.target.value) } : current)} required />
          <Field label={t('kams.notes')} value={chargeForm?.notes || ''} onChange={(e) => setChargeForm((current) => current ? { ...current, notes: e.target.value } : current)} />
          <div className="flex justify-end gap-2">
            <Button type="button" variant="secondary" disabled={saving} onClick={() => setChargeForm(null)}>{t('common.cancel')}</Button>
            <Button type="submit" disabled={saving}>{t('common.save')}</Button>
          </div>
        </form>
      </Modal>
    </div>
  )
}
