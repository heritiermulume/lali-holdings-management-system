import { useEffect, useMemo, useState } from 'react'
import { useOutletContext } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { ChevronDown, Package } from 'lucide-react'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { Badge, Card, Empty, Field, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'
import { buildBrandedDocumentHtml, printHtml } from '../../lib/printDocument'
import { KamsTransactionsPrintMenu } from './KamsAccountTransactions'
import type { OperationsOfficeFilter } from './KamsStockFlows'

type Period = 'day' | 'week' | 'month' | 'custom'
type DaySort = 'name' | 'code' | 'amount' | 'due' | 'quantity' | 'reference'

type Product = {
  id: number
  name: string
  sku?: string | null
  image_url?: string | null
}

type AssignmentItem = {
  id: number
  quantity_out: number
  quantity_returned: number
  quantity_lost: number
  unit_price: number
  unit_cost: number
  product?: Product | null
}

type Assignment = {
  id: number
  reference: string
  status: string
  assigned_at?: string | null
  settled_at?: string | null
  expected_amount: number
  collected_amount: number
  profit_amount: number
  loss_amount: number
  member?: { full_name: string; member_code: string; phone?: string | null } | null
  office?: { name: string } | null
  items?: AssignmentItem[]
}

type DayTotals = {
  assignments: number
  agents: number
  expected_amount: number
  collected_amount: number
  profit_amount: number
  loss_amount: number
  assigned_units: number
  sold_units: number
  returned_units: number
  lost_units: number
  surplus: number
  shortfall: number
}

type DayReport = {
  date: string
  totals: DayTotals
  assignments: Assignment[]
}

type ReportPayload = {
  period: Period
  range: { from: string; to: string }
  can_filter_offices?: boolean
  office_id?: number | null
  office_name?: string | null
  filter_offices?: Array<{ id: number; name: string; code?: string | null }>
  totals: DayTotals
  days: DayReport[]
}

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
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate())
  if (period === 'day') {
    const day = isoDate(today)
    return { from: day, to: day }
  }
  if (period === 'month') {
    const start = new Date(today.getFullYear(), today.getMonth(), 1)
    const end = new Date(today.getFullYear(), today.getMonth() + 1, 0)
    return { from: isoDate(start), to: isoDate(end) }
  }
  const start = startOfWeekMonday(today)
  const end = new Date(start)
  end.setDate(start.getDate() + 6)
  return { from: isoDate(start), to: isoDate(end) }
}

function formatDay(value: string, locale: string) {
  return new Date(`${value}T12:00:00`).toLocaleDateString(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  })
}

function escapeHtml(value: string) {
  return value.replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[char] ?? char))
}

function soldQty(item: AssignmentItem) {
  return Math.max(0, item.quantity_out - item.quantity_returned - item.quantity_lost)
}

function agentLabel(row: Assignment) {
  return row.member?.full_name || row.reference
}

function assignmentSoldUnits(row: Assignment) {
  return (row.items || []).reduce((sum, item) => sum + soldQty(item), 0)
}

const DAY_SORTS: DaySort[] = ['name', 'code', 'amount', 'due', 'quantity', 'reference']

function sortAssignments(rows: Assignment[], sort: DaySort) {
  const list = [...rows]
  const cmpText = (a: string, b: string) => a.localeCompare(b, undefined, { sensitivity: 'base' })
  list.sort((left, right) => {
    if (sort === 'name') return cmpText(left.member?.full_name || '', right.member?.full_name || '')
    if (sort === 'code') return cmpText(left.member?.member_code || '', right.member?.member_code || '')
    if (sort === 'amount') return Number(right.collected_amount || 0) - Number(left.collected_amount || 0)
    if (sort === 'due') return Number(right.expected_amount || 0) - Number(left.expected_amount || 0)
    if (sort === 'quantity') return assignmentSoldUnits(right) - assignmentSoldUnits(left)
    return cmpText(left.reference || '', right.reference || '')
  })
  return list
}

export function KamsAssignmentReports() {
  const { t, i18n } = useTranslation()
  const locale = i18n.language.startsWith('en') ? 'en-GB' : 'fr-FR'
  const initial = useMemo(() => presetRange('day'), [])
  const today = useMemo(() => isoDate(new Date()), [])
  const [period, setPeriod] = useState<Period>('day')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const { officeId } = useOutletContext<OperationsOfficeFilter>()
  const [data, setData] = useState<ReportPayload | null>(null)
  const [expandedDays, setExpandedDays] = useState<string[]>([])
  const [expandedAssignments, setExpandedAssignments] = useState<number[]>([])
  const [daySorts, setDaySorts] = useState<Record<string, DaySort>>({})
  const [loading, setLoading] = useState(true)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [printPreviewTitle, setPrintPreviewTitle] = useState('')
  const [filtersOpen, setFiltersOpen] = useState(false)

  useEffect(() => {
    if (!from || !to) return
    let active = true
    setLoading(true)
    api.get('/modules/kams/stock/assignments/report', {
      params: {
        from,
        to,
        period,
        ...(officeId ? { office_id: officeId } : {}),
      },
    }).then((res) => {
      if (!active) return
      const payload = res.data.data as ReportPayload
      setData(payload)
      setExpandedDays([])
    }).catch(() => {
      if (active) {
        setData({
          period,
          range: { from, to },
          can_filter_offices: false,
          office_id: null,
          office_name: null,
          filter_offices: [],
          totals: {
            assignments: 0,
            agents: 0,
            expected_amount: 0,
            collected_amount: 0,
            profit_amount: 0,
            loss_amount: 0,
            assigned_units: 0,
            sold_units: 0,
            returned_units: 0,
            lost_units: 0,
            surplus: 0,
            shortfall: 0,
          },
          days: [],
        })
      }
    }).finally(() => {
      if (active) setLoading(false)
    })
    return () => { active = false }
  }, [from, to, period, officeId])

  const applyPreset = (item: Exclude<Period, 'custom'>) => {
    const next = presetRange(item)
    setPeriod(item)
    setFrom(next.from)
    setTo(next.to)
  }

  const toggleDay = (date: string) => {
    setExpandedDays((current) => (
      current.includes(date) ? current.filter((item) => item !== date) : [...current, date]
    ))
  }

  const toggleAssignment = (id: number) => {
    setExpandedAssignments((current) => (
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id]
    ))
  }

  const daySort = (date: string): DaySort => daySorts[date] || 'name'

  const setDaySort = (date: string, sort: DaySort) => {
    setDaySorts((current) => ({ ...current, [date]: sort }))
  }

  const sortedDay = (day: DayReport): DayReport => ({
    ...day,
    assignments: sortAssignments(day.assignments || [], daySort(day.date)),
  })

  const totalsHtml = (totals: DayTotals) => `
    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin:0 0 14px;">
      <div style="border:1px solid #e4ece6;border-radius:10px;padding:8px 10px;font-size:11px;">${escapeHtml(t('kams.agents'))}: <b>${totals.agents}</b></div>
      <div style="border:1px solid #e4ece6;border-radius:10px;padding:8px 10px;font-size:11px;">${escapeHtml(t('kams.assignments'))}: <b>${totals.assignments}</b></div>
      <div style="border:1px solid #e4ece6;border-radius:10px;padding:8px 10px;font-size:11px;">${escapeHtml(t('kams.sold'))}: <b>${totals.sold_units}</b></div>
      <div style="border:1px solid #e4ece6;border-radius:10px;padding:8px 10px;font-size:11px;">${escapeHtml(t('kams.amountDue'))}: <b>${escapeHtml(money(totals.expected_amount))}</b></div>
      <div style="border:1px solid #e4ece6;border-radius:10px;padding:8px 10px;font-size:11px;">${escapeHtml(t('kams.cashCollected'))}: <b>${escapeHtml(money(totals.collected_amount))}</b></div>
      <div style="border:1px solid #e4ece6;border-radius:10px;padding:8px 10px;font-size:11px;">${escapeHtml(t('kams.shortfall'))}:<b style="display:block;margin-top:2px;">${escapeHtml(money(totals.shortfall))}</b></div>
    </div>
  `

  const assignmentHtml = (row: Assignment) => {
    const itemRows = (row.items || []).map((item) => `
      <tr>
        <td>${escapeHtml(item.product?.name || '—')}${item.product?.sku ? `<div style="color:#5d6b62;font-size:10px;">${escapeHtml(item.product.sku)}</div>` : ''}</td>
        <td style="text-align:right;">${item.quantity_out}</td>
        <td style="text-align:right;">${item.quantity_returned}</td>
        <td style="text-align:right;">${item.quantity_lost}</td>
        <td style="text-align:right;">${soldQty(item)}</td>
        <td style="text-align:right;">${escapeHtml(money(item.unit_price))}</td>
        <td style="text-align:right;">${escapeHtml(money(soldQty(item) * Number(item.unit_price || 0)))}</td>
      </tr>
    `).join('')

    return `
      <section style="margin:0 0 16px;padding:12px;border:1px solid #e4ece6;border-radius:12px;break-inside:avoid;">
        <div style="display:flex;justify-content:space-between;gap:12px;margin-bottom:8px;">
          <div>
            <div style="font-weight:700;font-size:13px;">${escapeHtml(agentLabel(row))}</div>
            <div style="color:#5d6b62;font-size:11px;">${escapeHtml([row.member?.phone, row.office?.name].filter(Boolean).join(' · '))}</div>
          </div>
          <div style="text-align:right;font-size:11px;line-height:1.5;">
            <div>${escapeHtml(t('kams.sold'))}: <b>${assignmentSoldUnits(row)}</b></div>
            <div>${escapeHtml(t('kams.amountDue'))}: <b>${escapeHtml(money(row.expected_amount))}</b></div>
            <div>${escapeHtml(t('kams.cashCollected'))}: <b>${escapeHtml(money(row.collected_amount))}</b></div>
          </div>
        </div>
        <table>
          <thead>
            <tr>
              <th>${escapeHtml(t('kams.product'))}</th>
              <th style="text-align:right;">${escapeHtml(t('kams.quantity'))}</th>
              <th style="text-align:right;">${escapeHtml(t('kams.returned'))}</th>
              <th style="text-align:right;">${escapeHtml(t('kams.lost'))}</th>
              <th style="text-align:right;">${escapeHtml(t('kams.sold'))}</th>
              <th style="text-align:right;">${escapeHtml(t('kams.salePrice'))}</th>
              <th style="text-align:right;">${escapeHtml(t('kams.lineDue'))}</th>
            </tr>
          </thead>
          <tbody>
            ${itemRows || `<tr><td class="empty" colspan="7">${escapeHtml(t('common.empty'))}</td></tr>`}
          </tbody>
        </table>
      </section>
    `
  }

  const dayHtml = (day: DayReport, simplified = false) => `
    <section style="margin:0 0 22px;break-inside:avoid;">
      <h2 style="margin:0 0 8px;color:#094924;font-size:16px;font-weight:800;text-transform:capitalize;">${escapeHtml(formatDay(day.date, locale))}</h2>
      ${totalsHtml(day.totals)}
      ${simplified
        ? (day.assignments.length
          ? `<table>
              <thead>
                <tr>
                  <th>${escapeHtml(t('kams.agents'))}</th>
                  <th>${escapeHtml(t('kams.office'))}</th>
                  <th style="text-align:right;">${escapeHtml(t('kams.sold'))}</th>
                  <th style="text-align:right;">${escapeHtml(t('kams.amountDue'))}</th>
                  <th style="text-align:right;">${escapeHtml(t('kams.cashCollected'))}</th>
                </tr>
              </thead>
              <tbody>
                ${day.assignments.map((row) => `
                  <tr>
                    <td>${escapeHtml(row.member?.full_name || '—')}</td>
                    <td>${escapeHtml(row.office?.name || '—')}</td>
                    <td style="text-align:right;">${assignmentSoldUnits(row)}</td>
                    <td style="text-align:right;">${escapeHtml(money(row.expected_amount))}</td>
                    <td style="text-align:right;">${escapeHtml(money(row.collected_amount))}</td>
                  </tr>
                `).join('')}
              </tbody>
            </table>`
          : `<p style="color:#5d6b62;font-size:12px;">${escapeHtml(t('common.empty'))}</p>`)
        : (day.assignments.map(assignmentHtml).join('') || `<p style="color:#5d6b62;font-size:12px;">${escapeHtml(t('common.empty'))}</p>`)}
    </section>
  `

  const officeScopeLabel = data?.office_name || t('kams.allOffices')
  const withOffice = (title: string) => `${title} — ${officeScopeLabel}`

  const buildDaysHtml = (days: DayReport[], title: string, subtitle?: string, simplified = false) => {
    if (!data) return ''
    const periodLabel = t('kams.periodRange', {
      start: new Date(`${data.range.from}T12:00:00`).toLocaleDateString(locale),
      end: new Date(`${data.range.to}T12:00:00`).toLocaleDateString(locale),
    })
    return buildBrandedDocumentHtml({
      title,
      module: 'KAMS',
      subtitle: [subtitle, periodLabel].filter(Boolean).join(' · '),
      meta: [
        { label: t('kams.filterOffice'), value: officeScopeLabel },
        { label: t('common.printedOn'), value: new Date().toLocaleString(locale) },
        { label: t('kams.assignments'), value: String(days.reduce((sum, day) => sum + day.totals.assignments, 0)) },
        { label: t('kams.sold'), value: String(days.reduce((sum, day) => sum + day.totals.sold_units, 0)) },
        { label: t('kams.cashCollected'), value: money(days.reduce((sum, day) => sum + day.totals.collected_amount, 0)) },
      ],
      footerNote: t('common.confidential'),
      orientation: 'landscape',
      body: days.map((day) => dayHtml(day, simplified)).join('') || `<p class="empty">${escapeHtml(t('common.empty'))}</p>`,
    })
  }

  const printDays = (days: DayReport[], title: string, subtitle?: string, simplified = false) => {
    const html = buildDaysHtml(days, title, subtitle, simplified)
    if (html) printHtml(html)
  }

  const previewDays = (days: DayReport[], title: string, subtitle?: string, simplified = false) => {
    const html = buildDaysHtml(days, title, subtitle, simplified)
    if (!html) return
    setPrintPreviewTitle(title)
    setPrintPreviewHtml(html)
  }

  const allTitle = withOffice(t('kams.assignmentReports'))
  const allSubtitle = t('kams.assignmentReportsHint')
  const simplifiedTitle = withOffice(t('kams.simplifiedReport'))
  const dayTitle = (day: DayReport, simplified = false) => (
    simplified
      ? withOffice(t('kams.simplifiedReport'))
      : withOffice(`${t('kams.assignmentReports')} — ${formatDay(day.date, locale)}`)
  )

  if (loading && !data) return <LoadingScreen />

  const totals = data?.totals
  const canPrint = Boolean(data && (data.days || []).length > 0)

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-2">
          {data ? (
            <p className="shrink-0 whitespace-nowrap text-[11px] text-muted sm:text-xs">
              {t('kams.periodRange', {
                start: new Date(`${data.range.from}T12:00:00`).toLocaleDateString(locale),
                end: new Date(`${data.range.to}T12:00:00`).toLocaleDateString(locale),
              })}
            </p>
          ) : <span />}
          <div className="flex shrink-0 items-center justify-end gap-1.5">
            <button
              type="button"
              onClick={() => setFiltersOpen((open) => !open)}
              className={cn('shrink-0 rounded-full px-2.5 py-2 text-xs font-semibold sm:px-3 sm:text-sm', filtersOpen ? 'bg-lhms text-white' : 'bg-white text-lhms')}
            >
              {filtersOpen ? t('common.hide') : t('common.filter')}
            </button>
            <KamsTransactionsPrintMenu
              buttonClassName="px-2.5 sm:px-4"
              canPreview={canPrint}
              canPrint={canPrint}
              onPreview={() => data && previewDays(data.days.map(sortedDay), allTitle, allSubtitle)}
              onPrint={() => data && printDays(data.days.map(sortedDay), allTitle, allSubtitle)}
              onPreviewSimplified={() => data && previewDays(data.days.map(sortedDay), simplifiedTitle, allSubtitle, true)}
              onPrintSimplified={() => data && printDays(data.days.map(sortedDay), simplifiedTitle, allSubtitle, true)}
            />
          </div>
        </div>
        {filtersOpen ? (
          <div className="flex w-full min-w-0 flex-col gap-2 sm:items-end">
            <div className="flex w-full min-w-0 overflow-x-auto rounded-full bg-white p-1 sm:w-auto">
              {(['day', 'week', 'month'] as const).map((item) => (
                <button
                  key={item}
                  type="button"
                  onClick={() => applyPreset(item)}
                  className={cn(
                    'min-h-10 min-w-0 flex-1 rounded-full px-2 text-xs font-semibold whitespace-nowrap sm:flex-none sm:px-4 sm:text-sm',
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
                  'min-h-10 min-w-0 flex-1 rounded-full px-2 text-xs font-semibold whitespace-nowrap sm:flex-none sm:px-4 sm:text-sm',
                  period === 'custom' ? 'bg-lhms text-white' : 'text-muted hover:text-ink',
                )}
              >
                {t('dashboard.customPeriod')}
              </button>
            </div>
            {period === 'custom' ? (
              <div className="grid w-full grid-cols-2 gap-2 sm:w-80">
                <Field
                  className="min-w-0 max-w-full"
                  label={t('kams.dateFrom')}
                  type="date"
                  value={from}
                  max={to || today}
                  onChange={(event) => setFrom(event.target.value)}
                />
                <Field
                  className="min-w-0 max-w-full"
                  label={t('kams.dateTo')}
                  type="date"
                  value={to}
                  min={from}
                  max={today}
                  onChange={(event) => setTo(event.target.value)}
                />
              </div>
            ) : null}
          </div>
        ) : null}
      </div>

      {totals ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
          <Card className="p-4 text-sm">{t('kams.agents')}: <b>{totals.agents}</b></Card>
          <Card className="p-4 text-sm">{t('kams.assignments')}: <b>{totals.assignments}</b></Card>
          <Card className="p-4 text-sm">{t('kams.sold')}: <b>{totals.sold_units}</b></Card>
          <Card className="flex flex-col gap-1 p-4 text-sm">
            <span className="whitespace-nowrap">{t('kams.amountDue')}:</span>
            <b>{money(totals.expected_amount)}</b>
          </Card>
          <Card className="flex flex-col gap-1 p-4 text-sm">
            <span className="whitespace-nowrap">{t('kams.cashCollected')}:</span>
            <b>{money(totals.collected_amount)}</b>
          </Card>
          <Card className="flex flex-col gap-1 p-4 text-sm">
            <span className="whitespace-nowrap">{t('kams.shortfall')}:</span>
            <b>{money(totals.shortfall)}</b>
          </Card>
        </div>
      ) : null}

      <div className="grid gap-3">
        {(data?.days || []).map((day) => {
          const expanded = expandedDays.includes(day.date)
          return (
            <Card key={day.date} className="overflow-hidden">
              <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:p-5">
                <button
                  type="button"
                  onClick={() => toggleDay(day.date)}
                  aria-expanded={expanded}
                  className="flex min-w-0 flex-1 items-start justify-between gap-3 text-left hover:opacity-90"
                >
                  <div className="min-w-0">
                    <div className="font-semibold capitalize">{formatDay(day.date, locale)}</div>
                    <div className="mt-1 text-xs text-muted sm:text-sm">
                      {day.totals.assignments} {t('kams.assignments').toLowerCase()}
                      {' · '}
                      {day.totals.agents} {t('kams.agents').toLowerCase()}
                      {' · '}
                      {t('kams.sold')} {day.totals.sold_units}
                      {' · '}
                      {t('kams.cashCollected')} {money(day.totals.collected_amount)}
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    <Badge tone="green">{money(day.totals.expected_amount)}</Badge>
                    <ChevronDown size={18} className={cn('text-muted transition', expanded ? 'rotate-0' : '-rotate-90')} />
                  </div>
                </button>
                <KamsTransactionsPrintMenu
                  iconOnly
                  className="shrink-0 self-end sm:self-auto"
                  buttonClassName="h-11 w-11 px-0"
                  onPreview={() => {
                    const sorted = sortedDay(day)
                    previewDays([sorted], dayTitle(day), allSubtitle)
                  }}
                  onPrint={() => {
                    const sorted = sortedDay(day)
                    printDays([sorted], dayTitle(day), allSubtitle)
                  }}
                  onPreviewSimplified={() => {
                    const sorted = sortedDay(day)
                    previewDays([sorted], dayTitle(day, true), allSubtitle, true)
                  }}
                  onPrintSimplified={() => {
                    const sorted = sortedDay(day)
                    printDays([sorted], dayTitle(day, true), allSubtitle, true)
                  }}
                />
              </div>
              {expanded ? (
                <div className="space-y-3 border-t border-line bg-surface px-4 py-4 sm:px-5">
                  <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                    <div className="grid flex-1 grid-cols-2 gap-2 text-sm">
                      <div className="rounded-xl border border-line bg-white px-3 py-2">{t('kams.sold')}: <b>{day.totals.sold_units}</b></div>
                      <div className="rounded-xl border border-line bg-white px-3 py-2">{t('kams.returned')}: <b>{day.totals.returned_units}</b></div>
                    </div>
                    <div className="w-full sm:w-56">
                      <Select
                        label={t('kams.reportSortLabel')}
                        value={daySort(day.date)}
                        onChange={(e) => setDaySort(day.date, e.target.value as DaySort)}
                      >
                        {DAY_SORTS.map((value) => (
                          <option key={value} value={value}>{t(`kams.reportSort.${value}`)}</option>
                        ))}
                      </Select>
                    </div>
                  </div>
                  {sortedDay(day).assignments.map((row) => {
                    const open = expandedAssignments.includes(row.id)
                    return (
                      <div key={row.id} className="overflow-hidden rounded-xl border border-line bg-white">
                        <button
                          type="button"
                          onClick={() => toggleAssignment(row.id)}
                          aria-expanded={open}
                          className="flex w-full items-start justify-between gap-3 px-4 py-3 text-left hover:bg-surface/50"
                        >
                          <div className="min-w-0">
                            <div className="font-semibold">{agentLabel(row)}</div>
                            {row.member?.phone || row.office?.name ? (
                              <div className="text-sm text-muted">
                                {[row.member?.phone, row.office?.name].filter(Boolean).join(' · ')}
                              </div>
                            ) : null}
                          </div>
                          <div className="flex shrink-0 flex-col items-end gap-1 text-xs sm:flex-row sm:items-center sm:gap-3 sm:text-sm">
                            <div className="text-right">
                              <div className="text-[11px] uppercase tracking-wide text-muted">{t('kams.sold')}</div>
                              <div className="font-semibold tabular-nums text-lhms">{assignmentSoldUnits(row)}</div>
                            </div>
                            <div className="text-right">
                              <div className="text-[11px] uppercase tracking-wide text-muted">{t('kams.cashCollected')}</div>
                              <div className="font-semibold tabular-nums">{money(row.collected_amount)}</div>
                            </div>
                            <ChevronDown size={16} className={cn('text-muted transition', open ? 'rotate-0' : '-rotate-90')} />
                          </div>
                        </button>
                        {open ? (
                          <div className="border-t border-line px-4 py-3">
                            <div className="mb-3 grid gap-2 sm:grid-cols-3 text-sm">
                              <div>{t('kams.sold')}: <b>{assignmentSoldUnits(row)}</b></div>
                              <div>{t('kams.amountDue')}: <b>{money(row.expected_amount)}</b></div>
                              <div>{t('kams.cashCollected')}: <b>{money(row.collected_amount)}</b></div>
                            </div>
                            <div className="overflow-x-auto rounded-xl border border-line">
                              <div className="grid min-w-full grid-cols-[minmax(0,1fr)_4.5rem_4.5rem_7rem]">
                                <div className="col-span-4 grid grid-cols-subgrid items-end gap-x-2 border-b border-line bg-[#f4faf5] px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide text-muted">
                                  <span>{t('kams.product')}</span>
                                  <span className="text-right">{t('kams.quantity')}</span>
                                  <span className="text-right">{t('kams.sold')}</span>
                                  <span className="text-right">{t('kams.salePrice')}</span>
                                </div>
                                {(row.items || []).map((item) => (
                                  <div key={item.id} className="col-span-4 grid grid-cols-subgrid items-center gap-x-2 border-t border-line px-3 py-3 text-sm">
                                    <div className="flex min-w-0 items-center gap-2">
                                      <span className="grid h-10 w-10 shrink-0 overflow-hidden place-items-center rounded-lg bg-lhms-soft text-lhms">
                                        {item.product?.image_url ? (
                                          <img src={item.product.image_url} alt={item.product.name || ''} className="h-full w-full object-cover" />
                                        ) : (
                                          <Package size={16} />
                                        )}
                                      </span>
                                      <div className="min-w-0">
                                        <div className="truncate font-semibold">{item.product?.name || '—'}</div>
                                        {item.product?.sku ? <div className="truncate text-xs text-muted">{item.product.sku}</div> : null}
                                      </div>
                                    </div>
                                    <span className="text-right font-semibold tabular-nums">{item.quantity_out}</span>
                                    <span className="text-right font-semibold tabular-nums text-lhms">{soldQty(item)}</span>
                                    <span className="text-right tabular-nums text-muted">{money(item.unit_price)}</span>
                                  </div>
                                ))}
                              </div>
                            </div>
                          </div>
                        ) : null}
                      </div>
                    )
                  })}
                </div>
              ) : null}
            </Card>
          )
        })}
      </div>

      {!loading && (data?.days || []).length === 0 ? <Empty>{t('common.empty')}</Empty> : null}

      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={printPreviewTitle || t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />
    </div>
  )
}
