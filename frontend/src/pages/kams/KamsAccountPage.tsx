import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { trackLoadSpinner } from '../../lib/dataSync'
import { money } from '../../lib/format'
import type { User } from '../../types'
import { KamsAccountTransactions, KamsTransactionsPrintMenu, type KamsAccountTransactionsHandle } from './KamsAccountTransactions'
import { KamsBankCard } from './KamsBankCard'

type Entry = {
  id: number
  kind?: string
  type: string
  status: string
  amount: number
  occurred_on?: string | null
  notes?: string | null
  created_by?: { name?: string } | null
  is_commission_transfer?: boolean
  source?: { is_commission_transfer?: boolean; entry_type?: string } | null
}

type Share = {
  id: number
  rule_code: string
  role_slug: string
  status: string
  amount: number
  formula?: string | null
  snapshot?: {
    product?: { name?: string } | null
  } | null
  sale?: { id: number; reference?: string; sold_at?: string } | null
  product?: { name?: string } | null
  office?: { name?: string } | null
}

type CompensationSummary = {
  member: { id: number; full_name: string; member_code: string; fixed_salary: number; role?: { slug?: string; name?: string } | null }
  totals: {
    generated: number
    posted: number
    accrued: number
    remaining: number
    distributed_to_assistants: number
    manager_remaining: number
    manager_sale: number
    assistant_share: number
    manager_office: number
    manager_certified_office?: number
    manager_descendant_office: number
    divisionnaire_office: number
    secretary_office: number
    salary_paid: number
    has_opened_offices?: boolean
  }
  by_rule?: Array<{ code: string; amount: number }>
  shares: Share[]
}

type Wallet = {
  kind: string
  balance: number
  pending_withdrawals: number
  pending_deposits?: number
}

type Account = {
  member: {
    id: number
    full_name: string
    member_code: string
    rank?: string | null
    office?: { id: number; name: string; code: string } | null
    role?: { id: number; name: string; slug: string } | null
  }
  balance: number
  pending_withdrawals: number
  pending_deposits?: number
  deposits: number
  withdrawals: number
  expenses: number
  split_accounts?: boolean
  accounts?: Wallet[]
  entries: Entry[]
  can_deposit: boolean
  can_withdraw: boolean
  can_direct?: boolean
  can_decide_incoming?: boolean
}

type Action = 'deposit' | 'withdraw'

const COMPENSATION_ROLES = new Set([
  'super_administrator',
  'partner',
  'president',
  'vice_president',
  'divisionnaire',
  'manager',
  'secretary',
])

function canViewMyCompensation(user: User | null, memberRoleSlug?: string | null) {
  if (!user) return false
  if (user.is_super_admin) return true
  const slugs = new Set<string>()
  if (memberRoleSlug) slugs.add(memberRoleSlug)
  for (const role of user.roles ?? []) {
    if (role?.slug) slugs.add(role.slug)
  }
  for (const assignment of user.assignments ?? []) {
    if (assignment.role?.slug) slugs.add(assignment.role.slug)
    for (const office of assignment.offices ?? []) {
      if (office.role?.slug) slugs.add(office.role.slug)
    }
  }
  for (const slug of slugs) {
    if (COMPENSATION_ROLES.has(slug)) return true
  }
  return false
}

type CompensationKpi = { key: string; value: number; label?: string }

function profileCompensationKpis(
  totals: CompensationSummary['totals'],
  roleSlug: string | null | undefined,
  t: (key: string, options?: { defaultValue?: string }) => string,
  walletsTotal?: number | null,
): CompensationKpi[] {
  const generated = walletsTotal ?? totals.generated
  const overview: CompensationKpi[] = [
    { key: 'generated', value: generated },
    { key: 'postedShares', value: totals.posted },
  ]

  const certifiedSurplus: CompensationKpi = {
    key: 'managerCertifiedShare',
    value: totals.manager_certified_office ?? 0,
    label: t('kams.rule.manager_certified_office'),
  }
  const openedOffices: CompensationKpi[] = totals.has_opened_offices
    ? [{
        key: 'managerOpenedOffices',
        value: roundMoney((totals.manager_office || 0) + (totals.manager_descendant_office || 0) + (totals.divisionnaire_office || 0)),
        label: t('kams.rule.manager_opened_offices'),
      }]
    : []

  switch (roleSlug) {
    case 'manager':
      return [
        ...overview,
        { key: 'managerSaleShare', value: totals.manager_sale, label: t('kams.rule.manager_sale') },
        certifiedSurplus,
        ...openedOffices,
        { key: 'assistantDistributed', value: totals.distributed_to_assistants },
        { key: 'managerRemaining', value: walletsTotal ?? totals.manager_remaining },
      ]
    case 'partner':
    case 'vice_president':
      return [
        ...overview,
        { key: 'managerSaleShare', value: totals.manager_sale, label: t('kams.rule.manager_sale') },
        certifiedSurplus,
        ...openedOffices,
      ]
    case 'secretary':
      return [
        ...overview,
        { key: 'secretaryShare', value: totals.secretary_office, label: t('kams.rule.secretary_office') },
        { key: 'salaryPaid', value: totals.salary_paid },
      ]
    case 'divisionnaire':
      return [
        ...overview,
        { key: 'managerSaleShare', value: totals.manager_sale, label: t('kams.rule.manager_sale') },
        certifiedSurplus,
        { key: 'divisionnaireShare', value: totals.divisionnaire_office, label: t('kams.rule.divisionnaire_office') },
      ]
    case 'assistant_manager':
      return [
        ...overview,
        { key: 'assistantShare', value: totals.assistant_share, label: t('kams.rule.assistant_manager') },
      ]
    default:
      return overview
  }
}

function walletLabel(kind: string, t: (key: string, options?: { defaultValue?: string }) => string) {
  return t(`kams.wallet.${kind}`, { defaultValue: kind })
}

function roundMoney(value: number) {
  return Math.round(value * 100) / 100
}

export function KamsAccountPage() {
  const { t } = useTranslation()
  const { user, hasPermission } = useAuth()
  const [data, setData] = useState<Account | null>(null)
  const [compensation, setCompensation] = useState<CompensationSummary | null>(null)
  const [missing, setMissing] = useState(false)
  const [loading, setLoading] = useState(true)
  const [action, setAction] = useState<Action | null>(null)
  const [accountKind, setAccountKind] = useState('salary')
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)
  const [hasPrintableRows, setHasPrintableRows] = useState(false)

  const showCompensation = canViewMyCompensation(user, data?.member.role?.slug)
  const splitAccounts = Boolean(data?.split_accounts)
  const wallets = data?.accounts?.length
    ? data.accounts
    : [{ kind: 'guy', balance: data?.balance ?? 0, pending_withdrawals: data?.pending_withdrawals ?? 0, pending_deposits: data?.pending_deposits ?? 0 }]

  const load = () => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/finance/account')
      .then((res) => {
        setMissing(!res.data.data)
        setData(res.data.data || null)
      })
      .catch(() => {
        setMissing(true)
        setData(null)
      })
      .finally(stop)
  }

  useEffect(() => { void load() }, [])
  useLiveData(load)

  useEffect(() => {
    if (!data || !showCompensation) {
      setCompensation(null)
      return
    }
    api.get('/modules/kams/revenue/summary')
      .then((res) => setCompensation(res.data.data ?? null))
      .catch(() => setCompensation(null))
  }, [data, showCompensation])

  useEffect(() => {
    if (!data) return
    if (data.split_accounts) {
      setAccountKind((current) => (current === 'salary' || current === 'percentage' ? current : 'salary'))
    } else {
      setAccountKind('guy')
    }
  }, [data])

  const selectedWallet = wallets.find((wallet) => wallet.kind === accountKind) || wallets[0]
  const available = Math.max(0, (selectedWallet?.balance ?? 0) - (selectedWallet?.pending_withdrawals ?? 0))
  const withdrawTooHigh = action === 'withdraw' && amount !== '' && Number(amount) > available + 0.001

  const openAction = (next: Action, kind?: string) => {
    setAction(next)
    setError('')
    setAmount('')
    setNotes('')
    if (kind) setAccountKind(kind)
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!action || !data) return
    if (action === 'withdraw' && Number(amount) > available + 0.001) {
      setError(t('kams.withdrawExceedsBalance', { balance: money(available) }))
      return
    }
    setError('')
    setSaving(true)
    try {
      const res = await api.post(`/modules/kams/finance/account/${action}`, {
        amount: Number(amount),
        notes,
        account_kind: accountKind,
      })
      setData(res.data.data)
      setAction(null)
      setAmount('')
      setNotes('')
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const decideIncoming = async (id: number, decision: 'approve' | 'reject', notesValue: string) => {
    setError('')
    try {
      const res = await api.post(`/modules/kams/finance/account/entries/${id}/${decision}`, {
        notes: notesValue,
      })
      setData(res.data.data)
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  const isIncomingCommission = (entry: Entry) => Boolean(
    entry.is_commission_transfer
    || entry.source?.is_commission_transfer
    || entry.source?.entry_type === 'commission_transfer',
  )

  if (loading) return <LoadingScreen />
  if (missing || !data) {
    return <Empty>{t('kams.noAgentAccount')}</Empty>
  }

  const titles: Record<Action, string> = {
    deposit: data.can_direct ? t('kams.makeDeposit') : t('kams.requestDeposit'),
    withdraw: data.can_direct ? t('kams.makeWithdrawal') : t('kams.requestWithdrawal'),
  }

  const walletsTotal = splitAccounts
    ? roundMoney(wallets.reduce((sum, wallet) => sum + (wallet.balance || 0), 0))
    : null

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm text-muted">{t('kams.myAccountHint')}</p>
        <KamsTransactionsPrintMenu
          canPreview={hasPermission('kams.finance.account.preview')}
          canPrint={hasPermission('kams.finance.account.print')}
          onPreview={() => transactionsRef.current?.preview()}
          onPrint={() => transactionsRef.current?.print()}
          disabled={!hasPrintableRows}
        />
      </div>

      {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

      <div className={splitAccounts ? 'grid gap-4 lg:grid-cols-2' : ''}>
        {wallets.map((wallet) => {
          const walletAvailable = Math.max(0, wallet.balance - (wallet.pending_withdrawals || 0))
          const subtitle = wallet.kind === 'salary'
            ? t('kams.salaryAccountHint')
            : wallet.kind === 'percentage'
              ? t('kams.percentageAccountHint')
              : data.member.office?.name
          return (
            <div key={wallet.kind} className="space-y-3">
              <KamsBankCard
                kind="member"
                holder={walletLabel(wallet.kind, t)}
                subtitle={subtitle}
                balance={wallet.balance}
                pending={wallet.pending_withdrawals}
                pendingDeposits={wallet.pending_deposits}
              />
              <div className="flex flex-wrap items-center gap-2">
                {data.can_deposit && hasPermission('kams.finance.account.deposit') ? (
                  <Button onClick={() => openAction('deposit', wallet.kind)}>{data.can_direct ? t('kams.makeDeposit') : t('kams.requestDeposit')}</Button>
                ) : null}
                {data.can_withdraw && hasPermission('kams.finance.account.withdraw') ? (
                  <Button variant="secondary" disabled={walletAvailable <= 0.001} onClick={() => openAction('withdraw', wallet.kind)}>
                    {data.can_direct ? t('kams.makeWithdrawal') : t('kams.requestWithdrawal')}
                  </Button>
                ) : null}
              </div>
            </div>
          )
        })}
      </div>

      {showCompensation && compensation ? (
        <Card className="p-5">
          <div className="mb-1 font-semibold">{t('kams.myCompensation')}</div>
          <p className="mb-4 text-sm text-muted">{t('kams.myCompensationHint')}</p>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            {profileCompensationKpis(
              compensation.totals,
              compensation.member.role?.slug || data.member.role?.slug,
              t,
              walletsTotal,
            ).concat([
              { key: 'deposits', value: data.deposits || 0 },
              { key: 'expenses', value: data.expenses || 0 },
              { key: 'withdrawals', value: data.withdrawals || 0 },
              { key: 'balance', value: walletsTotal ?? data.balance ?? 0 },
            ]).map((item) => (
              <div key={item.key} className="rounded-xl bg-surface p-3">
                <div className="text-xs text-muted">{item.label ?? t(`kams.${item.key}`)}</div>
                <div className="mt-1 text-xl font-extrabold">{money(item.value)}</div>
              </div>
            ))}
          </div>
          {compensation.member.role?.slug === 'secretary' && compensation.member.fixed_salary > 0 ? (
            <p className="mt-4 text-sm text-muted">
              {t('kams.fixedSalary')}: {money(compensation.member.fixed_salary)}
            </p>
          ) : null}
        </Card>
      ) : null}

      {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

      <KamsAccountTransactions
        ref={transactionsRef}
        key={data.member.id}
        title={t('kams.transactions')}
        subtitle={data.member.full_name}
        entries={data.entries}
        showMember={false}
        showProvenance={splitAccounts || wallets.some((wallet) => wallet.kind === 'salary' || wallet.kind === 'percentage')}
        provenanceKinds={splitAccounts ? ['salary', 'percentage'] : wallets.map((wallet) => wallet.kind)}
        compact
        onDecide={data.can_decide_incoming ? (id, decision, notesValue) => void decideIncoming(id, decision, notesValue) : undefined}
        canDecideEntry={(entry) => isIncomingCommission(entry as Entry)}
        onRowsChange={(count) => setHasPrintableRows(count > 0)}
      />

      <Modal open={Boolean(action)} title={action ? titles[action] : ''} onClose={() => setAction(null)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
          {splitAccounts ? (
            <Select label={t('kams.selectWallet')} value={accountKind} onChange={(e) => setAccountKind(e.target.value)}>
              {wallets.map((wallet) => (
                <option key={wallet.kind} value={wallet.kind}>{walletLabel(wallet.kind, t)}</option>
              ))}
            </Select>
          ) : null}
          <Field
            label={t('kams.amount')}
            type="number"
            min="0.01"
            step="0.01"
            max={action === 'withdraw' ? Math.max(0, available).toFixed(2) : undefined}
            value={amount}
            onChange={(e) => {
              setAmount(e.target.value)
              if (action === 'withdraw' && Number(e.target.value) > available + 0.001) {
                setError(t('kams.withdrawExceedsBalance', { balance: money(available) }))
              } else {
                setError('')
              }
            }}
            required
          />
          <Field label={t('kams.motif')} value={notes} onChange={(e) => setNotes(e.target.value)} required />
          <Button type="submit" className="w-full" disabled={saving || withdrawTooHigh}>{saving ? t('common.loading') : t('common.send')}</Button>
        </form>
      </Modal>
    </div>
  )
}
