import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Field, Modal } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'
import { KamsAccountTransactions, KamsTransactionsPrintMenu, type KamsAccountTransactionsHandle } from './KamsAccountTransactions'
import { KamsAgentAccountModal } from './KamsAgentAccountModal'
import { KamsBankCard } from './KamsBankCard'

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

export function KamsCompanyAccountPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Summary[]>([])
  const [data, setData] = useState<Statement | null>(null)
  const [selected, setSelected] = useState<number | null>(null)
  const [action, setAction] = useState<Action | null>(null)
  const [memberId, setMemberId] = useState('')
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const scrollerRef = useRef<HTMLDivElement>(null)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)
  const [canScrollLeft, setCanScrollLeft] = useState(false)
  const [canScrollRight, setCanScrollRight] = useState(false)
  const [agentModalOpen, setAgentModalOpen] = useState(false)
  const [agentMemberId, setAgentMemberId] = useState<number | null>(null)
  const [agentOfficeId, setAgentOfficeId] = useState<number | null>(null)

  const loadList = () => {
    api.get('/modules/kams/finance/company-accounts').then((res) => {
      const next = (res.data.data || []) as Summary[]
      setRows(next)
      if (!selected && next[0]) {
        setSelected(next[0].id)
      }
    }).catch(() => setRows([]))
  }

  useEffect(() => { loadList() }, [])

  useEffect(() => {
    if (!selected) {
      setData(null)
      return
    }
    api.get(`/modules/kams/finance/company-accounts/${selected}`)
      .then((res) => setData(res.data.data))
      .catch(() => setData(null))
  }, [selected])

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

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!action || !selected || !data) return
    const member = data.members?.find((item) => String(item.id) === memberId)
    const spendable = data.available ?? data.balance
    const cap = member ? Math.min(spendable, member.balance) : spendable
    if ((action === 'expense' || action === 'withdraw') && Number(amount) > cap + 0.001) {
      setError(member && Number(amount) > member.balance + 0.001
        ? t('kams.memberExceedsBalance', { balance: money(member.balance) })
        : t('kams.expenseExceedsBalance', { balance: money(spendable) }))
      return
    }
    setError('')
    setSaving(true)
    try {
      const res = await api.post(`/modules/kams/finance/company-accounts/${selected}/${action}`, {
        amount: Number(amount),
        notes,
        member_id: memberId || undefined,
      })
      setData(res.data.data)
      setAction(null)
      setMemberId('')
      setAmount('')
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
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const titles: Record<Action, string> = {
    deposit: t('kams.makeDeposit'),
    withdraw: t('kams.makeWithdrawal'),
    expense: t('kams.recordExpense'),
  }
  const selectedMember = data?.members?.find((item) => String(item.id) === memberId)
  const spendable = data?.available ?? data?.balance ?? 0
  const operationCap = selectedMember
    ? Math.min(spendable, selectedMember.balance)
    : spendable
  const amountTooHigh = (action === 'expense' || action === 'withdraw') && amount !== '' && Number(amount) > operationCap + 0.001
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
    setNotes('')
    setMemberId('')
  }

  return (
    <div className="space-y-5">
      <div>
        <h2 className="text-lg font-bold">{t('kams.companyAccount')}</h2>
        <p className="mt-1 text-sm text-muted">{t('kams.companyAccountHint')}</p>
      </div>

      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : (
        <div className="relative">
          <button
            type="button"
            aria-label={t('kams.scrollCardsLeft')}
            disabled={!canScrollLeft}
            onClick={() => scrollCards(-1)}
            className="absolute left-2 top-1/2 z-20 hidden h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-lhms shadow-[0_8px_24px_rgba(9,73,36,0.16)] disabled:pointer-events-none disabled:opacity-30 md:grid"
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
                    'h-56 w-[min(24rem,calc(100vw-7rem))] shrink-0 snap-start rounded-[1.4rem] text-left',
                    active && 'ring-2 ring-lhms ring-offset-2 ring-offset-surface',
                  )}
                >
                  <KamsBankCard
                    kind={row.is_central ? 'central' : 'office'}
                    holder={row.is_central ? 'KAMS' : (row.office?.name || row.name)}
                    subtitle={row.is_central ? t('kams.centralAccount') : t('kams.officeAccount')}
                    balance={row.balance}
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
            className="absolute right-2 top-1/2 z-20 hidden h-11 w-11 -translate-y-1/2 place-items-center rounded-full border border-line bg-white text-lhms shadow-[0_8px_24px_rgba(9,73,36,0.16)] disabled:pointer-events-none disabled:opacity-30 md:grid"
          >
            <ChevronRight size={20} />
          </button>
        </div>
      )}

      {data ? (
        <>
          <div className="flex flex-wrap items-center gap-2">
            {data.can_deposit ? (
              <Button onClick={() => openAction('deposit')}>{t('kams.makeDeposit')}</Button>
            ) : null}
            {data.can_withdraw ? (
              <Button variant="secondary" onClick={() => openAction('withdraw')}>{t('kams.makeWithdrawal')}</Button>
            ) : null}
            {data.can_expense ? (
              <Button variant="accent" onClick={() => openAction('expense')}>{t('kams.ledger.expense')}</Button>
            ) : null}
            {(hasPermission('kams.finance.account.view')
              || hasPermission('kams.finance.dashboard.view')
              || hasPermission('kams.finance.company.view')) ? (
              <Button
                variant="ghost"
                onClick={() => {
                  const officeScoped = !data.account.is_central
                  setAgentOfficeId(officeScoped ? (data.account.office?.id ?? null) : null)
                  setAgentMemberId(data.members[0]?.id ?? null)
                  setAgentModalOpen(true)
                }}
              >
                {t('kams.agentAccounts')}
              </Button>
            ) : null}
            <div className="ml-auto">
              <KamsTransactionsPrintMenu
                canPreview={hasPermission('kams.finance.company.preview')}
                canPrint={hasPermission('kams.finance.company.print')}
                onPreview={() => transactionsRef.current?.preview()}
                onPrint={() => transactionsRef.current?.print()}
              />
            </div>
          </div>

          {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

          <KamsAccountTransactions
            ref={transactionsRef}
            key={data.account.id}
            title={t('kams.transactionsFor', { holder: data.account.is_central ? 'KAMS' : (data.account.office?.name || data.account.name) })}
            subtitle={data.account.name}
            entries={data.entries}
            onDecide={data.can_decide ? (id, decision, notesValue) => void decide(id, decision, notesValue) : undefined}
          />
        </>
      ) : selected ? <LoadingScreen /> : null}

      <Modal open={Boolean(action)} title={action ? titles[action] : ''} onClose={() => setAction(null)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
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
          />
          <Field
            label={t('kams.amount')}
            type="number"
            min="0.01"
            step="0.01"
            max={(action === 'expense' || action === 'withdraw') ? Math.max(0, operationCap).toFixed(2) : undefined}
            value={amount}
            onChange={(e) => {
              setAmount(e.target.value)
              if ((action === 'expense' || action === 'withdraw') && Number(e.target.value) > operationCap + 0.001) {
                setError(selectedMember && Number(e.target.value) > selectedMember.balance + 0.001
                  ? t('kams.memberExceedsBalance', { balance: money(selectedMember.balance) })
                  : t('kams.expenseExceedsBalance', { balance: money(spendable) }))
              } else {
                setError('')
              }
            }}
            required
          />
          <Field label={t('kams.motif')} value={notes} onChange={(e) => setNotes(e.target.value)} />
          <Button type="submit" className="w-full" disabled={saving || amountTooHigh}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <KamsAgentAccountModal
        open={agentModalOpen}
        initialMemberId={agentMemberId}
        officeId={agentOfficeId}
        onClose={() => {
          setAgentModalOpen(false)
          setAgentMemberId(null)
          setAgentOfficeId(null)
        }}
      />
    </div>
  )
}
