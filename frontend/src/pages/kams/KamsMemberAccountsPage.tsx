import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Empty, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money, roleLabel } from '../../lib/format'
import { KamsAgentAccountModal } from './KamsAgentAccountModal'

type AccountKind = 'guy' | 'manager' | 'assistant_manager' | 'secretary' | 'divisionnaire'

type MemberRow = {
  id: number
  full_name: string
  member_code: string
  status: string
  office?: { id: number; name: string; code: string } | null
  role?: { id: number; name: string; slug: string } | null
  accounts: Record<AccountKind, number>
  total_balance: number
}

type Payload = {
  members: MemberRow[]
  totals: Record<AccountKind | 'total', number>
  can_filter_offices?: boolean
  office_id?: number | null
  filter_offices?: Array<{ id: number; name: string; code: string }>
}

const KIND_ORDER: AccountKind[] = ['guy', 'manager', 'assistant_manager', 'secretary', 'divisionnaire']

export function KamsMemberAccountsPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [officeId, setOfficeId] = useState('')
  const [data, setData] = useState<Payload | null>(null)
  const [viewMemberId, setViewMemberId] = useState<number | null>(null)
  const [viewOfficeId, setViewOfficeId] = useState<number | null>(null)

  useEffect(() => {
    let active = true
    api.get('/modules/kams/finance/member-accounts', {
      params: { office_id: officeId || undefined },
    }).then((res) => { if (active) setData(res.data.data) })
    return () => { active = false }
  }, [officeId])

  if (!data) return <LoadingScreen />

  const canViewAccount = hasPermission('kams.finance.account.view')
    || hasPermission('kams.finance.dashboard.view')
    || hasPermission('kams.finance.company.view')

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h2 className="text-lg font-bold">{t('kams.memberAccounts')}</h2>
          <p className="mt-1 text-sm text-muted">{t('kams.memberAccountsHint')}</p>
        </div>
        {data.can_filter_offices ? (
          <Select
            className="min-w-[16rem] sm:w-72"
            label={t('kams.filterOffice')}
            value={officeId}
            onChange={(e) => setOfficeId(e.target.value)}
          >
            <option value="">{t('kams.allOffices')}</option>
            {(data.filter_offices || []).map((office) => (
              <option key={office.id} value={office.id}>{office.name}{office.code ? ` · ${office.code}` : ''}</option>
            ))}
          </Select>
        ) : null}
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.guySavings')}</div>
          <div className="mt-2 text-2xl font-extrabold">{money(data.totals.guy || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.assistantManagerSavings')}</div>
          <div className="mt-2 text-2xl font-extrabold">{money(data.totals.assistant_manager || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.managerSavings')}</div>
          <div className="mt-2 text-2xl font-extrabold">{money(data.totals.manager || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.totalSavings')}</div>
          <div className="mt-2 text-2xl font-extrabold text-lhms">{money(data.totals.total || 0)}</div>
        </Card>
      </div>

      <Card className="overflow-hidden p-0">
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-surface text-left text-xs uppercase tracking-wide text-muted">
              <tr>
                <th className="px-4 py-3 font-semibold">{t('kams.member')}</th>
                <th className="px-4 py-3 font-semibold">{t('kams.office')}</th>
                <th className="px-4 py-3 font-semibold">{t('users.role')}</th>
                {KIND_ORDER.map((kind) => (
                  <th key={kind} className="px-4 py-3 font-semibold whitespace-nowrap">{t(`kams.accountKind.${kind}`)}</th>
                ))}
                <th className="px-4 py-3 font-semibold">{t('kams.totalBalance')}</th>
              </tr>
            </thead>
            <tbody>
              {data.members.map((row) => (
                <tr key={row.id} className="border-t border-line">
                  <td className="px-4 py-3">
                    <div className="font-semibold">{row.full_name}</div>
                    <div className="text-xs text-muted">{row.member_code}</div>
                    {canViewAccount ? (
                      <button
                        type="button"
                        onClick={() => {
                          setViewMemberId(row.id)
                          setViewOfficeId(officeId ? Number(officeId) : (row.office?.id ?? null))
                        }}
                        className="mt-1 text-xs font-semibold text-lhms hover:underline"
                      >
                        {t('kams.viewAccount')}
                      </button>
                    ) : null}
                  </td>
                  <td className="px-4 py-3">{row.office?.name || '—'}</td>
                  <td className="px-4 py-3">{row.role ? roleLabel(t, row.role) : '—'}</td>
                  {KIND_ORDER.map((kind) => (
                    <td key={kind} className="px-4 py-3 whitespace-nowrap">{money(row.accounts?.[kind] || 0)}</td>
                  ))}
                  <td className="px-4 py-3 font-bold text-lhms whitespace-nowrap">{money(row.total_balance)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        {data.members.length === 0 ? <div className="p-6"><Empty>{t('common.empty')}</Empty></div> : null}
      </Card>

      <KamsAgentAccountModal
        open={viewMemberId !== null}
        initialMemberId={viewMemberId}
        officeId={viewOfficeId}
        onClose={() => {
          setViewMemberId(null)
          setViewOfficeId(null)
        }}
      />
    </div>
  )
}
