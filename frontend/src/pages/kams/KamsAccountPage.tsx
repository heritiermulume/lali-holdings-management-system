import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Field, Modal } from '../../components/ui'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money, roleLabel } from '../../lib/format'
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
type AgentOption = {
  id: number
  full_name: string
  member_code: string
  phone?: string | null
  office?: { id: number; name: string; code: string } | null
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
  can_browse_agents?: boolean
  agents: AgentOption[]
}

type Action = 'deposit' | 'withdraw'

export function KamsAccountPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [data, setData] = useState<Account | null>(null)
  const [missing, setMissing] = useState(false)
  const [memberId, setMemberId] = useState<number | ''>('')
  const [agents, setAgents] = useState<AgentOption[]>([])
  const [canBrowseAgents, setCanBrowseAgents] = useState(false)
  const [action, setAction] = useState<Action | null>(null)
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)

  const load = (id?: number | '') => {
    const params = id ? { member_id: id } : {}
    api.get('/modules/kams/finance/account', { params })
      .then((res) => {
        setMissing(!res.data.data)
        const next = res.data.data || null
        setData(next)
        setCanBrowseAgents(Boolean(next?.can_browse_agents))
        if (next?.can_browse_agents) {
          setAgents(next.agents || [])
        } else {
          setAgents([])
        }
        if (next?.member?.id) setMemberId(next.member.id)
      })
      .catch(() => {
        setMissing(true)
        setData(null)
      })
  }

  useEffect(() => { load() }, [])

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
        member_id: memberId || undefined,
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

  if (missing) {
    return <Empty>{t('kams.noAgentAccount')}</Empty>
  }
  if (!data) return <p className="text-sm text-muted">{t('common.loading')}</p>

  const titles: Record<Action, string> = {
    deposit: t('kams.requestDeposit'),
    withdraw: t('kams.requestWithdrawal'),
  }

  const agentItems: SearchComboboxItem[] = agents.map((agent) => ({
    id: String(agent.id),
    label: agent.full_name,
    hint: [agent.member_code, agent.office?.name, agent.phone].filter(Boolean).join(' · '),
  }))
  const selectedAgent = agentItems.find((item) => item.id === String(memberId)) ?? agentItems.find((item) => item.id === String(data.member.id)) ?? null

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

      {canBrowseAgents ? (
        <SearchCombobox
          label={t('kams.selectAgent')}
          value={selectedAgent}
          items={agentItems}
          placeholder={t('kams.searchAgents')}
          emptyLabel={t('common.empty')}
          onSelect={(item) => {
            if (!item) return
            const next = Number(item.id)
            if (!next || next === memberId) return
            setMemberId(next)
            load(next)
          }}
        />
      ) : null}

      {error && !action ? <p className="text-sm text-red-600">{error}</p> : null}

      <KamsBankCard
        kind="member"
        holder={data.member.full_name}
        subtitle={data.member.office?.name}
        balance={data.balance}
        pending={data.pending_withdrawals}
        pendingDeposits={data.pending_deposits}
      />

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
