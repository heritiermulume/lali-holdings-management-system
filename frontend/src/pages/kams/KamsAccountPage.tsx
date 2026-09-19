import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money, roleLabel } from '../../lib/format'
import type { User } from '../../types'
import { KamsAccountTransactions, KamsTransactionsPrintMenu, type KamsAccountTransactionsHandle } from './KamsAccountTransactions'
import { KamsBankCard } from './KamsBankCard'

type Entry = {
  id: number
  type: string
  status: string
  amount: number
  occurred_on?: string | null
  notes?: string | null
  created_by?: { name?: string } | null
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
    manager_descendant_office: number
    divisionnaire_office: number
    secretary_office: number
    salary_paid: number
  }
  by_rule?: Array<{ code: string; amount: number }>
  shares: Share[]
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
  entries: Entry[]
  can_deposit: boolean
  can_withdraw: boolean
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
): CompensationKpi[] {
  const overview: CompensationKpi[] = [
    { key: 'generated', value: totals.generated },
    { key: 'postedShares', value: totals.posted },
    { key: 'accrued', value: totals.accrued },
  ]

  switch (roleSlug) {
    case 'manager':
      return [
        ...overview,
        { key: 'managerSaleShare', value: totals.manager_sale, label: t('kams.rule.manager_sale') },
        { key: 'managerOfficeShare', value: totals.manager_office, label: t('kams.rule.manager_office') },
        { key: 'managerDescendantShare', value: totals.manager_descendant_office, label: t('kams.rule.manager_descendant_office') },
        { key: 'assistantDistributed', value: totals.distributed_to_assistants },
        { key: 'managerRemaining', value: totals.manager_remaining },
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

export function KamsAccountPage() {
  const { t } = useTranslation()
  const { user, hasPermission } = useAuth()
  const [data, setData] = useState<Account | null>(null)
  const [compensation, setCompensation] = useState<CompensationSummary | null>(null)
  const [missing, setMissing] = useState(false)
  const [loading, setLoading] = useState(true)
  const [action, setAction] = useState<Action | null>(null)
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)

  const showCompensation = canViewMyCompensation(user, data?.member.role?.slug)

  const load = () => {
    setLoading(true)
    api.get('/modules/kams/finance/account')
      .then((res) => {
        setMissing(!res.data.data)
        setData(res.data.data || null)
      })
      .catch(() => {
        setMissing(true)
        setData(null)
      })
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    if (!data || !showCompensation) {
      setCompensation(null)
      return
    }
    api.get('/modules/kams/revenue/summary')
      .then((res) => setCompensation(res.data.data ?? null))
      .catch(() => setCompensation(null))
  }, [data, showCompensation])

  const available = Math.max(0, (data?.balance ?? 0) - (data?.pending_withdrawals ?? 0))
  const withdrawTooHigh = action === 'withdraw' && amount !== '' && Number(amount) > available + 0.001

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

  if (loading) return <LoadingScreen />
  if (missing || !data) {
    return <Empty>{t('kams.noAgentAccount')}</Empty>
  }

  const titles: Record<Action, string> = {
    deposit: t('kams.requestDeposit'),
    withdraw: t('kams.requestWithdrawal'),
  }

  const compensationEntries = (data.entries || []).filter((entry) => entry.type === 'revenue_share' || entry.type === 'salary')

  return (
    <div className="space-y-5">
      <div>
        <h2 className="text-lg font-bold">{t('kams.myAccount')}</h2>
        <p className="mt-1 text-sm text-muted">{t('kams.myAccountHint')}</p>
        <p className="mt-1 text-sm">
          <b>{data.member.full_name}</b>
          <span className="text-muted"> · {data.member.member_code}</span>
          {data.member.office ? <span className="text-muted"> · {data.member.office.name}</span> : null}
          {data.member.role ? <span className="text-muted"> · {roleLabel(t, data.member.role)}</span> : data.member.rank ? <span className="text-muted"> · {t(`kams.rank.${data.member.rank}`, { defaultValue: data.member.rank })}</span> : null}
        </p>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        {data.can_deposit ? <Button onClick={() => { setAction('deposit'); setError(''); setAmount(''); setNotes('') }}>{t('kams.requestDeposit')}</Button> : null}
        {data.can_withdraw ? <Button variant="secondary" onClick={() => { setAction('withdraw'); setError(''); setAmount(''); setNotes('') }}>{t('kams.requestWithdrawal')}</Button> : null}
        <div className="ml-auto">
          <KamsTransactionsPrintMenu
            canPreview={hasPermission('kams.finance.account.preview')}
            canPrint={hasPermission('kams.finance.account.print')}
            onPreview={() => transactionsRef.current?.preview()}
            onPrint={() => transactionsRef.current?.print()}
          />
        </div>
      </div>

      {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

      <KamsBankCard
        kind="member"
        holder={data.member.full_name}
        subtitle={data.member.office?.name}
        balance={data.balance}
        pending={data.pending_withdrawals}
        pendingDeposits={data.pending_deposits}
      />

      {showCompensation && (compensation || compensationEntries.length > 0) ? (
        <Card className="p-5">
          <div className="mb-1 font-semibold">{t('kams.myCompensation')}</div>
          <p className="mb-4 text-sm text-muted">{t('kams.myCompensationHint')}</p>
          {compensation ? (
            <div className="mb-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              {profileCompensationKpis(
                compensation.totals,
                compensation.member.role?.slug || data.member.role?.slug,
                t,
              ).map((item) => (
                <div key={item.key} className="rounded-xl bg-surface p-3">
                  <div className="text-xs text-muted">{item.label ?? t(`kams.${item.key}`)}</div>
                  <div className="mt-1 text-xl font-extrabold">{money(item.value)}</div>
                </div>
              ))}
            </div>
          ) : null}
          {compensation?.member.role?.slug === 'secretary' && compensation.member.fixed_salary > 0 ? (
            <p className="mb-4 text-sm text-muted">
              {t('kams.fixedSalary')}: {money(compensation.member.fixed_salary)}
            </p>
          ) : null}
          <div className="space-y-2">
            {(compensation?.shares?.length ? compensation.shares : compensationEntries).length === 0 ? (
              <Empty>{t('common.empty')}</Empty>
            ) : compensation?.shares?.length ? (
              compensation.shares.map((share) => (
                <div key={share.id} className="rounded-xl border border-line px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="font-semibold">{t(`kams.rule.${share.rule_code}`, { defaultValue: share.rule_code })}</div>
                    <Badge>{money(share.amount)}</Badge>
                  </div>
                  <div className="mt-1 text-xs text-muted">
                    {[share.sale?.reference, share.product?.name || share.snapshot?.product?.name, share.office?.name, share.formula, share.status].filter(Boolean).join(' · ')}
                  </div>
                </div>
              ))
            ) : (
              compensationEntries.map((entry) => (
                <div key={entry.id} className="rounded-xl border border-line px-3 py-2">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="font-semibold">{t(`kams.ledger.${entry.type}`, { defaultValue: entry.type })}</div>
                    <Badge>{money(entry.amount)}</Badge>
                  </div>
                  <div className="mt-1 text-xs text-muted">
                    {[entry.occurred_on, entry.notes, entry.status].filter(Boolean).join(' · ')}
                  </div>
                </div>
              ))
            )}
          </div>
        </Card>
      ) : null}

      <KamsAccountTransactions
        ref={transactionsRef}
        key={data.member.id}
        title={t('kams.transactions')}
        subtitle={data.member.full_name}
        entries={data.entries}
        showMember={false}
      />

      <Modal open={Boolean(action)} title={action ? titles[action] : ''} onClose={() => setAction(null)}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          {error ? <p className="text-sm text-red-600">{error}</p> : null}
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
          <Field label={t('kams.motif')} value={notes} onChange={(e) => setNotes(e.target.value)} />
          <Button type="submit" className="w-full" disabled={saving || withdrawTooHigh}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>
    </div>
  )
}
