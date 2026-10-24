import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Field, Modal } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { isSoftRefresh, trackLoadSpinner } from '../../lib/dataSync'
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
  accounts?: Array<{ kind: string; balance: number }>
  entries: Entry[]
  can_post_deposit?: boolean
  can_post_withdraw?: boolean
  can_decide?: boolean
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
  const [action, setAction] = useState<Action | null>(null)
  const [amount, setAmount] = useState('')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const transactionsRef = useRef<KamsAccountTransactionsHandle>(null)
  const [hasPrintableRows, setHasPrintableRows] = useState(false)

  const load = (id?: number | '') => {
    const stop = trackLoadSpinner(setLoading)
    if (!isSoftRefresh()) setError('')
    const params: Record<string, number> = {}
    if (id) params.member_id = Number(id)
    if (officeId) params.office_id = officeId
    return api.get('/modules/kams/finance/account', { params })
      .then((res) => {
        setMissing(!res.data.data)
        const next = (res.data.data || null) as Account | null
        setData(next)
        if (next?.member?.id && !isSoftRefresh()) setMemberId(next.member.id)
      })
      .catch(() => {
        if (isSoftRefresh()) return
        setMissing(true)
        setData(null)
      })
      .finally(stop)
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
    void load(initialMemberId || '')
  }, [open, initialMemberId, officeId])

  useLiveData(() => load(memberId || initialMemberId || ''), { enabled: open })

  const available = Math.max(0, (data?.balance ?? 0) - (data?.pending_withdrawals ?? 0))
  const withdrawTooHigh = action === 'withdraw' && amount !== '' && Number(amount) > available + 0.001

  const decide = async (id: number, decision: 'approve' | 'reject', notesValue: string) => {
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

  return (
    <>
      <Modal open={open} title={t('kams.agentAccount')} onClose={onClose} size="lg">
        <div className="space-y-4">
          {loading && !data ? <LoadingScreen /> : null}
          {!loading && missing ? <Empty>{t('kams.noAgentAccount')}</Empty> : null}

          {!loading && data ? (
            <>
              <div className="space-y-3">
                <div className="min-w-0">
                  <div className="font-semibold">{data.member.full_name}</div>
                  <p className="text-sm text-muted">
                    {data.member.member_code}
                    {data.member.office ? ` · ${data.member.office.name}` : ''}
                    {data.member.role ? ` · ${roleLabel(t, data.member.role)}` : ''}
                  </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  {data.can_post_deposit && (hasPermission('kams.finance.company.deposit') || hasPermission('kams.finance.account.deposit')) ? (
                    <Button onClick={() => { setAction('deposit'); setError(''); setAmount(''); setNotes('') }}>
                      {t('kams.makeDeposit')}
                    </Button>
                  ) : null}
                  {data.can_post_withdraw && (hasPermission('kams.finance.company.withdraw') || hasPermission('kams.finance.account.withdraw')) ? (
                    <Button variant="secondary" disabled={available <= 0.001} onClick={() => { setAction('withdraw'); setError(''); setAmount(''); setNotes('') }}>
                      {t('kams.makeWithdrawal')}
                    </Button>
                  ) : null}
                  <KamsTransactionsPrintMenu
                    canPreview={hasPermission('kams.finance.account.preview')}
                    canPrint={hasPermission('kams.finance.account.print')}
                    onPreview={() => transactionsRef.current?.preview()}
                    onPrint={() => transactionsRef.current?.print()}
                    disabled={!hasPrintableRows}
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

              <KamsAccountTransactions
                ref={transactionsRef}
                key={data.member.id}
                title={t('kams.transactions')}
                subtitle={data.member.full_name}
                entries={data.entries}
                showMember={false}
                showProvenance={Boolean(data.split_accounts)}
                provenanceKinds={data.split_accounts ? ['salary', 'percentage'] : []}
                onDecide={data.can_decide && hasPermission('kams.finance.company.approve') ? (id, decision, notesValue) => void decide(id, decision, notesValue) : undefined}
                onRowsChange={(count) => setHasPrintableRows(count > 0)}
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
            {saving ? t('common.loading') : t('common.send')}
          </Button>
        </form>
      </Modal>
    </>
  )
}
