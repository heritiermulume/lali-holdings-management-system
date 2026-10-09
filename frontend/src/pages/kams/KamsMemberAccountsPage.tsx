import { Eye } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { OfficeFilterSelect } from '../../components/OfficeFilterSelect'
import { Button, Card } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { PaginationButtons } from '../../components/PaginationButtons'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import { syncOfficeIdFromPayload } from '../../hooks/useOfficeScope'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money, roleLabel } from '../../lib/format'
import { KamsAgentAccountModal } from './KamsAgentAccountModal'

type AccountKind = 'guy' | 'manager' | 'assistant_manager' | 'secretary' | 'divisionnaire' | 'salary' | 'percentage'

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

export function KamsMemberAccountsPage() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [searchParams, setSearchParams] = useSearchParams()
  const [officeId, setOfficeId] = useState('')
  const [search, setSearch] = useState('')
  const [data, setData] = useState<Payload | null>(null)
  const [viewMemberId, setViewMemberId] = useState<number | null>(null)
  const [viewOfficeId, setViewOfficeId] = useState<number | null>(null)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)

  const load = () => {
    return api.get('/modules/kams/finance/member-accounts', {
      params: { office_id: officeId || undefined },
    }).then((res) => {
      const payload = res.data.data
      setData(payload)
      syncOfficeIdFromPayload(setOfficeId, payload)
    })
  }

  useEffect(() => {
    void load()
  }, [officeId])

  useEffect(() => {
    const memberParam = searchParams.get('member_id')
    if (!memberParam) return
    const memberId = Number(memberParam)
    if (!Number.isFinite(memberId) || memberId <= 0) return
    const officeParam = searchParams.get('office_id')
    const nextOfficeId = officeParam ? Number(officeParam) : null
    setViewMemberId(memberId)
    setViewOfficeId(Number.isFinite(nextOfficeId) && nextOfficeId && nextOfficeId > 0 ? nextOfficeId : null)
    if (officeParam && Number.isFinite(Number(officeParam)) && Number(officeParam) > 0) {
      setOfficeId(String(Number(officeParam)))
    }
  }, [searchParams])

  useLiveData(load)

  if (!data) return <LoadingScreen />

  const filterOffices = data.filter_offices || []
  const canFilterOffices = Boolean(data.can_filter_offices) || filterOffices.length > 1
  const canViewAccount = hasPermission('kams.finance.account.view')
    || hasPermission('kams.finance.dashboard.view')
    || hasPermission('kams.finance.company.view')

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        {canFilterOffices ? (
          <OfficeFilterSelect
            value={officeId}
            offices={filterOffices}
            onChange={(e) => { setOfficeId(e.target.value); setPage(1) }}
          />
        ) : null}
        <input
          className="min-h-10 w-full rounded-full border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4 sm:ml-auto sm:w-80"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
          placeholder={t('common.search')}
          aria-label={t('common.search')}
        />
      </div>

      <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.guySavings')}</div>
          <div className="mt-2 text-xl font-extrabold sm:text-2xl">{money(data.totals.guy || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.assistantManagerSavings')}</div>
          <div className="mt-2 text-xl font-extrabold sm:text-2xl">{money(data.totals.assistant_manager || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.managerSavings')}</div>
          <div className="mt-2 text-xl font-extrabold sm:text-2xl">{money(data.totals.manager || 0)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.totalSavings')}</div>
          <div className="mt-2 text-xl font-extrabold sm:text-2xl text-lhms">{money(data.totals.total || 0)}</div>
        </Card>
      </div>

      {(() => {
        const query = search.trim().toLowerCase()
        const members = (data.members || []).filter((row) => {
          if (!query) return true
          const haystack = [
            row.full_name,
            row.member_code,
            row.office?.name,
            row.office?.code,
            row.role ? roleLabel(t, row.role) : '',
          ].filter(Boolean).join(' ').toLowerCase()
          return haystack.includes(query)
        })
        const lastPage = Math.max(1, Math.ceil(members.length / perPage) || 1)
        const safePage = Math.min(page, lastPage)
        const pageRows = members.slice((safePage - 1) * perPage, safePage * perPage)
        const rangeStart = members.length === 0 ? 0 : (safePage - 1) * perPage + 1
        const rangeEnd = Math.min(safePage * perPage, members.length)
        return (
      <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(4,55,17,0.04)]">
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-lhms text-white">
              <tr className="text-left text-xs font-semibold tracking-wide">
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.member')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">ID</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.office')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('users.role')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.totalBalance')}</th>
                {canViewAccount ? <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.actions')}</th> : null}
              </tr>
            </thead>
            <tbody>
              {pageRows.length === 0 ? (
                <tr>
                  <td colSpan={canViewAccount ? 6 : 5} className="px-4 py-8 text-center text-muted">{t('common.empty')}</td>
                </tr>
              ) : pageRows.map((row, index) => (
                <tr key={row.id} className={cn('border-t border-line', index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white')}>
                  <td className="px-4 py-1.5">
                    <div className="font-semibold">{row.full_name}</div>
                  </td>
                  <td className="px-4 py-1.5 whitespace-nowrap text-muted">{row.member_code}</td>
                  <td className="px-4 py-1.5">{row.office?.name || '—'}</td>
                  <td className="px-4 py-1.5">{row.role ? roleLabel(t, row.role) : '—'}</td>
                  <td className="px-4 py-1.5 font-bold text-lhms whitespace-nowrap">{money(row.total_balance)}</td>
                  {canViewAccount ? (
                    <td className="w-px px-4 py-1 text-right">
                      <Button
                        type="button"
                        variant="secondary"
                        className="shrink-0 overflow-hidden p-0 px-0 min-h-0"
                        style={{ width: 28, height: 28, minHeight: 0, padding: 0 }}
                        aria-label={t('kams.viewAccount')}
                        onClick={() => {
                          setViewMemberId(row.id)
                          setViewOfficeId(officeId ? Number(officeId) : (row.office?.id ?? null))
                        }}
                      >
                        <Eye className="size-4 shrink-0" />
                      </Button>
                    </td>
                  ) : null}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="flex flex-col gap-3 border-t border-line px-4 py-1.5 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">{t('kams.pageRange', { from: rangeStart, to: rangeEnd, total: members.length })}</p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2 text-sm text-muted">
              {t('kams.rowsPerPage')}
              <select value={perPage} onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1) }} className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink">
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
        </div>
      </div>
        )
      })()}

      <KamsAgentAccountModal
        open={viewMemberId !== null}
        initialMemberId={viewMemberId}
        officeId={viewOfficeId}
        onClose={() => {
          setViewMemberId(null)
          setViewOfficeId(null)
          if (searchParams.has('member_id') || searchParams.has('office_id')) {
            const next = new URLSearchParams(searchParams)
            next.delete('member_id')
            next.delete('office_id')
            setSearchParams(next, { replace: true })
          }
        }}
      />
    </div>
  )
}
