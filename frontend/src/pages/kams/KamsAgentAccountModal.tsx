import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Field, Modal } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
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
  can_post_deposit?: boolean
  can_post_withdraw?: boolean
  can_browse_agents?: boolean
  agents: AgentOption[]
}

type Action = 'deposit' | 'withdraw'

type Props = {
  open: boolean
  onClose: () => void
  initialMemberId?: number | null
  /** When set, only agents of this office are listed. Null/undefined = all offices (central). */
  officeId?: number | null
}

export function KamsAgentAccountModal({ open, onClose, initialMemberId = null, officeId = null }: Props) {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [data, setData] = useState<Account | null>(null)
  const [missing, setMissing] = useState(false)
  const [loading, setLoading] = useState(false)
  const [memberId, setMemberId] = useState<number | ''>('')
  const [agents, setAgents] = useState<AgentOption[]>([])
  const [canBrowseAgents, setCanBrowseAgents] = useState(false)
  const [action, setAction] = useState<Action | null>(null)
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)

  const load = (id?: number | '', preferredId?: number | null) => {
    setLoading(true)
    setError('')
    const params: Record<string, number> = {}
    if (id) params.member_id = Number(id)
    if (officeId) params.office_id = officeId
    api.get('/modules/kams/finance/account', { params })
      .then((res) => {
        setMissing(!res.data.data)
        const next = (res.data.data || null) as Account | null
        const nextAgents = next?.can_browse_agents ? (next.agents || []) : []
        setCanBrowseAgents(Boolean(next?.can_browse_agents))
        setAgents(nextAgents)

        const preferred = preferredId || id || null
        const preferredInList = preferred && nextAgents.some((agent) => agent.id === preferred)
        const fallbackId = preferredInList
          ? Number(preferred)
          : (nextAgents[0]?.id || next?.member?.id || null)

        if (fallbackId && next && fallbackId !== next.member.id) {
          return api.get('/modules/kams/finance/account', {
            params: {
              member_id: fallbackId,
              ...(officeId ? { office_id: officeId } : {}),
            },
          }).then((second) => {
            const selected = (second.data.data || null) as Account | null
            setMissing(!selected)
            setData(selected)
            setCanBrowseAgents(Boolean(selected?.can_browse_agents))
            setAgents(selected?.can_browse_agents ? (selected.agents || nextAgents) : nextAgents)
            if (selected?.member?.id) setMemberId(selected.member.id)
          })
        }

        setData(next)
        if (next?.member?.id) setMemberId(next.member.id)
      })
      .catch(() => {
        setMissing(true)
        setData(null)
      })
      .finally(() => setLoading(false))
  }

  useEffect(() => {
    if (!open) {
      setData(null)
      setMissing(false)
      setAction(null)
      setError('')
      setAmount('')
      setNotes('')
      return
    }
    load(initialMemberId || '', initialMemberId)
  }, [open, initialMemberId, officeId])

  const available = Math.max(0, (data?.balance ?? 0) - (data?.pending_withdrawals ?? 0))
  const withdrawTooHigh = action === 'withdraw' && amount !== '' && Number(amount) > available + 0.001

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    if (!action || !data || !memberId) return
    if (action === 'withdraw' && Number(amount) > available + 0.001) {
      setError(t('kams.withdrawExceedsBalance', { balance: money(available) }))
      return
    }
    setError('')
    setSaving(true)
    try {
      const endpoint = action === 'deposit'
        ? '/modules/kams/finance/account/post-deposit'
        : '/modules/kams/finance/account/post-withdraw'
      const res = await api.post(endpoint, {
        amount: Number(amount),
        notes,
        member_id: memberId,
        ...(officeId ? { office_id: officeId } : {}),
      })
      setData(res.data.data)
      setAgents(res.data.data?.can_browse_agents ? (res.data.data.agents || agents) : agents)
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

  const titles: Record<Action, string> = {
    deposit: t('kams.makeDeposit'),
    withdraw: t('kams.makeWithdrawal'),
  }

  const agentItems: SearchComboboxItem[] = agents.map((agent) => ({
    id: String(agent.id),
    label: agent.full_name,
    hint: [agent.member_code, agent.office?.name, agent.phone].filter(Boolean).join(' · '),
  }))
  const selectedAgent = agentItems.find((item) => item.id === String(memberId))
    ?? agentItems.find((item) => item.id === String(data?.member.id))
    ?? null

  return (
    <>
      <Modal open={open} title={t('kams.agentAccounts')} onClose={onClose} size="lg">
        <div className="space-y-4">
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

          {loading ? <LoadingScreen /> : null}
          {!loading && missing ? <Empty>{t('kams.noAgentAccount')}</Empty> : null}

          {!loading && data ? (
            <>
              <div className="flex flex-wrap items-center gap-2">
                <div className="min-w-0 flex-1">
                  <div className="font-semibold">{data.member.full_name}</div>
                  <p className="text-sm text-muted">
                    {data.member.member_code}
                    {data.member.office ? ` · ${data.member.office.name}` : ''}
                    {data.member.role ? ` · ${roleLabel(t, data.member.role)}` : data.member.rank ? ` · ${t(`kams.rank.${data.member.rank}`, { defaultValue: data.member.rank })}` : ''}
                  </p>
                </div>
                {data.can_post_deposit ? (
                  <Button onClick={() => { setAction('deposit'); setError(''); setAmount(''); setNotes('') }}>
                    {t('kams.makeDeposit')}
                  </Button>
                ) : null}
                {data.can_post_withdraw ? (
                  <Button variant="secondary" onClick={() => { setAction('withdraw'); setError(''); setAmount(''); setNotes('') }}>
                    {t('kams.makeWithdrawal')}
                  </Button>
                ) : null}
                <KamsTransactionsPrintMenu
                  canPreview={hasPermission('kams.finance.account.preview')}
                  canPrint={hasPermission('kams.finance.account.print')}
                  onPreview={() => transactionsRef.current?.preview()}
                  onPrint={() => transactionsRef.current?.print()}
                />
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

              <KamsAccountTransactions
                ref={transactionsRef}
                key={data.member.id}
                title={t('kams.transactions')}
                subtitle={data.member.full_name}
                entries={data.entries}
                showMember={false}
              />
            </>
          ) : null}
        </div>
      </Modal>

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
          <Button type="submit" className="w-full" disabled={saving || withdrawTooHigh}>
            {saving ? t('common.loading') : t('common.save')}
          </Button>
        </form>
      </Modal>
    </>
  )
}
