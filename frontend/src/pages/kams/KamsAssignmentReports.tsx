import { Fragment, useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useOutletContext, useParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { intlLocale } from '../../i18n/locales'
import { ChevronDown, Info, Package, Trash2 } from 'lucide-react'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { PaginationButtons } from '../../components/PaginationButtons'
import { Badge, Button, Card, Field, Modal } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
import { trackLoadSpinner } from '../../lib/dataSync'
import { cn } from '../../lib/cn'
import { money } from '../../lib/format'
import { buildBrandedDocumentHtml, printHtml } from '../../lib/printDocument'
import { KamsTransactionsPrintMenu } from './KamsAccountTransactions'
import type { OperationsOfficeFilter } from './KamsStockFlows'

type Period = 'day' | 'week' | 'month' | 'custom'
type DaySort = 'name' | 'code' | 'amount' | 'due' | 'quantity' | 'reference'
type ReportSort = 'az' | 'za' | 'sold' | 'collected'

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
  closed_by?: { id: number; name: string } | null
  day_closed_by?: { id: number; name: string } | null
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
  day_closed_by?: string
}

type ReportPayload = {
  period: Period
  range: { from: string; to: string }
  can_filter_offices?: boolean
  can_delete?: boolean
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

function formatDateTime(value: string, locale: string) {
  const normalized = value.includes('T') || value.includes(' ') ? value.replace(' ', 'T') : `${value}T00:00:00`
  return new Date(normalized).toLocaleString(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
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

function sortReportAssignments(rows: Assignment[], sort: ReportSort) {
  const list = [...rows]
  const byName = (left: Assignment, right: Assignment) => (left.member?.full_name || '').localeCompare(right.member?.full_name || '', undefined, { sensitivity: 'base' })
  list.sort((left, right) => {
    if (sort === 'za') return byName(right, left)
    if (sort === 'sold') return assignmentSoldUnits(right) - assignmentSoldUnits(left)
    if (sort === 'collected') return Number(right.collected_amount || 0) - Number(left.collected_amount || 0)
    return byName(left, right)
  })
  return list
}

export function KamsAssignmentReports() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const navigate = useNavigate()
  const { date: reportDate } = useParams()
  const detailDate = reportDate && /^\d{4}-\d{2}-\d{2}$/.test(reportDate) ? reportDate : ''
  const locale = intlLocale(i18n.language)
  const initial = useMemo(() => (detailDate ? { from: detailDate, to: detailDate } : presetRange('month')), [detailDate])
  const today = useMemo(() => isoDate(new Date()), [])
  const [period, setPeriod] = useState<Period>(detailDate ? 'day' : 'month')
  const [from, setFrom] = useState(initial.from)
  const [to, setTo] = useState(initial.to)
  const { officeId } = useOutletContext<OperationsOfficeFilter>()
  const [data, setData] = useState<ReportPayload | null>(null)
  const [expandedAssignments, setExpandedAssignments] = useState<number[]>([])
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [daySorts] = useState<Record<string, DaySort>>({})
  const [reportSort, setReportSort] = useState<ReportSort>('az')
  const [loading, setLoading] = useState(true)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [printPreviewTitle, setPrintPreviewTitle] = useState('')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [deletingDate, setDeletingDate] = useState<string | null>(null)
  const [deleteError, setDeleteError] = useState('')
  const [reloadKey, setReloadKey] = useState(0)

  const load = () => {
    if (!from || !to) return
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/stock/assignments/report', {
      params: {
        from,
        to,
        period,
        ...(officeId ? { office_id: officeId } : {}),
      },
    }).then((res) => {
      const payload = res.data.data as ReportPayload
      setData(payload)
      setPage(1)
    }).catch(() => {
      setData({
        period,
        range: { from, to },
        can_filter_offices: false,
        can_delete: false,
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
    }).finally(stop)
  }

  useEffect(() => {
    void load()
  }, [from, to, period, officeId, reloadKey])

  useLiveData(load, { enabled: Boolean(from && to) })

  const canDelete = hasPermission('kams.stock.reports.delete') || Boolean(data?.can_delete)

  const confirmDeleteReport = async () => {
    if (!deletingDate) return
    const date = deletingDate
    setDeleteError('')
    try {
      await withBusy('data', async () => {
        await api.delete('/modules/kams/stock/assignments/report', {
          data: {
            date,
            ...(officeId ? { office_id: officeId } : {}),
          },
        })
      })
      setDeletingDate(null)
      if (detailDate && detailDate === date) {
        navigate('/modules/kams/stock/assignments/reports')
      }
      setReloadKey((value) => value + 1)
    } catch (error: unknown) {
      const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
      setDeleteError(message || t('common.alertError'))
    }
  }

  useEffect(() => {
    if (!detailDate) return
    setPeriod('day')
    setFrom(detailDate)
    setTo(detailDate)
  }, [detailDate])

  const applyPreset = (item: Exclude<Period, 'custom'>) => {
    const next = presetRange(item)
    setPeriod(item)
    setFrom(next.from)
    setTo(next.to)
  }

  const toggleAssignment = (id: number) => {
    setExpandedAssignments((current) => (
      current.includes(id) ? current.filter((item) => item !== id) : [...current, id]
    ))
  }

  const daySort = (date: string): DaySort => daySorts[date] || 'name'

  const sortedDay = (day: DayReport): DayReport => ({
    ...day,
    assignments: detailDate
      ? sortReportAssignments(day.assignments || [], reportSort)
      : sortAssignments(day.assignments || [], daySort(day.date)),
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
      <h2 style="margin:0 0 8px;color:#043711;font-size:16px;font-weight:800;text-transform:capitalize;">${escapeHtml(formatDay(day.date, locale))}</h2>
      ${totalsHtml(day.totals)}
      ${simplified
        ? (day.assignments.length
          ? `<table>
              <thead>
                <tr>
                  <th>${escapeHtml(t('kams.agent'))}</th>
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
          : `<p style="color:#5d6b62;font-size:12px;text-align:center;">${escapeHtml(t('common.empty'))}</p>`)
        : (day.assignments.map(assignmentHtml).join('') || `<p style="color:#5d6b62;font-size:12px;text-align:center;">${escapeHtml(t('common.empty'))}</p>`)}
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

  if (loading && !data) return <LoadingScreen />

  const totals = data?.totals
  const hasPrintableRows = Boolean(data && (data.days || []).length > 0)
  const canPrint = hasPermission('kams.stock.reports.print') && hasPrintableRows
  const canPreview = hasPermission('kams.stock.reports.preview') && hasPrintableRows
  const lines = (data?.days || []).flatMap((day) => sortReportAssignments(day.assignments || [], reportSort).map((assignment) => ({ date: day.date, assignment })))
  const lastPage = Math.max(1, Math.ceil(lines.length / perPage) || 1)
  const safePage = Math.min(page, lastPage)
  const pageRows = lines.slice((safePage - 1) * perPage, safePage * perPage)
  const rangeStart = lines.length === 0 ? 0 : (safePage - 1) * perPage + 1
  const rangeEnd = Math.min(safePage * perPage, lines.length)
  const days = data?.days || []
  const dayLastPage = Math.max(1, Math.ceil(days.length / perPage) || 1)
  const safeDayPage = Math.min(page, dayLastPage)
  const dayRows = days.slice((safeDayPage - 1) * perPage, safeDayPage * perPage)
  const dayRangeStart = days.length === 0 ? 0 : (safeDayPage - 1) * perPage + 1
  const dayRangeEnd = Math.min(safeDayPage * perPage, days.length)

  if (!detailDate) {
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
                canPreview={canPreview}
                canPrint={canPrint}
                disabled={!days.length}
                onPreview={() => data && previewDays(data.days.map(sortedDay), allTitle, allSubtitle, true)}
                onPrint={() => data && printDays(data.days.map(sortedDay), allTitle, allSubtitle, true)}
                onPreviewSimplified={() => data && previewDays(data.days.map(sortedDay), simplifiedTitle, allSubtitle, true)}
                onPrintSimplified={() => data && printDays(data.days.map(sortedDay), simplifiedTitle, allSubtitle, true)}
              />
            </div>
          </div>
          {filtersOpen ? (
            <div className="flex w-full min-w-0 flex-col items-end gap-2">
              <div className="inline-flex w-fit max-w-full shrink-0 overflow-x-auto rounded-full bg-white p-1">
                {(['day', 'week', 'month'] as const).map((item) => (
                  <button
                    key={item}
                    type="button"
                    onClick={() => applyPreset(item)}
                    className={cn(
                      'min-h-10 shrink-0 rounded-full px-4 text-sm font-semibold whitespace-nowrap',
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
                    'min-h-10 shrink-0 rounded-full px-4 text-sm font-semibold whitespace-nowrap',
                    period === 'custom' ? 'bg-lhms text-white' : 'text-muted hover:text-ink',
                  )}
                >
                  {t('dashboard.customPeriod')}
                </button>
              </div>
              {period === 'custom' ? (
                <div className="grid w-full max-w-80 grid-cols-2 gap-2">
                  <Field className="min-w-0 max-w-full" label={t('kams.dateFrom')} type="date" value={from} max={to || today} onChange={(event) => setFrom(event.target.value)} />
                  <Field className="min-w-0 max-w-full" label={t('kams.dateTo')} type="date" value={to} min={from} max={today} onChange={(event) => setTo(event.target.value)} />
                </div>
              ) : null}
            </div>
          ) : null}
        </div>
        <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(4,55,17,0.04)]">
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-lhms text-white">
                <tr className="text-left text-xs font-semibold tracking-wide">
                  <th className="bg-lhms px-4 py-2 font-semibold">{t('common.date')}</th>
                  <th className="bg-lhms px-4 py-2 font-semibold">{t('kams.dayClosedBy')}</th>
                  <th className="bg-lhms px-4 py-2 text-right font-semibold">{t('kams.assignments')}</th>
                  <th className="bg-lhms px-4 py-2 text-right font-semibold">{t('kams.sold')}</th>
                  <th className="bg-lhms px-4 py-2 text-right font-semibold">{t('kams.amountDue')}</th>
                  <th className="bg-lhms px-4 py-2 text-right font-semibold">{t('kams.cashCollected')}</th>
                  <th className="bg-lhms px-3 py-2 text-right font-semibold">{t('common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {dayRows.length === 0 ? (
                  <tr>
                    <td colSpan={7} className="px-4 py-8 text-center text-muted">{t('common.empty')}</td>
                  </tr>
                ) : dayRows.map((day, index) => (
                  <tr key={day.date} className={cn('border-t border-line', index % 2 === 1 ? 'bg-emerald-50' : 'bg-white')}>
                    <td className="px-4 py-2 font-semibold capitalize">{formatDay(day.date, locale)}</td>
                    <td className="px-4 py-2">{day.day_closed_by || '—'}</td>
                    <td className="px-4 py-2 text-right tabular-nums">{day.totals.assignments}</td>
                    <td className="px-4 py-2 text-right tabular-nums">{day.totals.sold_units}</td>
                    <td className="px-4 py-2 text-right whitespace-nowrap tabular-nums">{money(day.totals.expected_amount)}</td>
                    <td className="px-4 py-2 text-right whitespace-nowrap tabular-nums">{money(day.totals.collected_amount)}</td>
                    <td className="px-3 py-2 text-right">
                      <div className="inline-flex items-center justify-end gap-1">
                        <Link
                          to={`/modules/kams/stock/assignments/reports/${day.date}`}
                          aria-label={t('kams.dayReportDetails')}
                          className="inline-flex h-9 w-9 items-center justify-center rounded-xl bg-lhms/10 text-lhms hover:bg-lhms/20"
                        >
                          <Info size={20} />
                        </Link>
                        {canDelete ? (
                          <button
                            type="button"
                            aria-label={t('kams.deleteDayReport')}
                            title={t('kams.deleteDayReport')}
                            className="inline-flex h-9 w-9 items-center justify-center rounded-xl text-danger hover:bg-danger/10"
                            onClick={() => { setDeleteError(''); setDeletingDate(day.date) }}
                          >
                            <Trash2 size={18} />
                          </button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <div className="flex flex-col gap-3 border-t border-line px-4 py-2 sm:flex-row sm:items-center sm:justify-between">
            <p className="text-sm text-muted">{t('kams.pageRange', { from: dayRangeStart, to: dayRangeEnd, total: days.length })}</p>
            <div className="flex flex-wrap items-center gap-2">
              <label className="flex items-center gap-2 text-sm text-muted">
                {t('kams.rowsPerPage')}
                <select
                  value={perPage}
                  onChange={(event) => { setPerPage(Number(event.target.value)); setPage(1) }}
                  className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink"
                >
                  {[10, 20, 50].map((size) => <option key={size} value={size}>{size}</option>)}
                </select>
              </label>
              <PaginationButtons
                page={safeDayPage}
                lastPage={dayLastPage}
                onPrev={() => setPage((value) => Math.max(1, value - 1))}
                onNext={() => setPage((value) => Math.min(dayLastPage, value + 1))}
              />
            </div>
          </div>
        </div>
        <PrintPreviewModal
          open={Boolean(printPreviewHtml)}
          title={printPreviewTitle || t('common.printPreview')}
          html={printPreviewHtml}
          onClose={() => setPrintPreviewHtml('')}
        />
        <Modal open={Boolean(deletingDate)} title={t('kams.deleteDayReport')} onClose={() => { setDeletingDate(null); setDeleteError('') }}>
          <div className="space-y-4">
            <p className="text-sm text-muted">
              {t('kams.deleteDayReportConfirm', { date: deletingDate ? formatDay(deletingDate, locale) : '' })}
            </p>
            {deleteError ? <p className="text-sm text-danger">{deleteError}</p> : null}
            <div className="flex justify-end gap-2">
              <Button variant="secondary" onClick={() => { setDeletingDate(null); setDeleteError('') }}>{t('common.cancel')}</Button>
              <Button onClick={() => void confirmDeleteReport()}>{t('common.delete')}</Button>
            </div>
          </div>
        </Modal>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3">
        <div className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <Link to="/modules/kams/stock/assignments/reports" className="text-sm font-semibold text-lhms">
              ← {t('kams.assignmentReports')}
            </Link>
            <p className="truncate text-base font-semibold capitalize">{formatDay(detailDate, locale)}</p>
            {data?.days?.[0]?.day_closed_by ? (
              <p className="truncate text-sm text-muted">{t('kams.dayClosedBy')}: {data.days[0].day_closed_by}</p>
            ) : null}
          </div>
          <div className="flex shrink-0 items-center justify-end gap-1.5">
            <KamsTransactionsPrintMenu
              buttonClassName="px-2.5 sm:px-4"
              canPreview={canPreview}
              canPrint={canPrint}
              disabled={!data?.days?.length}
              onPreview={() => data && previewDays(data.days.map(sortedDay), allTitle, allSubtitle)}
              onPrint={() => data && printDays(data.days.map(sortedDay), allTitle, allSubtitle)}
              onPreviewSimplified={() => data && previewDays(data.days.map(sortedDay), simplifiedTitle, allSubtitle, true)}
              onPrintSimplified={() => data && printDays(data.days.map(sortedDay), simplifiedTitle, allSubtitle, true)}
            />
          </div>
        </div>
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

      <div className="flex min-w-0 overflow-x-auto rounded-full bg-white p-1">
        {([
          ['az', 'kams.sortAz'],
          ['za', 'kams.sortZa'],
          ['sold', 'kams.sortSoldQuantity'],
          ['collected', 'kams.sortCollected'],
        ] as const).map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => { setReportSort(key); setPage(1) }}
            className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none', reportSort === key ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
          >
            {t(label)}
          </button>
        ))}
      </div>

      <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(4,55,17,0.04)]">
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-lhms text-white">
              <tr className="text-left text-xs font-semibold tracking-wide">
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.date')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.agent')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.assignmentClosedBy')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.phoneWhatsapp')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.office')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.reference')}</th>
                <th className="bg-lhms px-4 py-1.5 text-right font-semibold">{t('kams.sold')}</th>
                <th className="bg-lhms px-4 py-1.5 text-right font-semibold">{t('kams.amountDue')}</th>
                <th className="bg-lhms px-4 py-1.5 text-right font-semibold">{t('kams.cashCollected')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.status')}</th>
              </tr>
            </thead>
            <tbody>
              {pageRows.length === 0 ? (
                <tr>
                  <td colSpan={10} className="px-4 py-8 text-center text-muted">{t('common.empty')}</td>
                </tr>
              ) : pageRows.map(({ date, assignment: row }, index) => {
                const open = expandedAssignments.includes(row.id)
                return (
                  <Fragment key={row.id}>
                    <tr className={cn('border-t border-line', index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white')}>
                      <td className="w-px whitespace-nowrap px-3 py-1.5 text-muted">{formatDateTime(row.assigned_at || date, locale)}</td>
                      <td className="px-4 py-1.5 font-semibold whitespace-nowrap">{agentLabel(row)}</td>
                      <td className="px-4 py-1.5 whitespace-nowrap">{row.closed_by?.name || '—'}</td>
                      <td className="px-4 py-1.5 whitespace-nowrap">{row.member?.phone || '—'}</td>
                      <td className="px-4 py-1.5 whitespace-nowrap">{row.office?.name || '—'}</td>
                      <td className="px-4 py-1.5 whitespace-nowrap">
                        <button type="button" className="inline-flex items-center gap-1 font-semibold text-lhms" onClick={() => toggleAssignment(row.id)}>
                          {row.reference}
                          <ChevronDown size={14} className={cn('transition', open ? 'rotate-0' : '-rotate-90')} />
                        </button>
                      </td>
                      <td className="px-4 py-1.5 text-right font-semibold tabular-nums">{assignmentSoldUnits(row)}</td>
                      <td className="px-4 py-1.5 text-right whitespace-nowrap tabular-nums">{money(row.expected_amount)}</td>
                      <td className="px-4 py-1.5 text-right whitespace-nowrap tabular-nums">{money(row.collected_amount)}</td>
                      <td className="px-4 py-1.5 whitespace-nowrap">
                        <Badge tone={row.status === 'open' ? 'amber' : 'green'}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
                      </td>
                    </tr>
                    {open ? (
                      <tr className={index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white'}>
                        <td colSpan={10} className="px-4 pb-4">
                          <table className="min-w-full overflow-hidden rounded-xl border border-line text-sm">
                            <thead className="bg-lhms text-white">
                              <tr className="text-left text-xs font-semibold">
                                <th className="bg-lhms px-3 py-2 font-semibold">{t('kams.product')}</th>
                                <th className="bg-lhms px-3 py-2 text-right font-semibold">{t('kams.quantity')}</th>
                                <th className="bg-lhms px-3 py-2 text-right font-semibold">{t('kams.sold')}</th>
                                <th className="bg-lhms px-3 py-2 text-right font-semibold">{t('kams.salePrice')}</th>
                              </tr>
                            </thead>
                            <tbody>
                              {(row.items || []).map((item) => (
                                <tr key={item.id} className="border-t border-line bg-white">
                                  <td className="px-3 py-2">
                                    <div className="flex min-w-0 items-center gap-2">
                                      <span className="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-lg bg-lhms-soft text-lhms">
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
                                  </td>
                                  <td className="px-3 py-2 text-right tabular-nums">{item.quantity_out}</td>
                                  <td className="px-3 py-2 text-right font-semibold tabular-nums text-lhms">{soldQty(item)}</td>
                                  <td className="px-3 py-2 text-right tabular-nums">{money(item.unit_price)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        </td>
                      </tr>
                    ) : null}
                  </Fragment>
                )
              })}
            </tbody>
          </table>
        </div>
        <div className="flex flex-col gap-3 border-t border-line px-4 py-1.5 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">{t('kams.pageRange', { from: rangeStart, to: rangeEnd, total: lines.length })}</p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2 text-sm text-muted">
              {t('kams.rowsPerPage')}
              <select
                value={perPage}
                onChange={(event) => {
                  setPerPage(Number(event.target.value))
                  setPage(1)
                }}
                className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink"
              >
                {[10, 20, 50].map((size) => (
                  <option key={size} value={size}>{size}</option>
                ))}
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

      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={printPreviewTitle || t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />
    </div>
  )
}
