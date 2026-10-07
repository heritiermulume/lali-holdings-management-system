import { useEffect, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { money } from '../../lib/format'

type Guy = { id: number; name: string; code: string; stock: number; savings: number; sales: number }
type OfficeNode = {
  office: string
  code: string
  received: number
  remaining_office: number
  remaining_sellers: number
  remaining: number
  stock_value: number
  managers: Array<{ manager: string; savings: number; guys: Guy[] }>
}

export function KamsHierarchy() {
  const { t } = useTranslation()
  const [data, setData] = useState<{
    head_office: { units: number }
    offices: OfficeNode[]
    savings: { guys: number; managers: number }
  } | null>(null)

  useEffect(() => {
    api.get('/modules/kams/stock/hierarchy').then((res) => setData(res.data.data))
  }, [])

  if (!data) return <LoadingScreen />

  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-3">
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.headOfficeStock')}</div>
          <div className="mt-2 text-2xl font-extrabold">{data.head_office.units}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.guySavings')}</div>
          <div className="mt-2 text-2xl font-extrabold">{money(data.savings.guys)}</div>
        </Card>
        <Card className="p-4">
          <div className="text-sm text-muted">{t('kams.managerSavings')}</div>
          <div className="mt-2 text-2xl font-extrabold">{money(data.savings.managers)}</div>
        </Card>
      </div>
      {data.offices.map((office) => (
        <Card key={office.code} className="p-5">
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <div className="font-semibold">{office.office}</div>
              <div className="text-sm text-muted">
                {t('kams.stockFormula')}: {office.received} → {t('kams.remainingOffice')} {office.remaining_office} + {t('kams.withAgents')} {office.remaining_sellers} = {office.remaining}
              </div>
            </div>
            <Badge>{money(office.stock_value)}</Badge>
          </div>
          <div className="mt-4 space-y-3">
            {office.managers.map((manager) => (
              <div key={manager.manager} className="rounded-xl bg-surface p-3">
                <div className="mb-2 flex justify-between text-sm">
                  <span className="font-semibold">{manager.manager}</span>
                  <span>{t('kams.managerSavings')}: {money(manager.savings)}</span>
                </div>
                {manager.guys.map((guy) => (
                  <div key={guy.id} className="flex justify-between py-1 text-sm">
                    <span>{guy.name} ({guy.code})</span>
                    <span>{guy.stock} · {money(guy.sales)} · {money(guy.savings)}</span>
                  </div>
                ))}
              </div>
            ))}
          </div>
        </Card>
      ))}
      {data.offices.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}
    </div>
  )
}

export function KamsSavings() {
  const { t } = useTranslation()
  const [kind, setKind] = useState<'guy' | 'manager'>('guy')
  const [rows, setRows] = useState<Array<{
    id: number
    balance: number
    member?: { full_name: string; member_code: string; office?: { name: string } | null } | null
    entries?: Array<{ id: number; type: string; amount: number; occurred_on?: string | null; notes?: string | null }>
  }>>([])

  useEffect(() => {
    api.get('/modules/kams/stock/savings', { params: { kind } }).then((res) => setRows(res.data.data || []))
  }, [kind])

  return (
    <div>
      <div className="mb-4 flex gap-2">
        {(['guy', 'manager'] as const).map((item) => (
          <Button key={item} variant={kind === item ? 'primary' : 'secondary'} onClick={() => setKind(item)}>
            {item === 'guy' ? t('kams.guySavings') : t('kams.managerSavings')}
          </Button>
        ))}
      </div>
      <div className="grid gap-3">
        {rows.map((row) => (
          <Card key={row.id} className="p-5">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{row.member?.full_name}</div>
                <div className="text-sm text-muted">{row.member?.member_code} · {row.member?.office?.name}</div>
              </div>
              <div className="text-xl font-extrabold text-lhms">{money(row.balance)}</div>
            </div>
            <div className="mt-3 space-y-1 text-sm">
              {(row.entries || []).map((entry) => (
                <div key={entry.id} className="flex justify-between">
                  <span>{t(`kams.ledger.${entry.type}`, { defaultValue: entry.type })} · {entry.occurred_on || '—'}</span>
                  <span className={entry.amount < 0 ? 'text-red-600' : ''}>{money(entry.amount)}</span>
                </div>
              ))}
            </div>
          </Card>
        ))}
      </div>
      {rows.length === 0 ? <Empty>{t('common.empty')}</Empty> : null}
    </div>
  )
}

export function KamsDailyMaster() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [date, setDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [data, setData] = useState<{
    date: string
    sales_count: number
    field_total: number
    seller_savings: number
    manager_savings: number
    company_share: number
    cost_total?: number
    profit?: number
    sales?: Array<{ id: number; reference: string; payment_status: string; field_total?: number; cost_total?: number; margin?: number; member?: { full_name: string } | null }>
  } | null>(null)
  const [error, setError] = useState('')

  const load = () => api.get('/modules/kams/stock/daily-settlements', { params: { date } }).then((res) => setData(res.data.data))

  useEffect(() => { void load() }, [date])

  const close = async (event: FormEvent) => {
    event.preventDefault()
    setError('')
    try {
      await api.post('/modules/kams/stock/daily-settlements', { date })
      void load()
    } catch (err) {
      const response = (err as { response?: { data?: { message?: string } } }).response?.data
      setError(response?.message || t('kams.saveError'))
    }
  }

  return (
    <div className="space-y-4">
      <Field label={t('kams.settlementDate')} type="date" value={date} onChange={(e) => setDate(e.target.value)} />
      {error ? <p className="text-sm text-red-600">{error}</p> : null}
      {!data ? <LoadingScreen /> : (
        <>
          <p className="text-sm text-muted">{t('kams.dailyMasterHint')}</p>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Card className="p-4"><div className="text-sm text-muted">{t('kams.salePrice')}</div><div className="mt-2 text-2xl font-extrabold">{money(data.field_total)}</div></Card>
            <Card className="p-4"><div className="text-sm text-muted">{t('kams.purchasePrice')}</div><div className="mt-2 text-2xl font-extrabold">{money(data.cost_total ?? data.company_share)}</div></Card>
            <Card className="p-4"><div className="text-sm text-muted">{t('kams.grossProfit')}</div><div className="mt-2 text-2xl font-extrabold">{money(data.profit ?? (data.field_total - (data.cost_total ?? data.company_share)))}</div></Card>
          </div>
          {(data.sales || []).map((sale) => (
            <Card key={sale.id} className="flex items-center justify-between p-4 text-sm">
              <span>{sale.reference} · {sale.member?.full_name}</span>
              <span>{money(sale.field_total || 0)} · {t('kams.grossProfit')} {money(sale.margin || 0)} · {t(`kams.status.${sale.payment_status}`, { defaultValue: sale.payment_status })}</span>
            </Card>
          ))}
          {hasPermission('kams.stock.update') ? (
            <form onSubmit={(e) => void close(e)}>
              <Button type="submit">{t('kams.closeDailyMaster')}</Button>
            </form>
          ) : null}
        </>
      )}
    </div>
  )
}
