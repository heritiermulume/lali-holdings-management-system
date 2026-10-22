import { BadgeCheck, Building2, Camera, Check, CheckCircle2, Download, Eye, EyeOff, Info, ListFilter, Pencil, Plus, Printer, Search, Trash2, Upload, UserPlus } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Outlet, Navigate, useOutletContext } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { PhotoSourcePicker } from '../../components/PhotoSourcePicker'
import { OfficeFilterSelect } from '../../components/OfficeFilterSelect'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { PaginationButtons } from '../../components/PaginationButtons'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { showAlert } from '../../lib/alert'
import { cn } from '../../lib/cn'
import { initials, roleLabel } from '../../lib/format'
import { buildBrandedTableHtml, buildServiceCardHtml, printHtml } from '../../lib/printDocument'
import { useAuth } from '../../context/AuthContext'
import { useLiveData } from '../../hooks/useLiveData'
import { useOfficeScope } from '../../hooks/useOfficeScope'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
import { trackLoadSpinner } from '../../lib/dataSync'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'
import { KamsRecruitmentDashboard } from './KamsRecapPage'

type ListPeriod = 'day' | 'week' | 'month' | 'custom'

function isoDate(value: Date) {
  const year = value.getFullYear()
  const month = String(value.getMonth() + 1).padStart(2, '0')
  const day = String(value.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function presetRange(period: Exclude<ListPeriod, 'custom'>) {
  const today = new Date()
  const end = new Date(today.getFullYear(), today.getMonth(), today.getDate())
  if (period === 'day') {
    const day = isoDate(end)
    return { from: day, to: day }
  }
  if (period === 'month') {
    const start = new Date(end.getFullYear(), end.getMonth(), 1)
    const monthEnd = new Date(end.getFullYear(), end.getMonth() + 1, 0)
    return { from: isoDate(start), to: isoDate(monthEnd) }
  }
  const weekday = end.getDay()
  const start = new Date(end)
  start.setDate(end.getDate() - (weekday === 0 ? 6 : weekday - 1))
  const weekEnd = new Date(start)
  weekEnd.setDate(start.getDate() + 6)
  return { from: isoDate(start), to: isoDate(weekEnd) }
}

type LookupOffice = { id: number; company_id: number; name: string; code: string }
type LookupCompany = { id: number; name: string; code: string }
type LookupPerson = { id: number; company_id: number; office_id?: number | null; user_id?: number | null; member_code: string; full_name: string; phone?: string | null; role_id?: number | null; rank?: string | null }
type LookupRole = { id: number; name: string; slug?: string; offices?: Array<{ id: number; company_id: number }> }
type Candidate = {
  id: number
  company_id?: number
  office_id?: number | null
  candidate_id: string
  first_name?: string | null
  last_name?: string | null
  full_name: string
  gender?: string | null
  birth_date?: string | null
  photo_url?: string | null
  phone?: string | null
  email?: string | null
  address?: string | null
  guardian_name?: string | null
  guardian_phone?: string | null
  guardian_member_id?: number | null
  source: string
  status: string
  applied_at: string
  notes?: string | null
  office?: { name?: string }
  member?: {
    member_code?: string
    coach?: { id: number; full_name: string; member_code?: string | null } | null
    coach_name?: string | null
  } | null
  latest_interview?: {
    result?: string | null
    observation_starts_at?: string | null
    observation_ends_at?: string | null
  } | null
  latest_observation_day?: {
    decision?: string | null
    supervisor_name?: string | null
    supervisor?: { id: number; full_name: string; member_code?: string | null } | null
  } | null
}
type MemberAccount = { email?: string | null; phone?: string | null; password?: string | null; is_active?: boolean }
type Member = {
  id: number
  company_id?: number
  office_id?: number | null
  member_code: string
  first_name?: string | null
  last_name?: string | null
  full_name: string
  photo_url?: string | null
  phone?: string | null
  email?: string | null
  address?: string | null
  gender?: string | null
  birth_date?: string | null
  origin: string
  status: string
  joined_on?: string | null
  migrated_at?: string | null
  trainer_name?: string | null
  trainer_user_id?: number | null
  trainer?: { id: number; name: string; email?: string | null } | null
  coach_member_id?: number | null
  coach_name?: string | null
  coach?: { id: number; full_name: string; member_code?: string | null } | null
  manager_member_id?: number | null
  assistant_manager_member_id?: number | null
  fixed_salary?: number | null
  manager_member?: { id: number; full_name: string } | null
  assistant_manager?: { id: number; full_name: string } | null
  manager_name?: string | null
  team_name?: string | null
  guardian_name?: string | null
  guardian_phone?: string | null
  rank?: string | null
  role_id?: number | null
  role?: { id: number; name: string; slug?: string } | null
  is_certified?: boolean
  can_be_certified?: boolean
  first_week_completed?: boolean
  company?: { id?: number; name?: string; legal_name?: string | null; code?: string } | null
  office?: { name?: string; code?: string }
  candidate?: {
    id?: number
  } | null
  promotion_eligible?: boolean
  promotion_for?: 'chef' | 'assistant_manager' | null
  coached_members_count?: number
  coached_members?: Array<{
    id: number
    member_code: string
    full_name: string
    phone?: string | null
    photo_url?: string | null
    gender?: string | null
    rank?: string | null
    role?: { id: number; name: string; slug?: string } | null
    office?: { name?: string; code?: string } | null
  }>
  opening_positions?: Array<{ id: number; category: string; amount: string | number; is_opening_balance: boolean }>
  account?: MemberAccount
}

const CERTIFIABLE_ROLE_SLUGS = new Set(['partner', 'vice_president', 'divisionnaire', 'manager', 'assistant_manager'])

function roleIsCertifiable(role?: { slug?: string } | null) {
  return Boolean(role?.slug && CERTIFIABLE_ROLE_SLUGS.has(role.slug))
}
function membersFromImport(data: { rows?: Array<{ member?: Member }> } | undefined): Member[] {
  return (data?.rows || []).map((row) => row.member).filter((member): member is Member => Boolean(member))
}

function personName(person?: { first_name?: string | null; last_name?: string | null; full_name?: string | null } | null) {
  return [person?.first_name, person?.last_name].filter(Boolean).join(' ') || person?.full_name || ''
}

function canRecordInterview(status: string) {
  return ['received', 'under_review', 'shortlisted', 'on_hold'].includes(status)
}

function canRecordObservation(status: string) {
  return ['observation', 'interview_admitted'].includes(status)
}

function memberRankLabel(
  t: (key: string, options?: { defaultValue?: string }) => string,
  rank?: string | null,
  gender?: string | null,
) {
  if (!rank) return '—'
  if (rank === 'Distributor' && gender === 'female') return t('kams.rank.Distributrice')
  return t(`kams.rank.${rank}`, { defaultValue: rank })
}

function csvCell(value: unknown) {
  return `"${String(value ?? '').replace(/"/g, '""')}"`
}

function downloadCsv(filename: string, lines: string[][]) {
  const body = lines.map((line) => line.map(csvCell).join(',')).join('\n')
  const blob = new Blob([`\uFEFF${body}`], { type: 'text/csv;charset=utf-8;' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function canDeleteCandidate(row: Candidate) {
  return !row.member && !['approved', 'registered'].includes(row.status)
}

type StepState = 'done' | 'current' | 'upcoming' | 'failed'

function interviewStepState(row: Candidate): StepState {
  if (canRecordInterview(row.status)) return 'current'
  const result = row.latest_interview?.result
  if (result === 'rejected' || result === 'abandoned') return 'failed'
  if (result === 'admitted' || ['observation', 'interview_admitted', 'approved', 'registered'].includes(row.status)) return 'done'
  if (['rejected', 'abandoned'].includes(row.status)) return 'failed'
  return 'upcoming'
}

function observationStepState(row: Candidate): StepState {
  if (canRecordObservation(row.status)) return 'current'
  if (['approved', 'registered'].includes(row.status) || row.latest_observation_day?.decision === 'approved') return 'done'
  if (row.latest_observation_day?.decision === 'rejected') return 'failed'
  return 'upcoming'
}

function stepCircleClass(state: StepState) {
  if (state === 'done') return 'border-lhms bg-lhms text-white'
  if (state === 'current') return 'border-lhms bg-lhms-soft text-lhms ring-4 ring-lhms/15'
  if (state === 'failed') return 'border-danger bg-white text-danger'
  return 'border-line bg-surface text-muted'
}

function stepLineClass(state: StepState) {
  if (state === 'done' || state === 'current' || state === 'failed') return 'bg-lhms'
  return 'bg-line'
}

function formatDateTime(value?: string | null) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleString()
}

function AgentCredentialsCard({
  name,
  email,
  phone,
  password,
  isActive,
  revealable = false,
}: {
  name?: string
  email?: string | null
  phone?: string | null
  password?: string | null
  isActive?: boolean
  revealable?: boolean
}) {
  const { t } = useTranslation()
  const [revealed, setRevealed] = useState(!revealable)
  const [copied, setCopied] = useState<'email' | 'phone' | 'password' | null>(null)
  const copy = async (field: 'email' | 'phone' | 'password', value: string) => {
    try {
      await navigator.clipboard.writeText(value)
    } catch {
      // Clipboard can be blocked in some browsers; still confirm the click.
    }
    setCopied(field)
    window.setTimeout(() => setCopied((current) => (current === field ? null : current)), 1600)
  }
  const mask = (value?: string | null) => {
    if (!value) return '—'
    return '•'.repeat(Math.min(Math.max(value.length, 8), 18))
  }
  const copyButton = (field: 'email' | 'phone' | 'password', value: string) => (
    <button
      type="button"
      className={cn(
        'inline-flex shrink-0 items-center gap-1 rounded-lg px-2 py-1 text-xs font-semibold transition',
        copied === field ? 'bg-lhms text-white' : 'text-lhms hover:bg-white',
      )}
      onClick={() => void copy(field, value)}
    >
      {copied === field ? <Check size={12} /> : null}
      {copied === field ? t('common.copied') : t('common.copy')}
    </button>
  )

  return (
    <div className="space-y-2 rounded-xl bg-surface p-3 text-sm">
      <div className="flex items-start justify-between gap-2">
        {name ? <div className="font-semibold">{name}</div> : <div className="font-semibold">{t('kams.loginCredentials')}</div>}
        {revealable ? (
          <button
            type="button"
            className="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-lhms"
            onClick={() => setRevealed((open) => !open)}
          >
            {revealed ? <EyeOff size={14} /> : <Eye size={14} />}
            {revealed ? t('common.hide') : t('common.show')}
          </button>
        ) : null}
      </div>
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <div className="text-xs text-muted">{t('kams.loginEmail')}</div>
          <div className="truncate font-semibold">{revealed ? (email || '—') : mask(email)}</div>
        </div>
        {revealed && email ? copyButton('email', email) : null}
      </div>
      {phone ? (
        <div className="flex items-center justify-between gap-2">
          <div className="min-w-0">
            <div className="text-xs text-muted">{t('kams.phone')}</div>
            <div className="truncate font-semibold">{revealed ? phone : mask(phone)}</div>
          </div>
          {revealed ? copyButton('phone', phone) : null}
        </div>
      ) : null}
      <div className="flex items-center justify-between gap-2">
        <div className="min-w-0">
          <div className="text-xs text-muted">{t('kams.loginPassword')}</div>
          <div className="truncate font-mono font-semibold">{revealed ? (password || '—') : mask(password)}</div>
        </div>
        {revealed && password ? copyButton('password', password) : null}
      </div>
      <div className={isActive ? 'text-lhms' : 'text-amber-700'}>
        {isActive ? t('kams.accountOn') : t('kams.accountOff')}
      </div>
    </div>
  )
}

function toDatetimeLocal(value?: string | null) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value.slice(0, 16)
  const pad = (part: number) => String(part).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function PersonPhoto({
  name,
  url,
  className = 'h-12 w-12 text-sm',
}: {
  name: string
  url?: string | null
  className?: string
}) {
  return (
    <span className={cn('grid shrink-0 overflow-hidden place-items-center rounded-full bg-lhms font-bold text-white', className)}>
      {url ? <img src={url} alt={name} className="h-full w-full object-cover" /> : initials(name)}
    </span>
  )
}

const toneFor = (status: string): 'green' | 'amber' | 'red' | 'slate' | 'lime' => {
  if (['active', 'approved', 'admitted', 'registered', 'verified', 'shortlisted'].includes(status)) return 'green'
  if (['received', 'imported', 'pending_verification', 'on_hold', 'under_review', 'observation'].includes(status)) return 'amber'
  if (['rejected', 'disputed', 'inactive', 'abandoned'].includes(status)) return 'red'
  if (['migrated'].includes(status)) return 'lime'
  return 'slate'
}

function parseTable(text: string): Array<Record<string, string>> {
  const trimmed = text.trim()
  if (!trimmed) return []
  if (trimmed.startsWith('[')) {
    const parsed = JSON.parse(trimmed) as unknown
    return Array.isArray(parsed) ? parsed as Array<Record<string, string>> : []
  }
  const lines = trimmed.split(/\r?\n/).filter(Boolean)
  const delimiter = lines[0].includes('\t') ? '\t' : ','
  const headers = lines[0].split(delimiter).map((h) => h.trim())
  return lines.slice(1).map((line) => {
    const cols = line.split(delimiter)
    const row: Record<string, string> = {}
    headers.forEach((header, index) => { row[header] = (cols[index] || '').trim() })
    return row
  })
}

const AGENT_IMPORT_HEADERS = [
  'member_code',
  'first_name',
  'last_name',
  'full_name',
  'gender',
  'birth_date',
  'phone',
  'email',
  'address',
  'office_code',
  'role',
  'coach_member_code',
  'joined_on',
  'status',
  'rank',
  'team_name',
  'guardian_name',
  'guardian_phone',
  'consignment_balance',
  'amounts_due',
  'distributor_savings',
  'manager_savings',
] as const

function openingAmount(row: Member, category: string) {
  const match = row.opening_positions?.find((position) => position.category === category)
  return match ? String(match.amount) : ''
}

function memberCoachName(row: Member) {
  return row.coach?.full_name
    || row.coach_name
    || row.trainer?.name
    || row.trainer_name
    || ''
}

function agentImportRow(row: Member): string[] {
  return [
    row.member_code || '',
    row.first_name || '',
    row.last_name || '',
    row.full_name || personName(row),
    row.gender || '',
    row.birth_date ? row.birth_date.slice(0, 10) : '',
    row.phone || '',
    row.email || '',
    row.address || '',
    row.office?.code || '',
    row.role?.slug || row.role?.name || '',
    row.coach?.member_code || '',
    row.joined_on ? row.joined_on.slice(0, 10) : '',
    row.status || '',
    row.rank || '',
    row.team_name || '',
    row.guardian_name || '',
    row.guardian_phone || '',
    openingAmount(row, 'consignment'),
    openingAmount(row, 'amounts_due'),
    openingAmount(row, 'distributor_savings'),
    openingAmount(row, 'manager_savings'),
  ]
}

export type RecruitmentListFilters = {
  period: ListPeriod
  from: string
  to: string
  officeId: string
  offices: LookupOffice[]
}

export function KamsMembersIndex() {
  const { hasPermission } = useAuth()
  if (hasPermission('kams.members.statistics.view')) return <KamsRecruitmentDashboard />
  if (hasPermission('kams.recruitment.applications.view')) return <Navigate to="/modules/kams/members/recruitment" replace />
  if (hasPermission('kams.recruitment.reports.view')) return <Navigate to="/modules/kams/members/recruitment/reports" replace />
  if (hasPermission('kams.members.agents.view')) return <Navigate to="/modules/kams/members/agents" replace />
  return <Navigate to="/modules/kams" replace />
}

export function KamsRecruitmentIndex() {
  const { hasPermission } = useAuth()
  if (hasPermission('kams.recruitment.applications.view')) return <KamsCandidates />
  if (hasPermission('kams.recruitment.reports.view')) return <Navigate to="/modules/kams/members/recruitment/reports" replace />
  return <Navigate to="/modules/kams/members" replace />
}

export function KamsRecruitmentWorkspace() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const today = isoDate(new Date())
  const [period, setPeriod] = useState<ListPeriod>('week')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [offices, setOffices] = useState<LookupOffice[]>([])
  const { officeId, setOfficeId, showOfficeFilter } = useOfficeScope(offices)
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const range = period === 'custom' ? { from, to } : presetRange(period)
  const formatDay = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString(locale)

  useEffect(() => {
    api.get('/modules/kams/recruitment/lookups').then((res) => {
      setOffices(res.data.data.offices ?? [])
    })
  }, [])

  return (
    <div>
      <div className="mb-4 flex flex-col gap-2">
        <div className="flex w-full min-w-0 items-center gap-2">
          {showOfficeFilter ? (
            <OfficeFilterSelect
              value={officeId}
              offices={offices}
              onChange={(e) => setOfficeId(e.target.value)}
              className="!w-auto !max-w-[42%] sm:!w-44 sm:!max-w-none"
            />
          ) : null}
          <p className="min-w-0 truncate text-left text-xs text-muted">
            {range.from && range.to ? t('kams.periodRange', { start: formatDay(range.from), end: formatDay(range.to) }) : null}
          </p>
          <span className="min-w-0 flex-1" aria-hidden />
          <button
            type="button"
            onClick={() => setFiltersOpen((open) => !open)}
            className={cn('shrink-0 rounded-full px-3 py-2 text-sm font-semibold', filtersOpen ? 'bg-lhms text-white' : 'bg-white text-lhms')}
          >
            {filtersOpen ? t('common.hide') : t('common.filter')}
          </button>
        </div>
        {filtersOpen ? (
          <>
            <div className="flex min-w-0 w-full overflow-x-auto rounded-full bg-white p-1 sm:ml-auto sm:w-auto">
              {(['day', 'week', 'month'] as const).map((item) => (
                <button
                  key={item}
                  type="button"
                  onClick={() => setPeriod(item)}
                  className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none', period === item ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
                >
                  {t(`kams.period.${item}`)}
                </button>
              ))}
              <button
                type="button"
                onClick={() => setPeriod('custom')}
                className={cn('min-h-10 flex-1 rounded-full px-4 text-sm font-semibold whitespace-nowrap sm:flex-none', period === 'custom' ? 'bg-lhms text-white' : 'text-muted hover:text-ink')}
              >
                {t('dashboard.customPeriod')}
              </button>
            </div>
            {period === 'custom' ? (
              <div className="grid w-full grid-cols-2 gap-2 sm:ml-auto sm:w-80">
                <Field className="min-w-0 max-w-full" label={t('dashboard.dateFrom')} type="date" value={from} max={to || today} onChange={(event) => setFrom(event.target.value)} />
                <Field className="min-w-0 max-w-full" label={t('dashboard.dateTo')} type="date" value={to} min={from} max={today} onChange={(event) => setTo(event.target.value)} />
              </div>
            ) : null}
          </>
        ) : null}
      </div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/members/recruitment', end: true, label: t('kams.applications'), show: hasPermission('kams.recruitment.applications.view') },
          { to: '/modules/kams/members/recruitment/reports', end: true, label: t('kams.recruitmentReports'), show: hasPermission('kams.recruitment.reports.view') },
        ]}
      />
      <Outlet context={{ period, from, to, officeId, offices } satisfies RecruitmentListFilters} />
    </div>
  )
}

export function KamsMembersWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/members', end: true, label: t('kams.statistics'), show: hasPermission('kams.members.statistics.view') },
          { to: '/modules/kams/members/recruitment', label: t('kams.recruitment'), show: hasPermission('kams.recruitment.applications.view') || hasPermission('kams.recruitment.reports.view') },
          { to: '/modules/kams/members/agents', end: true, label: t('kams.agents'), show: hasPermission('kams.members.agents.view') },
        ]}
      />
      <Outlet />
    </div>
  )
}

export function KamsAgents() {
  const { t } = useTranslation()
  const { user, hasPermission } = useAuth()
  const [rows, setRows] = useState<Member[]>([])
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [companies, setCompanies] = useState<LookupCompany[]>([])
  const [offices, setOffices] = useState<LookupOffice[]>([])
  const [people, setPeople] = useState<LookupPerson[]>([])
  const [roles, setRoles] = useState<LookupRole[]>([])
  const [memberOrigins, setMemberOrigins] = useState<string[]>([])
  const [importSources, setImportSources] = useState<string[]>([])
  const [origin, setOrigin] = useState('')
  const [promotionEligibility, setPromotionEligibility] = useState('')
  const { officeId, setOfficeId, showOfficeFilter } = useOfficeScope(offices)
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [formOpen, setFormOpen] = useState(false)
  const [importOpen, setImportOpen] = useState(false)
  const [editing, setEditing] = useState<Member | null>(null)
  const [viewing, setViewing] = useState<Member | null>(null)
  const [detail, setDetail] = useState<Record<string, unknown> | null>(null)
  const [deleting, setDeleting] = useState<Member | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [importForm, setImportForm] = useState({ company_id: '', source: 'excel', source_label: '', csv: '' })
  const [importFile, setImportFile] = useState<File | null>(null)
  const [importError, setImportError] = useState('')
  const [importing, setImporting] = useState(false)
  const [loading, setLoading] = useState(true)
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [photoRemoved, setPhotoRemoved] = useState(false)
  const [photoError, setPhotoError] = useState('')
  const [formError, setFormError] = useState('')
  const [passwordOpen, setPasswordOpen] = useState(false)
  const [passwordForm, setPasswordForm] = useState({ password: '', confirmation: '' })
  const [pendingPassword, setPendingPassword] = useState<string | null>(null)
  const [passwordError, setPasswordError] = useState('')
  const [saving, setSaving] = useState(false)
  const [filterOpen, setFilterOpen] = useState(false)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [printPreviewTitle, setPrintPreviewTitle] = useState('')
  const [coachedOpen, setCoachedOpen] = useState(false)
  const [credentials, setCredentials] = useState<Member[]>([])
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)
  const filtersActive = Boolean(origin || promotionEligibility)

  const load = (term = query) => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/members', {
      params: {
        per_page: 50,
        ...(origin ? { origin } : {}),
        ...(promotionEligibility ? { promotion_eligibility: promotionEligibility } : {}),
        ...(officeId ? { office_id: officeId } : {}),
        ...(term ? { search: term } : {}),
      },
    }).then((res) => setRows(res.data.data ?? [])).finally(stop)
  }

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!filterRef.current?.contains(event.target as Node)) setFilterOpen(false)
      if (!printMenuRef.current?.contains(event.target as Node)) setPrintMenuOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  useEffect(() => {
    setPage(1)
    void load()
  }, [origin, promotionEligibility, officeId, query])

  useLiveData(() => load(query))

  useEffect(() => {
    api.get('/modules/kams/recruitment/lookups').then((res) => {
      setCompanies(res.data.data.companies)
      setOffices(res.data.data.offices)
      setPeople(res.data.data.members ?? res.data.data.sponsors ?? [])
      setRoles(res.data.data.roles ?? [])
      setMemberOrigins(res.data.data.options?.member_origins ?? [])
      setImportSources(res.data.data.options?.import_sources ?? [])
      const sources = res.data.data.options?.import_sources as string[] | undefined
      if (sources?.length) {
        setImportForm((current) => ({
          ...current,
          source: sources.includes(current.source) ? current.source : sources[0],
        }))
      }
    })
  }, [])

  const resolveKamsCompanyId = (list = companies) => {
    if (list.length === 0) return ''
    const primary = user?.primary_company?.id
    if (primary && list.some((company) => company.id === primary)) return String(primary)
    const kams = list.find((company) => company.code === 'KAMS')
    if (kams) return String(kams.id)
    return String(list[0].id)
  }

  const resetForm = () => {
    setEditing(null)
    setForm({})
    setPhoto(null)
    setPhotoRemoved(false)
    setPhotoError('')
    setFormError('')
    setPendingPassword(null)
    setPasswordOpen(false)
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return null
    })
  }

  const pickPhoto = (file?: File) => {
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setPhotoError(t('kams.photoError'))
      return
    }
    setPhotoError('')
    setPhoto(file)
    setPhotoRemoved(false)
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return URL.createObjectURL(file)
    })
  }

  const clearPhoto = () => {
    setPhoto(null)
    setPhotoRemoved(true)
    setPhotoError('')
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return null
    })
  }

  const openCreate = () => {
    resetForm()
    const companyId = resolveKamsCompanyId()
    const defaultOffice = offices.length === 1 ? String(offices[0].id) : officeId
    setForm({
      company_id: companyId,
      ...(defaultOffice ? { office_id: defaultOffice } : {}),
    })
    setFormOpen(true)
  }

  const openEdit = (row: Member) => {
    resetForm()
    setEditing(row)
    const coachMemberId = row.coach_member_id
      ? String(row.coach_member_id)
      : (row.trainer_user_id
        ? String(people.find((person) => person.user_id === row.trainer_user_id)?.id || '')
        : '')
    setForm({
      company_id: row.company_id ? String(row.company_id) : resolveKamsCompanyId(),
      office_id: row.office_id ? String(row.office_id) : '',
      first_name: row.first_name || '',
      last_name: row.last_name || '',
      gender: row.gender || '',
      birth_date: row.birth_date ? row.birth_date.slice(0, 10) : '',
      phone: row.phone || '',
      email: row.email || '',
      address: row.address || '',
      coach_member_id: coachMemberId,
      manager_member_id: row.manager_member_id ? String(row.manager_member_id) : '',
      assistant_manager_member_id: row.assistant_manager_member_id ? String(row.assistant_manager_member_id) : '',
      fixed_salary: row.fixed_salary != null ? String(row.fixed_salary) : '',
      role_id: row.role_id ? String(row.role_id) : '',
      joined_on: row.joined_on ? row.joined_on.slice(0, 10) : '',
      is_certified: row.is_certified ? '1' : '0',
    })
    setPhotoPreview(row.photo_url || null)
    setFormOpen(true)
  }

  useEffect(() => {
    if (!formOpen || editing || form.company_id || companies.length === 0) return
    setForm((current) => (current.company_id ? current : { ...current, company_id: resolveKamsCompanyId() }))
  }, [formOpen, editing, form.company_id, companies, user?.primary_company?.id])

  const formRole = roles.find((role) => String(role.id) === form.role_id)
  const formCanBeCertified = roleIsCertifiable(formRole)

  useEffect(() => {
    if (!formCanBeCertified && form.is_certified === '1') {
      setForm((current) => ({ ...current, is_certified: '0' }))
    }
  }, [formCanBeCertified, form.is_certified])

  const openView = async (row: Member) => {
    const res = await api.get(`/modules/kams/members/${row.id}`)
    setViewing(row)
    setDetail(res.data.data)
    setCoachedOpen(false)
  }

  const memberDetail = detail as Member | null
  const memberRoleLabel = memberDetail
    ? (memberDetail.role
      ? roleLabel(t, memberDetail.role, memberDetail.gender)
      : memberRankLabel(t, memberDetail.rank, memberDetail.gender))
    : ''
  const memberEncadreur = memberDetail
    ? (memberDetail.coach?.full_name || memberDetail.coach_name || memberDetail.trainer?.name || memberDetail.trainer_name || '—')
    : '—'
  const coachedMembers = memberDetail?.coached_members || []
  const coachedCount = memberDetail?.coached_members_count ?? coachedMembers.length

  const previewServiceCard = () => {
    if (!memberDetail) return
    const absolutePhoto = memberDetail.photo_url
      ? (memberDetail.photo_url.startsWith('http') ? memberDetail.photo_url : `${window.location.origin}${memberDetail.photo_url}`)
      : null
    setPrintPreviewTitle(t('kams.serviceCardPreview'))
    setPrintPreviewHtml(buildServiceCardHtml({
      companyName: memberDetail.company?.name || '',
      fullName: personName(memberDetail),
      role: memberRoleLabel,
      office: memberDetail.office?.name || '—',
      photoUrl: absolutePhoto,
    }))
  }

  const submitAgent = async (event: FormEvent) => {
    event.preventDefault()
    const companyId = form.company_id || resolveKamsCompanyId()
    if (!form.first_name?.trim() || !form.last_name?.trim() || !form.gender || !form.phone?.trim() || !companyId) {
      setFormError(t('kams.requiredAgentFields'))
      return
    }
    const payload = new FormData()
    payload.append('company_id', companyId)
    if (form.office_id) payload.append('office_id', form.office_id)
    payload.append('first_name', form.first_name.trim())
    payload.append('last_name', form.last_name.trim())
    payload.append('gender', form.gender)
    payload.append('phone', form.phone.trim())
    if (form.email) payload.append('email', form.email)
    if (form.birth_date) payload.append('birth_date', form.birth_date)
    if (form.address) payload.append('address', form.address)
    // Encadreur is the parrain; backend syncs trainer_user_id for finance.
    payload.append('coach_member_id', form.coach_member_id || '')
    payload.append('manager_member_id', form.manager_member_id || '')
    payload.append('assistant_manager_member_id', form.assistant_manager_member_id || '')
    if (form.fixed_salary) payload.append('fixed_salary', form.fixed_salary)
    payload.append('role_id', form.role_id || '')
    if (form.joined_on) payload.append('joined_on', form.joined_on)
    payload.append('is_certified', formCanBeCertified && form.is_certified === '1' ? '1' : '0')
    if (photo) payload.append('photo', photo)
    if (editing && photoRemoved && !photo) payload.append('remove_photo', '1')

    setSaving(true)
    setFormError('')
    try {
      if (editing) {
        payload.append('_method', 'PUT')
        await api.post(`/modules/kams/members/${editing.id}`, payload)
        if (pendingPassword) {
          await api.post(`/modules/kams/members/${editing.id}/password`, {
            password: pendingPassword,
            password_confirmation: pendingPassword,
          })
        }
        setFormOpen(false)
        resetForm()
      } else {
        const res = await api.post('/modules/kams/members', payload)
        const payloadData = res.data.data as {
          member?: Member
          credentials?: MemberAccount
        } | undefined
        const member = payloadData?.member
        setFormOpen(false)
        resetForm()
        if (member) {
          setCredentials([{
            ...member,
            account: {
              ...(member.account || {}),
              ...(payloadData?.credentials || {}),
            },
          }])
        }
      }
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
      const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
      setFormError(firstError || response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const resetImport = () => {
    setImportForm({ company_id: '', source: 'excel', source_label: '', csv: '' })
    setImportFile(null)
    setImportError('')
  }

  const submitImport = async (event: FormEvent) => {
    event.preventDefault()
    if (!importForm.company_id) {
      setImportError(t('kams.importNeedCompany'))
      return
    }
    const pasted = importForm.csv.trim() ? parseTable(importForm.csv) : []
    if (!importFile && pasted.length === 0) {
      setImportError(t('kams.importNeedRows'))
      return
    }

    setImporting(true)
    setImportError('')
    try {
      const res = await withBusy('import', async () => {
        if (importFile) {
          const payload = new FormData()
          payload.append('company_id', importForm.company_id)
          payload.append('source', importForm.source || 'excel')
          if (importForm.source_label) payload.append('source_label', importForm.source_label)
          payload.append('file', importFile)
          return api.post('/modules/kams/member-imports', payload)
        }
        return api.post('/modules/kams/member-imports', {
          company_id: Number(importForm.company_id),
          source: importForm.source,
          source_label: importForm.source_label || undefined,
          rows: pasted,
        })
      })
      const imported = membersFromImport(res.data.data)
      setCredentials(imported)
      setImportOpen(false)
      resetImport()
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
      const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
      setImportError(firstError || response?.message || t('kams.saveError'))
    } finally {
      setImporting(false)
    }
  }

  const toggleAccount = async (row: Member) => {
    const res = await api.post(`/modules/kams/members/${row.id}/account`, { is_active: !row.account?.is_active })
    setRows((current) => current.map((item) => item.id === row.id ? { ...item, ...(res.data.data as Member) } : item))
  }

  const removeAgent = async () => {
    if (!deleting) return
    await api.delete(`/modules/kams/members/${deleting.id}`)
    setDeleting(null)
    void load()
  }

  const memberParams = () => ({
    per_page: 1000,
    ...(origin ? { origin } : {}),
    ...(officeId ? { office_id: officeId } : {}),
    ...(query ? { search: query } : {}),
  })

  const agentColumns = (row: Member) => [
    row.member_code,
    personName(row),
    row.phone || '',
    row.email || '',
    row.office?.name || '',
    row.role ? roleLabel(t, row.role, row.gender) : memberRankLabel(t, row.rank, row.gender),
    memberCoachName(row),
    t(`kams.status.${row.status}`, { defaultValue: row.status }),
  ]

  const agentHeaders = () => [
    t('kams.memberId'),
    `${t('users.firstName')} / ${t('users.lastName')}`,
    t('kams.phoneWhatsapp'),
    t('kams.email'),
    t('kams.office'),
    t('users.role'),
    t('kams.trainerCoach'),
    t('common.status'),
  ]

  const exportAgents = async () => {
    await withBusy('data', async () => {
      const res = await api.get('/modules/kams/members', { params: memberParams() })
      const list = (res.data.data as Member[]) || []
      downloadCsv(`agents-import-${new Date().toISOString().slice(0, 10)}.csv`, [
        [...AGENT_IMPORT_HEADERS],
        ...list.map(agentImportRow),
      ])
    })
  }

  const agentPrintOptions = (list: Member[]) => ({
    title: t('kams.agents'),
    module: t('kams.title'),
    subtitle: t('kams.membersHint'),
    meta: [
      { label: t('common.filter'), value: origin ? t(`kams.origin.${origin}`) : t('common.all') },
      { label: t('kams.filterOffice'), value: offices.find((office) => String(office.id) === officeId)?.name || t('kams.allOffices') },
      { label: t('common.records'), value: String(list.length) },
      { label: t('common.printedOn'), value: new Date().toLocaleString() },
    ],
    headers: agentHeaders(),
    rows: list.map(agentColumns),
    emptyLabel: t('common.empty'),
    footerNote: t('common.confidential'),
    orientation: 'landscape' as const,
  })

  const loadAgentsForPrint = async () => {
    return withBusy('data', async () => {
      const res = await api.get('/modules/kams/members', { params: memberParams() })
      return (res.data.data as Member[]) || []
    })
  }

  const printAgents = async () => {
    const list = await loadAgentsForPrint()
    printHtml(buildBrandedTableHtml(agentPrintOptions(list)))
  }

  const previewAgents = async () => {
    setPrintPreviewTitle(t('common.printPreview'))
    setPrintPreviewHtml(buildBrandedTableHtml(agentPrintOptions(rows)))
    const list = await loadAgentsForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(agentPrintOptions(list)))
  }

  const officeOptions = offices
    .filter((office) => String(office.company_id) === form.company_id)
    .map((office): SearchComboboxItem => ({
      id: String(office.id),
      label: office.name,
      hint: office.code,
    }))
  const selectedOffice = officeOptions.find((item) => item.id === form.office_id) ?? null
  const companySelected = Boolean(form.company_id)
  const companyPeople = people.filter((person) => (
    (!form.company_id || String(person.company_id) === form.company_id)
    && (!form.office_id || !person.office_id || String(person.office_id) === form.office_id)
  ))
  const coachOptions = companyPeople
    .filter((person) => (
      Boolean(person.user_id)
      && (!editing || String(person.id) !== String(editing.id))
    ))
    .map((person): SearchComboboxItem => ({
      id: String(person.id),
      label: person.full_name,
      hint: [person.member_code, person.phone].filter(Boolean).join(' · '),
    }))
  const selectedCoach = coachOptions.find((item) => item.id === form.coach_member_id) ?? null
  const agentLastPage = Math.max(1, Math.ceil(rows.length / perPage) || 1)
  const agentPage = Math.min(page, agentLastPage)
  const agentRangeStart = rows.length === 0 ? 0 : (agentPage - 1) * perPage + 1
  const agentRangeEnd = Math.min(agentPage * perPage, rows.length)

  return (
    <div>
      <div className="mb-4 flex flex-col-reverse gap-3 md:flex-row md:items-end">
        <div className="flex min-w-0 flex-1 items-end gap-2 md:contents">
        <div className="relative order-2 flex min-h-11 w-full min-w-0 flex-1 items-center gap-2 rounded-xl border border-line bg-white px-3 md:min-w-[8rem]">
          <Search size={16} className="shrink-0 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('kams.searchAgents')}
            className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
          <div ref={filterRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
            <button
              type="button"
              aria-label={t('common.filter')}
              aria-expanded={filterOpen}
              className={cn(
                'grid h-9 w-9 place-items-center rounded-lg text-muted hover:bg-surface hover:text-ink',
                filtersActive && 'bg-lhms-soft text-lhms',
              )}
              onClick={() => setFilterOpen((open) => !open)}
            >
              <ListFilter size={16} />
            </button>
            {filterOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-64 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                <div className="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted">{t('kams.originFilter')}</div>
                {[
                  { value: '', label: t('common.all') },
                  ...memberOrigins.map((value) => ({ value, label: t(`kams.origin.${value}`) })),
                ].map((option) => (
                  <button
                    key={`origin-${option.value || 'all'}`}
                    type="button"
                    className={cn(
                      'flex w-full px-3 py-2.5 text-left text-sm hover:bg-surface',
                      origin === option.value && 'bg-lhms-soft font-semibold text-lhms',
                    )}
                    onClick={() => {
                      setOrigin(option.value)
                      setFilterOpen(false)
                    }}
                  >
                    {option.label}
                  </button>
                ))}
                <div className="my-1 border-t border-line" />
                <div className="px-3 py-2 text-xs font-semibold uppercase tracking-wide text-muted">{t('kams.promotionEligibility')}</div>
                {[
                  { value: '', label: t('common.all') },
                  { value: 'eligible', label: t('kams.filterEligibleAny') },
                  { value: 'chef', label: t('kams.filterEligibleChef') },
                  { value: 'assistant_manager', label: t('kams.filterEligibleAssistantManager') },
                  { value: 'not_eligible', label: t('kams.filterNotEligible') },
                ].map((option) => (
                  <button
                    key={`promo-${option.value || 'all'}`}
                    type="button"
                    className={cn(
                      'flex w-full px-3 py-2.5 text-left text-sm hover:bg-surface',
                      promotionEligibility === option.value && 'bg-lhms-soft font-semibold text-lhms',
                    )}
                    onClick={() => {
                      setPromotionEligibility(option.value)
                      setFilterOpen(false)
                    }}
                  >
                    {option.label}
                  </button>
                ))}
              </div>
            ) : null}
          </div>
        </div>
        {showOfficeFilter ? (
          <div className="order-1 shrink-0">
            <OfficeFilterSelect
              value={officeId}
              offices={offices}
              onChange={(e) => setOfficeId(e.target.value)}
            />
          </div>
        ) : null}
        </div>
        <div className="flex w-full shrink-0 items-center gap-2 md:w-auto md:order-3">
          {hasPermission('kams.members.create') ? (
            <Button className="shrink-0 px-3 sm:px-4" onClick={openCreate}>
              <Plus size={16} /> {t('kams.addAgent')}
            </Button>
          ) : null}
          <div className="ml-auto flex shrink-0 items-center gap-2">
          {hasPermission('kams.members.import') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0" title={t('kams.importAgents')} aria-label={t('kams.importAgents')} onClick={() => setImportOpen(true)}>
              <Upload size={16} />
            </Button>
          ) : null}
          {hasPermission('kams.members.export') ? (
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0" title={t('common.export')} aria-label={t('common.export')} disabled={rows.length === 0} onClick={() => void exportAgents()}>
              <Download size={16} />
            </Button>
          ) : null}
          {hasPermission('kams.members.print') || hasPermission('kams.members.preview') ? (
          <div
            ref={printMenuRef}
            className="relative shrink-0"
            onMouseDown={(event) => event.stopPropagation()}
          >
            <Button
              variant="secondary"
              className="h-11 w-11 px-0"
              title={t('common.print')}
              aria-label={t('common.print')}
              aria-expanded={printMenuOpen}
              disabled={rows.length === 0}
              onClick={() => setPrintMenuOpen((open) => !open)}
            >
              <Printer size={16} />
            </Button>
            {printMenuOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                {hasPermission('kams.members.print') ? (
                <button
                  type="button"
                  className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                  onClick={(event) => {
                    event.stopPropagation()
                    setPrintMenuOpen(false)
                    void printAgents()
                  }}
                >
                  <Printer size={16} className="text-lhms" /> {t('common.print')}
                </button>
                ) : null}
                {hasPermission('kams.members.preview') ? (
                <button
                  type="button"
                  className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                  onClick={(event) => {
                    event.stopPropagation()
                    setPrintMenuOpen(false)
                    void previewAgents()
                  }}
                >
                  <Eye size={16} className="text-lhms" /> {t('common.preview')}
                </button>
                ) : null}
              </div>
            ) : null}
          </div>
          ) : null}
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(4,55,17,0.04)]">
        {loading && rows.length === 0 ? <LoadingScreen /> : (
          <>
        <div className="overflow-x-auto">
          <table className="min-w-full table-fixed text-sm">
            <thead className="bg-lhms text-white">
              <tr className="text-left text-xs font-semibold tracking-wide">
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.agents')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.memberId')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.phoneWhatsapp')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.office')}</th>
                <th className="w-28 bg-lhms px-4 py-1.5 font-semibold">{t('users.role')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.status')}</th>
                <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.length === 0 ? (
                <tr>
                  <td colSpan={7} className="px-4 py-8 text-center text-muted">{t('common.empty')}</td>
                </tr>
              ) : rows.slice((agentPage - 1) * perPage, agentPage * perPage).map((row, index) => (
          <tr
            key={row.id}
            className={cn(
              'border-t border-line',
              row.promotion_eligible
                ? 'bg-orange-100'
                : index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white',
            )}
            title={row.promotion_eligible
              ? t(row.promotion_for === 'assistant_manager' ? 'kams.eligibleForAssistantManager' : 'kams.eligibleForChef')
              : undefined}
          >
            <td className="px-4 py-1.5">
              <div className="flex items-center gap-3">
                <PersonPhoto name={personName(row)} url={row.photo_url} className="h-7 w-7 text-xs" />
                <div className="flex min-w-0 items-center gap-1.5 font-semibold">
                  <span className="truncate">{personName(row)}</span>
                  {row.is_certified ? (
                    <BadgeCheck size={16} className="shrink-0 text-[#1D9BF0]" fill="currentColor" stroke="white" aria-label={t('users.certified')} />
                  ) : null}
                </div>
              </div>
            </td>
            <td className="px-4 py-1.5 whitespace-nowrap text-muted">{row.member_code}</td>
            <td className="px-4 py-1.5 whitespace-nowrap">{row.phone || '—'}</td>
            <td className="px-4 py-1.5 whitespace-nowrap">{row.office?.name || '—'}</td>
            <td className="w-28 max-w-28 truncate px-4 py-1.5" title={row.role ? roleLabel(t, row.role, row.gender) : memberRankLabel(t, row.rank, row.gender)}>{row.role ? roleLabel(t, row.role, row.gender) : memberRankLabel(t, row.rank, row.gender)}</td>
            <td className="px-4 py-1.5 whitespace-nowrap">
              <Badge tone={toneFor(row.status)}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
            </td>
            <td className="px-4 py-1.5">
            <div className="flex shrink-0 items-center gap-1">
              {hasPermission('kams.members.activate') ? (
              <button
                type="button"
                role="switch"
                aria-checked={Boolean(row.account?.is_active)}
                aria-label={t('kams.accountAccess')}
                title={row.account?.is_active ? t('kams.accountOn') : t('kams.accountOff')}
                className="grid h-7 w-7 place-items-center rounded-lg hover:bg-white"
                onClick={() => void toggleAccount(row)}
              >
                <span className={cn('relative block h-4 w-7 rounded-full transition', row.account?.is_active ? 'bg-lhms' : 'bg-line')}>
                  <span className={cn('absolute top-0.5 left-0.5 h-3 w-3 rounded-full bg-white shadow-sm transition', row.account?.is_active && 'translate-x-3')} />
                </span>
              </button>
              ) : null}
              <button type="button" aria-label={t('common.view')} className="grid h-7 w-7 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => void openView(row)}>
                <Eye size={16} />
              </button>
              {hasPermission('kams.members.update') ? (
              <button type="button" aria-label={t('common.edit')} className="grid h-7 w-7 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => openEdit(row)}>
                <Pencil size={16} />
              </button>
              ) : null}
              {row.status === 'pending_verification' && hasPermission('kams.members.update') ? (
                <button type="button" aria-label={t('kams.verify')} className="grid h-7 w-7 place-items-center rounded-lg text-lhms hover:bg-white" onClick={() => void api.put(`/modules/kams/members/${row.id}`, { status: 'verified', reason: 'Verified from agents list' }).then(() => load())}>
                  <CheckCircle2 size={16} />
                </button>
              ) : null}
              {row.status === 'verified' && hasPermission('kams.members.activate') ? (
                <button type="button" aria-label={t('kams.activate')} className="grid h-7 w-7 place-items-center rounded-lg text-lhms hover:bg-white" onClick={() => void api.put(`/modules/kams/members/${row.id}`, { status: 'active', reason: 'Activated from agents list' }).then(() => load())}>
                  <Check size={16} />
                </button>
              ) : null}
              {hasPermission('kams.members.delete') ? (
              <button type="button" aria-label={t('common.delete')} className="grid h-7 w-7 place-items-center rounded-lg text-danger hover:bg-white" onClick={() => setDeleting(row)}>
                <Trash2 size={16} />
              </button>
              ) : null}
            </div>
            </td>
          </tr>
        ))}
            </tbody>
          </table>
        </div>
        <div className="flex flex-col gap-3 border-t border-line px-4 py-1.5 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">{t('kams.pageRange', { from: agentRangeStart, to: agentRangeEnd, total: rows.length })}</p>
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
              page={agentPage}
              lastPage={agentLastPage}
              onPrev={() => setPage((value) => Math.max(1, value - 1))}
              onNext={() => setPage((value) => Math.min(agentLastPage, value + 1))}
            />
          </div>
        </div>
          </>
        )}
      </div>

      <Modal open={formOpen} title={editing ? t('kams.editAgent') : t('kams.addAgent')} onClose={() => { setFormOpen(false); resetForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submitAgent(e)}>
          <div className="flex flex-col items-center gap-2 pb-1">
            <PhotoSourcePicker onFile={pickPhoto}>
              {(openPhotoMenu) => (
                <div className="relative h-24 w-24">
                  <button
                    type="button"
                    onClick={openPhotoMenu}
                    aria-label={t('kams.photo')}
                    className="block h-24 w-24 overflow-hidden rounded-full p-0"
                  >
                    {photoPreview || form.first_name || form.last_name ? (
                      <PersonPhoto name={personName({ first_name: form.first_name, last_name: form.last_name })} url={photoPreview} className="h-24 w-24 text-2xl" />
                    ) : (
                      <span className="grid h-24 w-24 place-items-center rounded-full bg-lhms text-white">
                        <Camera size={28} />
                      </span>
                    )}
                  </button>
                  {photoPreview ? (
                    <button
                      type="button"
                      aria-label={t('kams.removePhoto')}
                      className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
                      onClick={clearPhoto}
                    >
                      <Trash2 size={14} />
                    </button>
                  ) : null}
                  <button
                    type="button"
                    aria-label={t('kams.photo')}
                    className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
                    onClick={openPhotoMenu}
                  >
                    <Camera size={14} />
                  </button>
                </div>
              )}
            </PhotoSourcePicker>
            <p className="text-center text-xs text-muted">{t('kams.photoHint')}</p>
            {photoError ? <p className="text-center text-xs text-danger">{photoError}</p> : null}
          </div>
          {showOfficeFilter ? (
            <SearchCombobox
              label={t('kams.office')}
              value={selectedOffice}
              items={officeOptions}
              disabled={!companySelected}
              placeholder={companySelected ? t('kams.searchOffice') : t('kams.selectCompanyFirst')}
              emptyLabel={t('common.empty')}
              onSelect={(item) => setForm({ ...form, office_id: item?.id ?? '' })}
            />
          ) : null}
          <Select label={t('users.role')} value={form.role_id || ''} onChange={(e) => setForm({ ...form, role_id: e.target.value })}>
            <option value="">—</option>
            {roles.map((role) => (
              <option key={role.id} value={role.id}>{roleLabel(t, role, form.gender)}</option>
            ))}
          </Select>
          {formCanBeCertified ? (
            <div className="space-y-1.5">
              <Select
                label={t('users.certified')}
                value={form.is_certified === '1' ? '1' : '0'}
                onChange={(e) => setForm({ ...form, is_certified: e.target.value })}
              >
                <option value="1">{t('users.certified')}</option>
                <option value="0">{t('users.notCertified')}</option>
              </Select>
              <p className="text-xs text-muted">{t('users.certifiedHint')}</p>
            </div>
          ) : null}
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('users.firstName')} value={form.first_name || ''} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
            <Field label={t('users.lastName')} value={form.last_name || ''} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label={t('kams.gender')} required value={form.gender || ''} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
              <option value="">—</option>
              <option value="male">{t('kams.genderMale')}</option>
              <option value="female">{t('kams.genderFemale')}</option>
            </Select>
            <Field label={t('kams.birthDate')} type="date" value={form.birth_date || ''} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />
          </div>
          <Field label={t('kams.phoneWhatsapp')} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} required />
          <Field label={t('kams.email')} type="email" value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Field label={t('kams.address')} value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          <SearchCombobox
            label={t('kams.trainerCoach')}
            value={selectedCoach}
            items={coachOptions}
            placeholder={t('kams.pickSupervisor')}
            emptyLabel={coachOptions.length === 0 ? t('kams.noEligibleSupervisor') : t('common.empty')}
            onSelect={(item) => setForm({ ...form, coach_member_id: item?.id ?? '' })}
          />
          <div className="flex items-end gap-3">
            <div className="min-w-0 flex-1">
              <Field label={t('kams.joinedOn')} type="date" value={form.joined_on || ''} onChange={(e) => setForm({ ...form, joined_on: e.target.value })} />
            </div>
            {editing ? (
              <Button type="button" variant="secondary" className="shrink-0" onClick={() => { setPasswordForm({ password: pendingPassword || '', confirmation: pendingPassword || '' }); setPasswordError(''); setPasswordOpen(true) }}>
                {t('auth.changePassword')}
              </Button>
            ) : null}
          </div>
          {formError ? <p className="text-sm text-danger">{formError}</p> : null}
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={passwordOpen} title={t('auth.changePassword')} onClose={() => setPasswordOpen(false)}>
        <form
          className="space-y-3"
          onSubmit={(event) => {
            event.preventDefault()
            if (passwordForm.password !== passwordForm.confirmation) {
              setPasswordError(t('auth.passwordMismatch'))
              return
            }
            setPendingPassword(passwordForm.password)
            setPasswordError('')
            setPasswordOpen(false)
          }}
        >
          <Field label={t('auth.newPassword')} type="password" required value={passwordForm.password} onChange={(e) => setPasswordForm({ ...passwordForm, password: e.target.value })} autoComplete="new-password" />
          <Field label={t('auth.confirmPassword')} type="password" required value={passwordForm.confirmation} onChange={(e) => setPasswordForm({ ...passwordForm, confirmation: e.target.value })} autoComplete="new-password" />
          {passwordError ? <p className="text-sm text-danger">{passwordError}</p> : null}
          <Button type="submit" className="w-full">{t('common.validate')}</Button>
        </form>
      </Modal>

      <Modal open={importOpen} title={t('kams.importMembers')} onClose={() => { setImportOpen(false); resetImport() }}>
        <form className="space-y-3" onSubmit={(e) => void submitImport(e)}>
          <Select label={t('users.company')} required value={importForm.company_id} onChange={(e) => setImportForm({ ...importForm, company_id: e.target.value })}>
            <option value="">—</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </Select>
          <Select label={t('kams.dataSource')} value={importForm.source} onChange={(e) => setImportForm({ ...importForm, source: e.target.value })}>
            {importSources.map((source) => (
              <option key={source} value={source}>{t(`kams.importSource.${source}`)}</option>
            ))}
          </Select>
          <Field label={t('kams.sourceLabel')} value={importForm.source_label} onChange={(e) => setImportForm({ ...importForm, source_label: e.target.value })} />
          <label className="block space-y-1.5">
            <span className="text-sm font-medium">{t('kams.importFile')}</span>
            <input
              type="file"
              accept=".xlsx,.xlsm,.csv,.tsv,.txt,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv"
              className="block w-full rounded-xl border border-line bg-white px-3 py-2.5 text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-lhms-soft file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-lhms"
              onChange={(event) => {
                const file = event.target.files?.[0] || null
                setImportFile(file)
                setImportError('')
                if (file && /\.(xlsx|xlsm)$/i.test(file.name)) {
                  setImportForm((current) => ({ ...current, source: 'excel' }))
                }
              }}
            />
            {importFile ? <p className="text-xs text-muted">{importFile.name}</p> : <p className="text-xs text-muted">{t('kams.importFileHint')}</p>}
          </label>
          <label className="block space-y-1.5">
            <span className="text-sm font-medium">{t('kams.pasteRows')}</span>
            <textarea
              className="min-h-32 w-full rounded-xl border border-line px-3 py-2 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
              value={importForm.csv}
              onChange={(e) => setImportForm({ ...importForm, csv: e.target.value })}
              placeholder={AGENT_IMPORT_HEADERS.join(',')}
            />
            <p className="text-xs text-muted">{t('kams.pasteRowsHint')}</p>
          </label>
          {importError ? <p className="text-sm text-danger">{importError}</p> : null}
          <Button type="submit" className="w-full" disabled={importing}>{importing ? t('common.importing') : t('kams.import')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(viewing)} title={t('kams.member')} onClose={() => { setViewing(null); setDetail(null); setCoachedOpen(false) }}>
        {memberDetail ? (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              <PersonPhoto name={personName(memberDetail)} url={memberDetail.photo_url} className="h-20 w-20 text-xl" />
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
              <span>{t('users.firstName')} / {t('users.lastName')}: <strong>{personName(memberDetail)}</strong></span>
              {memberDetail.is_certified ? (
                <BadgeCheck size={16} className="shrink-0 text-[#1D9BF0]" fill="currentColor" stroke="white" aria-label={t('users.certified')} />
              ) : null}
            </div>
            <div>{t('kams.memberId')}: <strong>{memberDetail.member_code}</strong></div>
            <div>{t('kams.email')}: <strong>{memberDetail.email || '—'}</strong></div>
            <div>{t('kams.phoneWhatsapp')}: <strong>{memberDetail.phone || '—'}</strong></div>
            <div>{t('kams.joinedOn')}: {memberDetail.joined_on || '—'}</div>
            {memberDetail.migrated_at ? <div>{t('kams.migratedOn')}: {String(memberDetail.migrated_at)}</div> : null}
            <div>{t('users.role')}: <strong>{memberRoleLabel}</strong></div>
            {memberDetail.can_be_certified ? (
              <div>{t('users.certified')}: <strong>{memberDetail.is_certified ? t('users.certified') : t('users.notCertified')}</strong></div>
            ) : null}
            <div>{t('kams.trainerCoach')}: <strong>{memberEncadreur}</strong></div>
            <div className="flex flex-wrap items-center gap-2">
              <span>{t('kams.coachedMembers')}: <strong>{t('kams.coachedMembersCount', { count: coachedCount })}</strong></span>
              <Button
                type="button"
                variant="secondary"
                className="!min-h-9 h-9 px-3"
                disabled={coachedCount === 0}
                onClick={() => setCoachedOpen(true)}
              >
                {t('kams.viewCoachedMembers')}
              </Button>
            </div>
            {(hasPermission('kams.members.print') || hasPermission('kams.members.preview')) ? (
            <Button type="button" className="w-full" onClick={previewServiceCard}>
              <Printer size={16} /> {t('kams.serviceCard')}
            </Button>
            ) : null}
          </div>
        ) : null}
      </Modal>
      <Modal open={coachedOpen} title={t('kams.coachedMembersList')} onClose={() => setCoachedOpen(false)}>
        <div className="max-h-[60vh] space-y-2 overflow-auto">
          {coachedMembers.map((item) => (
            <div key={item.id} className="flex items-center gap-3 rounded-xl border border-line px-3 py-2">
              <PersonPhoto name={item.full_name} url={item.photo_url} className="h-10 w-10 text-xs" />
              <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-semibold">{item.full_name}</div>
                <div className="truncate text-xs text-muted">
                  {[item.member_code, item.office?.name, item.role ? roleLabel(t, item.role, item.gender) : memberRankLabel(t, item.rank, item.gender)].filter(Boolean).join(' · ')}
                </div>
              </div>
            </div>
          ))}
          {coachedMembers.length === 0 ? <p className="text-sm text-muted">{t('common.empty')}</p> : null}
        </div>
        <Button className="mt-4 w-full" variant="secondary" onClick={() => setCoachedOpen(false)}>{t('common.close')}</Button>
      </Modal>

      <Modal open={Boolean(deleting)} title={t('kams.deleteAgent')} onClose={() => setDeleting(null)}>
        <p className="text-sm text-muted">{t('kams.deleteAgentConfirm', { name: personName(deleting) })}</p>
        <div className="mt-4 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" onClick={() => void removeAgent()}>{t('common.delete')}</Button>
        </div>
      </Modal>
      <Modal open={credentials.length > 0} title={t('kams.loginCredentials')} onClose={() => setCredentials([])}>
        <p className="mb-3 text-sm text-muted">{t('kams.credentialsHint')}</p>
        <div className="max-h-[60vh] space-y-3 overflow-auto">
          {credentials.map((item) => (
            <AgentCredentialsCard
              key={item.id}
              name={personName(item)}
              email={item.account?.email}
              phone={item.account?.phone || item.phone}
              password={item.account?.password}
              isActive={item.account?.is_active}
            />
          ))}
        </div>
        <Button className="mt-4 w-full" onClick={() => setCredentials([])}>{t('common.close')}</Button>
      </Modal>
      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={printPreviewTitle || t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => { setPrintPreviewHtml(''); setPrintPreviewTitle('') }}
      />
    </div>
  )
}

export { KamsRecruitmentDashboard } from './KamsRecapPage'

export function KamsCandidates() {
  const { t } = useTranslation()
  const { user, hasPermission } = useAuth()
  const { period, from, to, officeId } = useOutletContext<RecruitmentListFilters>()
  const canCreate = hasPermission('kams.recruitment.create')
  const canUpdate = hasPermission('kams.recruitment.update')
  const canDelete = hasPermission('kams.recruitment.delete')
  const canInterview = hasPermission('kams.recruitment.interview.update')
  const canObservation = hasPermission('kams.recruitment.observation.update')
  const [rows, setRows] = useState<Candidate[]>([])
  const [companies, setCompanies] = useState<LookupCompany[]>([])
  const [offices, setOffices] = useState<LookupOffice[]>([])
  const [people, setPeople] = useState<LookupPerson[]>([])
  const [roles, setRoles] = useState<LookupRole[]>([])
  const [candidateSources, setCandidateSources] = useState<string[]>([])
  const [interviewResults, setInterviewResults] = useState<string[]>([])
  const [observationResults, setObservationResults] = useState<string[]>([])
  const [recruitmentSettings, setRecruitmentSettings] = useState<{
    interview_hours?: string
    observation_hours?: string
    default_member_rank?: string
  }>({})
  const [open, setOpen] = useState(false)
  const [editingId, setEditingId] = useState<number | null>(null)
  const [deleting, setDeleting] = useState<Candidate | null>(null)
  const [detail, setDetail] = useState<Record<string, unknown> | null>(null)
  const [form, setForm] = useState<Record<string, string>>({})
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [photoRemoved, setPhotoRemoved] = useState(false)
  const [photoError, setPhotoError] = useState('')
  const [saving, setSaving] = useState(false)
  const [formError, setFormError] = useState('')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [action, setAction] = useState<'interview' | 'observation' | null>(null)
  const [pickingSupervisor, setPickingSupervisor] = useState(false)
  const [credentials, setCredentials] = useState<Member[]>([])
  const [loading, setLoading] = useState(true)

  const load = (term = query) => {
    const stop = trackLoadSpinner(setLoading)
    const range = period === 'custom' ? { from, to } : presetRange(period)
    return api.get('/modules/kams/candidates', {
      params: {
        page,
        per_page: perPage,
        ...(term ? { search: term } : {}),
        ...(officeId ? { office_id: officeId } : {}),
        ...range,
      },
    }).then((res) => {
      setRows(res.data.data ?? [])
      setLastPage(Math.max(1, Number(res.data.meta?.last_page || 1)))
      setTotal(Number(res.data.meta?.total || 0))
    }).finally(stop)
  }

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    setPage(1)
  }, [query, period, from, to, officeId, perPage])

  useEffect(() => {
    void load()
  }, [query, period, from, to, officeId, page, perPage])

  useLiveData(() => load(query))

  useEffect(() => {
    api.get('/modules/kams/recruitment/lookups').then((res) => {
      setCompanies(res.data.data.companies)
      setOffices(res.data.data.offices)
      setPeople(res.data.data.members ?? res.data.data.sponsors ?? [])
      setRoles(res.data.data.roles ?? [])
      setCandidateSources(res.data.data.options?.candidate_sources ?? [])
      setInterviewResults(res.data.data.options?.interview_results ?? [])
      setObservationResults(res.data.data.options?.observation_results ?? [])
      setRecruitmentSettings(res.data.data.settings ?? {})
    })
  }, [])

  const resolveKamsCompanyId = (list = companies) => {
    if (list.length === 0) return ''
    const primary = user?.primary_company?.id
    if (primary && list.some((company) => company.id === primary)) return String(primary)
    const kams = list.find((company) => company.code === 'KAMS')
    if (kams) return String(kams.id)
    return String(list[0].id)
  }

  const distributorRoleIds = new Set(
    roles.filter((role) => role.slug === 'distributor').map((role) => role.id),
  )
  const isDistributorRole = (person: LookupPerson) => (
    person.role_id != null && distributorRoleIds.has(person.role_id)
  )

  const resetCandidateForm = () => {
    setEditingId(null)
    setForm({})
    setPhoto(null)
    setPhotoRemoved(false)
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return null
    })
    setPhotoError('')
    setFormError('')
  }

  const openCreate = () => {
    resetCandidateForm()
    const defaultOffice = offices.length === 1 ? String(offices[0].id) : officeId
    setForm({
      company_id: resolveKamsCompanyId(),
      ...(defaultOffice ? { office_id: defaultOffice } : {}),
    })
    setOpen(true)
  }

  const openEdit = (row: Candidate) => {
    resetCandidateForm()
    setEditingId(row.id)
    setForm({
      company_id: row.company_id ? String(row.company_id) : resolveKamsCompanyId(),
      office_id: row.office_id ? String(row.office_id) : '',
      first_name: row.first_name || '',
      last_name: row.last_name || '',
      gender: row.gender || '',
      birth_date: row.birth_date ? row.birth_date.slice(0, 10) : '',
      phone: row.phone || '',
      email: row.email || '',
      address: row.address || '',
      source: row.source || '',
    })
    setPhotoPreview(row.photo_url || null)
    setOpen(true)
  }

  useEffect(() => {
    if (!open || editingId || form.company_id || companies.length === 0) return
    setForm((current) => (current.company_id ? current : { ...current, company_id: resolveKamsCompanyId() }))
  }, [open, editingId, form.company_id, companies, user?.primary_company?.id])

  const pickPhoto = (file?: File) => {
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setPhotoError(t('kams.photoError'))
      return
    }
    setPhotoError('')
    setPhoto(file)
    setPhotoRemoved(false)
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return URL.createObjectURL(file)
    })
  }

  const clearPhoto = () => {
    setPhoto(null)
    setPhotoRemoved(true)
    setPhotoError('')
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return null
    })
  }

  const submit = async (event: FormEvent) => {
    event.preventDefault()
    const companyId = form.company_id || resolveKamsCompanyId()
    if (
      !form.first_name?.trim()
      || !form.last_name?.trim()
      || !form.gender
      || !form.phone?.trim()
      || !form.source
      || !companyId
      || !form.office_id
      || !form.birth_date
      || !form.email?.trim()
    ) {
      setFormError(t('kams.requiredFields'))
      return
    }

    const payload = new FormData()
    payload.append('company_id', companyId)
    payload.append('office_id', form.office_id)
    payload.append('first_name', form.first_name.trim())
    payload.append('last_name', form.last_name.trim())
    payload.append('gender', form.gender)
    payload.append('phone', form.phone.trim())
    payload.append('source', form.source)
    payload.append('birth_date', form.birth_date)
    payload.append('email', form.email.trim())
    if (form.address) payload.append('address', form.address)
    if (photo) payload.append('photo', photo)
    if (editingId && photoRemoved && !photo) payload.append('remove_photo', '1')

    setSaving(true)
    setFormError('')
    try {
      if (editingId) {
        payload.append('_method', 'PUT')
        await api.post(`/modules/kams/candidates/${editingId}`, payload)
      } else {
        await api.post('/modules/kams/candidates', payload)
      }
      setOpen(false)
      resetCandidateForm()
      void load()
    } catch (error) {
      const response = (error as { response?: { data?: { message?: string; errors?: Record<string, string[]> } } }).response?.data
      const firstError = response?.errors ? Object.values(response.errors).flat()[0] : undefined
      setFormError(firstError || response?.message || t('kams.saveError'))
    } finally {
      setSaving(false)
    }
  }

  const saveStage = async (event: FormEvent) => {
    event.preventDefault()
    if (!detail) return
    if (action === 'interview' && !canInterview) return
    if (action === 'observation' && !canObservation) return
    const id = Number(detail.id)
    try {
      if (action === 'interview') {
        if (form.result === 'abandoned' && !form.abandonment_reason?.trim()) {
          void showAlert(t('kams.abandonmentReasonRequired'), { tone: 'warning' })
          return
        }
        if (form.result === 'rejected' && !form.rejection_reason?.trim()) {
          void showAlert(t('kams.rejectionReasonRequired'), { tone: 'warning' })
          return
        }
        if (!form.interviewer_user_id) {
          void showAlert(t('kams.searchUser'), { tone: 'warning' })
          return
        }
        if (!form.result) return
        await api.post('/modules/kams/interviews', {
          candidate_id: id,
          office_id: form.office_id ? Number(form.office_id) : null,
          interview_starts_at: form.interview_starts_at,
          interviewer_user_id: Number(form.interviewer_user_id),
          attended: form.attended === '1',
          notes: form.notes,
          abandonment_reason: form.abandonment_reason || null,
          rejection_reason: form.rejection_reason || null,
          result: form.result,
        })
      }
      if (action === 'observation') {
        if (!form.supervisor_member_id) {
          void showAlert(t('kams.pickSupervisor'), { tone: 'warning' })
          return
        }
        if (form.decision === 'rejected' && !form.rejection_reason?.trim()) {
          void showAlert(t('kams.rejectionReasonRequired'), { tone: 'warning' })
          return
        }
        const supervisorMemberId = form.supervisor_member_id ? Number(form.supervisor_member_id) : null
        const res = await api.post('/modules/kams/observation-days', {
          candidate_id: id,
          office_id: form.office_id ? Number(form.office_id) : null,
          observation_date: form.observation_date,
          supervisor_member_id: supervisorMemberId,
          attended: form.attended === '1',
          evaluation: form.evaluation,
          notes: form.notes,
          decision: form.decision,
          rejection_reason: form.rejection_reason || null,
        })
        if (form.decision === 'approved') {
          const payload = res.data.data as {
            member?: Member
            credentials?: MemberAccount
          } | undefined
          const member = payload?.member
          if (member) {
            setCredentials([{
              ...member,
              account: {
                ...(member.account || {}),
                ...(payload?.credentials || {}),
              },
            }])
          }
        }
      }
      setAction(null)
      setDetail(null)
      setPickingSupervisor(false)
      void load()
    } catch (error) {
      const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message
      const mapped = message === 'Distributors cannot be selected as coaches.'
        || message === 'Distributors cannot be selected as sponsors.'
        ? t('kams.distributorCannotSponsor')
        : message === 'The selected coach must have a user account so the candidate can join their team.'
          ? t('kams.supervisorNeedsAccount')
        : message === 'Observation day is not available for this application.'
          ? t('kams.observationNotAvailable')
        : message === 'The selected coach does not belong to this company.'
          ? t('kams.supervisorWrongCompany')
          : null
      void showAlert(mapped || message || t('kams.saveError'), { tone: 'danger' })
    }
  }

  const removeCandidate = async () => {
    if (!deleting) return
    try {
      await api.delete(`/modules/kams/candidates/${deleting.id}`)
      setDeleting(null)
      void load()
    } catch (error) {
      const message = (error as { response?: { data?: { message?: string } } }).response?.data?.message
      void showAlert(
        message === 'This application is already registered as a member.'
          ? t('kams.cannotDeleteRegistered')
          : t('kams.saveError'),
        { tone: 'danger' },
      )
    }
  }

  const officeOptions = offices
    .filter((office) => String(office.company_id) === form.company_id)
    .map((office): SearchComboboxItem => ({
      id: String(office.id),
      label: office.name,
      hint: office.code,
      icon: Building2,
    }))
  const selectedOffice = officeOptions.find((item) => item.id === form.office_id) ?? null
  const companyPeople = people.filter((person) => !detail?.company_id || String(person.company_id) === String(detail.company_id))
  // Encadreur becomes the member upline on approval.
  const supervisorPeople = companyPeople
    .filter((person) => (
      Boolean(person.user_id)
      && person.rank !== 'Distributor'
      && !(person.role_id != null && distributorRoleIds.has(person.role_id))
    ))
    .map((person): SearchComboboxItem => ({
      id: String(person.id),
      label: person.full_name,
      hint: [person.member_code, person.phone].filter(Boolean).join(' · '),
    }))
  const selectedSupervisor = supervisorPeople.find((item) => item.id === form.supervisor_member_id) ?? null
  const supervisorQuery = (form.supervisor_query || '').trim().toLowerCase()
  const supervisorResults = supervisorPeople.filter((item) => {
    if (!supervisorQuery) return true
    return [item.label, item.hint ?? ''].join(' ').toLowerCase().includes(supervisorQuery)
  })
  const interviewerOptions = people
    .filter((person) => (
      Boolean(person.user_id)
      && !isDistributorRole(person)
      && (!detail?.office_id || !person.office_id || String(person.office_id) === String(detail.office_id))
    ))
    .map((person): SearchComboboxItem => ({
      id: String(person.user_id),
      label: person.full_name,
      hint: [person.member_code, person.phone].filter(Boolean).join(' · ') || undefined,
    }))
  const selectedInterviewer = interviewerOptions.find((item) => item.id === form.interviewer_user_id) ?? null
  const companySelected = Boolean(form.company_id)

  return (
    <div>
      <div className="mb-4 flex items-center gap-2 sm:gap-3">
        <label className="flex min-h-11 min-w-0 flex-1 items-center gap-2 rounded-xl border border-line bg-white px-3">
          <Search size={16} className="shrink-0 text-muted" />
          <input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('kams.searchCandidates')}
            className="w-full bg-transparent py-2 text-sm outline-none placeholder:text-muted"
          />
        </label>
        {canCreate ? (
          <Button
            onClick={openCreate}
            className="!h-9 !min-h-0 !w-9 shrink-0 !px-0 sm:!h-11 sm:!min-h-11 sm:!w-auto sm:!px-4"
            title={t('kams.newCandidate')}
            aria-label={t('kams.newCandidate')}
          >
            <UserPlus className="size-5 sm:size-4" />
            <span className="hidden sm:inline">{t('kams.newCandidate')}</span>
          </Button>
        ) : null}
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {loading && rows.length === 0 ? (
          <div className="md:col-span-2">
            <LoadingScreen />
          </div>
        ) : (
          <>
        {rows.map((row) => {
          const interviewState = interviewStepState(row)
          const observationState = observationStepState(row)
          const openInterview = () => {
            const bureau = people.filter((person) => (
              Boolean(person.user_id)
              && !isDistributorRole(person)
              && (!row.office_id || !person.office_id || String(person.office_id) === String(row.office_id))
            ))
            const defaultInterviewerId = bureau.some((person) => person.user_id === user?.id) ? String(user?.id) : ''
            setDetail(row)
            setAction('interview')
            setForm({
              interview_starts_at: new Date().toISOString(),
              result: '',
              attended: '1',
              abandonment_reason: '',
              rejection_reason: '',
              interviewer_user_id: defaultInterviewerId,
              notes: '',
            })
          }
          const openObservation = () => {
            const interview = row.latest_interview
            setDetail(row)
            setAction('observation')
            setForm({
              observation_date: interview?.observation_starts_at ? toDatetimeLocal(interview.observation_starts_at).slice(0, 10) : '',
              observation_starts_at: toDatetimeLocal(interview?.observation_starts_at),
              observation_ends_at: toDatetimeLocal(interview?.observation_ends_at),
              decision: '',
              attended: '1',
              supervisor_member_id: '',
              supervisor_query: '',
              evaluation: '',
              notes: '',
              rejection_reason: '',
            })
          }
          const steps: Array<{
            key: string
            label: string
            state: StepState
            onClick?: () => void
          }> = [
            { key: 'application', label: t('kams.application'), state: 'done' },
            {
              key: 'interview',
              label: t('kams.interview'),
              state: interviewState,
              onClick: interviewState === 'current' && canInterview ? openInterview : undefined,
            },
            {
              key: 'observation',
              label: t('kams.observation'),
              state: observationState,
              onClick: observationState === 'current' && canObservation ? openObservation : undefined,
            },
          ]

          return (
          <Card key={row.id} className="p-5">
            <div className="flex min-w-0 items-start gap-3">
              <PersonPhoto name={personName(row)} url={row.photo_url} />
              <div className="min-w-0 flex-1">
                <div className="font-semibold leading-snug">{personName(row)}</div>
                <div className="mt-0.5 flex min-w-0 flex-wrap items-center gap-2">
                  <span className="min-w-0 flex-1 text-sm text-muted">{t(`kams.source.${row.source}`)}</span>
                  <Badge tone={toneFor(row.status)}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
                </div>
              </div>
            </div>
            <div className="mt-4 flex items-center gap-3">
              {canUpdate || canDelete ? (
              <>
              <div className="flex shrink-0 items-center gap-0.5">
                {canUpdate ? (
                <button
                  type="button"
                  aria-label={t('common.edit')}
                  className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-surface"
                  onClick={() => openEdit(row)}
                >
                  <Pencil size={16} />
                </button>
                ) : null}
                {canDelete ? (
                <button
                  type="button"
                  aria-label={t('common.delete')}
                  className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-surface"
                  onClick={() => {
                    if (!canDeleteCandidate(row)) {
                      void showAlert(t('kams.cannotDeleteRegistered'), { tone: 'warning' })
                      return
                    }
                    setDeleting(row)
                  }}
                >
                  <Trash2 size={16} />
                </button>
                ) : null}
              </div>
              <span className="h-10 w-px shrink-0 bg-line" aria-hidden="true" />
              </>
              ) : null}
              <div className="relative min-w-0 flex-1">
              <div className="pointer-events-none absolute inset-x-[16%] top-[16px] flex">
                <span className={cn('h-0.5 flex-1', stepLineClass(steps[0].state))} />
                <span className={cn('h-0.5 flex-1', stepLineClass(steps[1].state))} />
              </div>
              <div className="relative grid grid-cols-3">
                {steps.map((step, index) => (
                  <button
                    key={step.key}
                    type="button"
                    disabled={!step.onClick}
                    onClick={step.onClick}
                    className={cn(
                      'flex min-w-0 flex-col items-center gap-1 disabled:cursor-default',
                      step.onClick && 'cursor-pointer',
                    )}
                  >
                    <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-full border-2 bg-white text-[11px] font-bold', stepCircleClass(step.state))}>
                      {step.state === 'done' ? <Check size={14} /> : index + 1}
                    </span>
                    <span className={cn(
                      'max-w-full px-0.5 text-center text-[10px] font-semibold leading-tight sm:text-[11px]',
                      step.state === 'current' ? 'text-lhms' : step.state === 'failed' ? 'text-danger' : step.state === 'upcoming' ? 'text-muted' : 'text-ink',
                    )}>
                      {step.label}
                    </span>
                  </button>
                ))}
              </div>
              </div>
            </div>
          </Card>
          )
        })}
        {rows.length === 0 ? <p className="text-sm text-muted md:col-span-2">{t('common.empty')}</p> : null}
          </>
        )}
      </div>
      <div className="mt-4 flex items-center justify-center gap-2 overflow-x-auto whitespace-nowrap text-xs sm:gap-3 sm:text-sm">
        <p className="shrink-0 text-muted">
          {t('kams.pageRange', {
            from: total === 0 ? 0 : (page - 1) * perPage + 1,
            to: Math.min(page * perPage, total),
            total,
          })}
        </p>
        <label className="flex shrink-0 items-center gap-1.5 text-muted">
          {t('kams.rowsPerPage')}
          <select
            value={perPage}
            onChange={(event) => setPerPage(Number(event.target.value))}
            className="h-8 rounded-full border border-line bg-white px-2 text-xs font-semibold text-ink sm:h-10 sm:px-3 sm:text-sm"
          >
            {[10, 20, 50].map((size) => (
              <option key={size} value={size}>{size}</option>
            ))}
          </select>
        </label>
        <PaginationButtons
          className="shrink-0"
          page={page}
          lastPage={lastPage}
          disabled={loading}
          onPrev={() => setPage((value) => Math.max(1, value - 1))}
          onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
        />
      </div>

      <Modal open={open} title={editingId ? t('kams.editCandidate') : t('kams.newCandidate')} onClose={() => { setOpen(false); resetCandidateForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          <div className="flex flex-col items-center gap-2 pb-1">
            <PhotoSourcePicker onFile={pickPhoto}>
              {(openPhotoMenu) => (
                <div className="relative h-24 w-24">
                  <button
                    type="button"
                    onClick={openPhotoMenu}
                    aria-label={t('kams.photo')}
                    className="block h-24 w-24 overflow-hidden rounded-full p-0"
                  >
                    {photoPreview || form.first_name || form.last_name ? (
                      <PersonPhoto name={personName({ first_name: form.first_name, last_name: form.last_name })} url={photoPreview} className="h-24 w-24 text-2xl" />
                    ) : (
                      <span className="grid h-24 w-24 place-items-center rounded-full bg-lhms text-white">
                        <Camera size={28} />
                      </span>
                    )}
                  </button>
                  {photoPreview ? (
                    <button
                      type="button"
                      aria-label={t('kams.removePhoto')}
                      className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
                      onClick={clearPhoto}
                    >
                      <Trash2 size={14} />
                    </button>
                  ) : null}
                  <button
                    type="button"
                    aria-label={t('kams.photo')}
                    className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
                    onClick={openPhotoMenu}
                  >
                    <Camera size={14} />
                  </button>
                </div>
              )}
            </PhotoSourcePicker>
            <p className="text-center text-xs text-muted">{t('kams.photoHint')}</p>
            {photoError ? <p className="text-center text-xs text-danger">{photoError}</p> : null}
          </div>
          {offices.length > 1 ? (
            <SearchCombobox
              label={t('kams.office')}
              required
              value={selectedOffice}
              items={officeOptions}
              disabled={!companySelected}
              placeholder={companySelected ? t('kams.searchOffice') : t('kams.selectCompanyFirst')}
              emptyLabel={t('common.empty')}
              onSelect={(item) => setForm({ ...form, office_id: item?.id ?? '' })}
            />
          ) : null}
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('users.firstName')} value={form.first_name || ''} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
            <Field label={t('users.lastName')} value={form.last_name || ''} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <Select label={t('kams.gender')} required value={form.gender || ''} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
              <option value="">—</option>
              <option value="male">{t('kams.genderMale')}</option>
              <option value="female">{t('kams.genderFemale')}</option>
            </Select>
            <Field label={t('kams.birthDate')} type="date" required value={form.birth_date || ''} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />
          </div>
          <Field label={t('kams.phoneWhatsapp')} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} required />
          <Field label={t('kams.email')} type="email" required value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Field label={t('kams.address')} value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          <Select label={t('kams.recruitmentSource')} required value={form.source || ''} onChange={(e) => setForm({ ...form, source: e.target.value })}>
            <option value="">—</option>
            {candidateSources.map((source) => (
              <option key={source} value={source}>{t(`kams.source.${source}`)}</option>
            ))}
          </Select>
          {formError ? <p className="text-sm text-danger">{formError}</p> : null}
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={Boolean(deleting)} title={t('kams.deleteCandidate')} onClose={() => setDeleting(null)}>
        <p className="text-sm text-muted">{t('kams.deleteCandidateConfirm', { name: personName(deleting) })}</p>
        <div className="mt-4 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" onClick={() => void removeCandidate()}>{t('common.delete')}</Button>
        </div>
      </Modal>

      <Modal
        open={Boolean(detail && action)}
        title={action === 'observation' ? t('kams.observation') : t('kams.interview')}
        onClose={() => { setDetail(null); setAction(null); setPickingSupervisor(false) }}
      >
        <form className="space-y-3" onSubmit={(e) => void saveStage(e)}>
          <p className="text-base font-semibold sm:text-lg">{personName(detail as Candidate)}</p>
          {action === 'interview' ? (
            <>
              <p className="rounded-xl bg-surface px-3 py-2 text-sm text-muted">
                {t('kams.interviewPeriod')}: {formatDateTime(form.interview_starts_at)} → {t('kams.untilSave')}
              </p>
              {recruitmentSettings.interview_hours ? (
                <p className="text-sm text-muted">
                  {t('kams.interviewHoursHint', { hours: recruitmentSettings.interview_hours })}
                </p>
              ) : null}
              <SearchCombobox
                label={`${t('kams.interviewer')} *`}
                value={selectedInterviewer}
                items={interviewerOptions}
                placeholder={t('kams.searchUser')}
                emptyLabel={t('common.empty')}
                onSelect={(item) => setForm({ ...form, interviewer_user_id: item?.id ?? '' })}
              />
              <Select label={t('kams.attendance')} value={form.attended || '1'} onChange={(e) => setForm({ ...form, attended: e.target.value })}>
                <option value="1">{t('kams.present')}</option>
                <option value="0">{t('kams.absent')}</option>
              </Select>
              <Field label={t('kams.notes')} value={form.notes || ''} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
              <Select
                label={t('kams.result')}
                required
                value={form.result || ''}
                onChange={(e) => setForm({ ...form, result: e.target.value })}
              >
                <option value="">—</option>
                {interviewResults.map((result) => (
                  <option key={result} value={result}>{t(`kams.status.${result}`)}</option>
                ))}
              </Select>
              {form.result === 'rejected' ? (
                <label className="block space-y-1.5">
                  <span className="text-sm font-medium">{t('kams.rejectionReason')} <span className="text-danger">*</span></span>
                  <textarea
                    required
                    className="min-h-24 w-full rounded-xl border border-line px-3 py-2 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
                    value={form.rejection_reason || ''}
                    onChange={(e) => setForm({ ...form, rejection_reason: e.target.value })}
                  />
                </label>
              ) : null}
              {form.result === 'abandoned' ? (
                <label className="block space-y-1.5">
                  <span className="text-sm font-medium">{t('kams.abandonmentReason')} <span className="text-danger">*</span></span>
                  <textarea
                    required
                    className="min-h-24 w-full rounded-xl border border-line px-3 py-2 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
                    value={form.abandonment_reason || ''}
                    onChange={(e) => setForm({ ...form, abandonment_reason: e.target.value })}
                  />
                </label>
              ) : null}
            </>
          ) : null}
          {action === 'observation' ? (
            <>
              <p className="text-sm text-muted">
                {recruitmentSettings.observation_hours
                  ? t('kams.observationHoursHint', { hours: recruitmentSettings.observation_hours })
                  : t('kams.observationHours')}
              </p>
              {form.observation_starts_at && form.observation_ends_at ? (
                <p className="rounded-xl bg-surface px-3 py-2 text-sm text-muted">
                  {t('kams.scheduledObservation')}: {form.observation_starts_at.replace('T', ' ')} → {form.observation_ends_at.replace('T', ' ')}
                </p>
              ) : null}
              <Field label={t('kams.observationDate')} type="date" value={form.observation_date || ''} onChange={(e) => setForm({ ...form, observation_date: e.target.value })} required />
              <div className="space-y-1.5">
                <span className="text-sm font-medium">{t('kams.supervisor')} <span className="text-danger">*</span></span>
                <button
                  type="button"
                  className="flex min-h-11 w-full items-center justify-between rounded-xl border border-line bg-white px-3 text-left text-sm"
                  onClick={() => setPickingSupervisor(true)}
                >
                  <span className={selectedSupervisor ? 'font-medium' : 'text-muted'}>
                    {selectedSupervisor ? selectedSupervisor.label : t('kams.pickSupervisor')}
                  </span>
                  <Search size={16} className="text-muted" />
                </button>
                {selectedSupervisor?.hint ? <p className="text-xs text-muted">{selectedSupervisor.hint}</p> : null}
              </div>
              <Select label={t('kams.attendance')} value={form.attended || '1'} onChange={(e) => setForm({ ...form, attended: e.target.value })}>
                <option value="1">{t('kams.present')}</option>
                <option value="0">{t('kams.absent')}</option>
              </Select>
              <Field label={t('kams.evaluation')} value={form.evaluation || ''} onChange={(e) => setForm({ ...form, evaluation: e.target.value })} />
              <Select label={t('kams.finalDecision')} required value={form.decision || ''} onChange={(e) => setForm({ ...form, decision: e.target.value })}>
                <option value="">—</option>
                {observationResults.map((result) => (
                  <option key={result} value={result}>{t(`kams.status.${result}`)}</option>
                ))}
              </Select>
              {form.decision === 'rejected' ? (
                <label className="block space-y-1.5">
                  <span className="text-sm font-medium">{t('kams.rejectionReason')} <span className="text-danger">*</span></span>
                  <textarea
                    required
                    className="min-h-24 w-full rounded-xl border border-line px-3 py-2 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
                    value={form.rejection_reason || ''}
                    onChange={(e) => setForm({ ...form, rejection_reason: e.target.value })}
                  />
                </label>
              ) : null}
            </>
          ) : null}
          <Button type="submit" className="w-full">{t('common.save')}</Button>
        </form>
      </Modal>
      <Modal open={pickingSupervisor} title={t('kams.pickSupervisor')} onClose={() => setPickingSupervisor(false)}>
        <div className="space-y-3">
          <label className="flex min-h-11 items-center gap-2 rounded-xl border border-line bg-white px-3">
            <Search size={16} className="shrink-0 text-muted" />
            <input
              value={form.supervisor_query || ''}
              onChange={(e) => setForm({ ...form, supervisor_query: e.target.value })}
              placeholder={t('kams.searchPerson')}
              className="w-full bg-transparent py-2 text-sm outline-none placeholder:text-muted"
            />
          </label>
          <div className="max-h-64 space-y-1 overflow-auto">
            {supervisorResults.map((item) => (
              <button
                key={item.id}
                type="button"
                className="flex w-full flex-col rounded-xl px-3 py-2 text-left hover:bg-surface"
                onClick={() => {
                  setForm({ ...form, supervisor_member_id: item.id, supervisor_query: '' })
                  setPickingSupervisor(false)
                }}
              >
                <span className="text-sm font-semibold">{item.label}</span>
                {item.hint ? <span className="text-xs text-muted">{item.hint}</span> : null}
              </button>
            ))}
            {supervisorResults.length === 0 ? (
              <p className="text-sm text-muted">
                {supervisorPeople.length === 0 ? t('kams.noEligibleSupervisor') : t('common.empty')}
              </p>
            ) : null}
          </div>
        </div>
      </Modal>
      <Modal open={credentials.length > 0} title={t('kams.loginCredentials')} onClose={() => setCredentials([])}>
        <p className="mb-3 text-sm text-muted">{t('kams.credentialsHint')}</p>
        {credentials.map((item) => (
          <AgentCredentialsCard
            key={item.id}
            name={personName(item)}
            email={item.account?.email}
            phone={item.account?.phone || item.phone}
            password={item.account?.password}
            isActive={item.account?.is_active}
          />
        ))}
        <Button className="mt-4 w-full" onClick={() => setCredentials([])}>{t('common.close')}</Button>
      </Modal>
    </div>
  )
}

export function KamsCommunications() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Array<Record<string, unknown>>>([])
  const [category, setCategory] = useState('')

  const load = () => api.get('/modules/kams/recruitment/communications', { params: category ? { category } : {} }).then((res) => setRows(res.data.data))

  useEffect(() => {
    void load()
  }, [category])

  useLiveData(load)

  return (
    <div>
      <div className="mb-4 flex gap-2">
        <Button variant={category === '' ? 'primary' : 'secondary'} onClick={() => setCategory('')}>{t('common.all')}</Button>
        <Button variant={category === 'recruitment' ? 'primary' : 'secondary'} onClick={() => setCategory('recruitment')}>{t('kams.newRecruitment')}</Button>
        <Button variant={category === 'migration' ? 'primary' : 'secondary'} onClick={() => setCategory('migration')}>{t('kams.existingMigration')}</Button>
      </div>
      <div className="space-y-3">
        {rows.map((row) => (
          <Card key={String(row.id)} className="p-4">
            <div className="flex items-start justify-between gap-3">
              <div>
                <div className="font-semibold">{String(row.subject)}</div>
                <div className="text-sm text-muted">{String(row.recipient_name || row.recipient_type)} · {String(row.channel)} · {String(row.locale)}</div>
                <p className="mt-2 text-sm">{String(row.body)}</p>
              </div>
              <Badge>{String(row.delivery_status)}</Badge>
            </div>
          </Card>
        ))}
      </div>
    </div>
  )
}

export function KamsRecruitmentReports() {
  const { t, i18n } = useTranslation()
  const { hasPermission } = useAuth()
  const canPrint = hasPermission('kams.recruitment.reports.print')
  const canPreview = hasPermission('kams.recruitment.reports.preview')
  const { period, from, to, officeId, offices } = useOutletContext<RecruitmentListFilters>()
  const [rows, setRows] = useState<Candidate[]>([])
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(20)
  const [lastPage, setLastPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [viewing, setViewing] = useState<Candidate | null>(null)
  const [detail, setDetail] = useState<Record<string, unknown> | null>(null)
  const [detailLoading, setDetailLoading] = useState(false)
  const printMenuRef = useRef<HTMLDivElement>(null)
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'

  const reportParams = (pageNumber: number, pageSize: number) => {
    const range = period === 'custom' ? { from, to } : presetRange(period)
    return {
      report: 1,
      page: pageNumber,
      per_page: pageSize,
      ...(officeId ? { office_id: officeId } : {}),
      ...range,
    }
  }

  useEffect(() => {
    setPage(1)
  }, [period, from, to, officeId, perPage])

  const load = () => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/modules/kams/candidates', { params: reportParams(page, perPage) })
      .then((res) => {
        setRows(res.data.data ?? [])
        setLastPage(Math.max(1, Number(res.data.meta?.last_page || 1)))
        setTotal(Number(res.data.meta?.total || 0))
      })
      .catch(() => {
        setRows([])
        setLastPage(1)
        setTotal(0)
      })
      .finally(stop)
  }

  useEffect(() => {
    void load()
  }, [page, perPage, period, from, to, officeId])

  useLiveData(load)

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!printMenuRef.current?.contains(event.target as Node)) setPrintMenuOpen(false)
    }
    document.addEventListener('click', onClick)
    return () => document.removeEventListener('click', onClick)
  }, [])

  const range = period === 'custom' ? { from, to } : presetRange(period)
  const formatDay = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString(locale)

  const printOptions = (list: Candidate[]) => ({
    title: t('kams.recruitmentReports'),
    module: t('kams.title'),
    subtitle: t('kams.recruitmentHint'),
    meta: [
      { label: t('common.filter'), value: range.from && range.to ? t('kams.periodRange', { start: formatDay(range.from), end: formatDay(range.to) }) : '—' },
      { label: t('kams.filterOffice'), value: offices.find((office) => String(office.id) === officeId)?.name || t('kams.allOffices') },
      { label: t('common.records'), value: String(list.length) },
      { label: t('common.printedOn'), value: new Date().toLocaleString() },
    ],
    headers: [
      t('kams.candidateId'),
      `${t('users.firstName')} / ${t('users.lastName')}`,
      t('kams.phoneWhatsapp'),
      t('kams.office'),
      t('kams.recruitmentSource'),
      t('common.status'),
      t('kams.appliedOn'),
    ],
    rows: list.map((row) => [
      row.candidate_id || '',
      personName(row),
      row.phone || '',
      row.office?.name || '',
      t(`kams.source.${row.source}`, { defaultValue: row.source }),
      t(`kams.status.${row.status}`, { defaultValue: row.status }),
      row.applied_at ? formatDay(row.applied_at.slice(0, 10)) : '',
    ]),
    emptyLabel: t('common.empty'),
    footerNote: t('common.confidential'),
    orientation: 'landscape' as const,
  })

  const loadAllForPrint = () => withBusy('data', async () => {
    const collected: Candidate[] = []
    let current = 1
    let pages = 1
    do {
      const res = await api.get('/modules/kams/candidates', { params: reportParams(current, 100) })
      collected.push(...((res.data.data ?? []) as Candidate[]))
      pages = Math.max(1, Number(res.data.meta?.last_page || 1))
      current += 1
    } while (current <= pages && current <= 50)
    return collected
  })

  const printReport = async () => {
    const list = await loadAllForPrint()
    printHtml(buildBrandedTableHtml(printOptions(list)))
  }
  const previewReport = async () => {
    const list = await loadAllForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(printOptions(list)))
  }

  const openCandidateDetails = async (row: Candidate) => {
    setViewing(row)
    setDetail(null)
    setDetailLoading(true)
    try {
      const res = await api.get(`/modules/kams/candidates/${row.id}`)
      setDetail(res.data.data ?? null)
    } catch {
      setViewing(null)
      void showAlert(t('kams.saveError'), { tone: 'danger' })
    } finally {
      setDetailLoading(false)
    }
  }

  const detailCandidate = (detail || viewing) as Candidate | null
  const detailCoach = detailCandidate?.member?.coach?.full_name
    || detailCandidate?.member?.coach_name
    || detailCandidate?.latest_observation_day?.supervisor?.full_name
    || detailCandidate?.latest_observation_day?.supervisor_name
    || null
  const byLatestId = (items: Array<Record<string, unknown>>) =>
    [...items].sort((a, b) => Number(b.id || 0) - Number(a.id || 0))
  const detailInterviews = Array.isArray(detail?.interviews) ? byLatestId(detail.interviews as Array<Record<string, unknown>>) : []
  const detailObservations = Array.isArray(detail?.observation_days)
    ? byLatestId(detail.observation_days as Array<Record<string, unknown>>)
    : Array.isArray(detail?.observationDays)
      ? byLatestId(detail.observationDays as Array<Record<string, unknown>>)
      : []
  const latestInterview = detailInterviews[0] || null
  const latestObservation = detailObservations[0] || null

  const rangeStart = total === 0 ? 0 : (page - 1) * perPage + 1
  const rangeEnd = Math.min(page * perPage, total)

  return (
    <div>
      <div className="mb-4 flex items-center justify-between gap-3">
        <p className="min-w-0 text-sm text-muted">{t('kams.recruitmentReportsHint')}</p>
        {canPrint || canPreview ? (
        <div ref={printMenuRef} className="relative shrink-0" onMouseDown={(event) => event.stopPropagation()}>
          <Button
            variant="secondary"
            className="h-11 px-4"
            title={t('common.print')}
            aria-label={t('common.print')}
            aria-expanded={printMenuOpen}
            disabled={total === 0}
            onClick={() => setPrintMenuOpen((open) => !open)}
          >
            <Printer size={16} /> {t('common.print')}
          </Button>
          {printMenuOpen ? (
            <div className="absolute right-0 z-30 mt-1 w-48 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
              {canPrint ? (
              <button
                type="button"
                className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                onClick={(event) => {
                  event.stopPropagation()
                  setPrintMenuOpen(false)
                  printReport()
                }}
              >
                <Printer size={16} className="text-lhms" /> {t('common.print')}
              </button>
              ) : null}
              {canPreview ? (
              <button
                type="button"
                className="flex w-full items-center gap-2 px-3 py-2.5 text-left text-sm hover:bg-surface"
                onClick={(event) => {
                  event.stopPropagation()
                  setPrintMenuOpen(false)
                  previewReport()
                }}
              >
                <Eye size={16} className="text-lhms" /> {t('common.preview')}
              </button>
              ) : null}
            </div>
          ) : null}
        </div>
        ) : null}
      </div>
      <div className="overflow-hidden rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(4,55,17,0.04)]">
        {loading && rows.length === 0 ? <LoadingScreen /> : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-lhms text-white">
                <tr className="text-left text-xs font-semibold tracking-wide">
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.candidateId')}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{`${t('users.firstName')} / ${t('users.lastName')}`}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.phoneWhatsapp')}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.office')}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.recruitmentSource')}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.status')}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('kams.appliedOn')}</th>
                  <th className="bg-lhms px-4 py-1.5 font-semibold">{t('common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {rows.length === 0 ? (
                  <tr>
                    <td colSpan={8} className="px-4 py-8 text-center text-muted">{t('common.empty')}</td>
                  </tr>
                ) : rows.map((row, index) => (
                  <tr key={row.id} className={cn('border-t border-line', index % 2 === 1 ? 'bg-lhms-soft' : 'bg-white')}>
                    <td className="px-4 py-1.5 whitespace-nowrap text-muted">{row.candidate_id}</td>
                    <td className="px-4 py-1.5 font-semibold whitespace-nowrap">{personName(row)}</td>
                    <td className="px-4 py-1.5 whitespace-nowrap">{row.phone || '—'}</td>
                    <td className="px-4 py-1.5 whitespace-nowrap">{row.office?.name || '—'}</td>
                    <td className="px-4 py-1.5 whitespace-nowrap">{t(`kams.source.${row.source}`, { defaultValue: row.source })}</td>
                    <td className="px-4 py-1.5 whitespace-nowrap">
                      <Badge tone={toneFor(row.status)}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
                    </td>
                    <td className="px-4 py-1.5 whitespace-nowrap text-muted">{row.applied_at ? formatDay(row.applied_at.slice(0, 10)) : '—'}</td>
                    <td className="px-4 py-1.5 whitespace-nowrap">
                      <button
                        type="button"
                        aria-label={t('common.view')}
                        title={t('common.view')}
                        className="grid h-8 w-8 place-items-center rounded-lg bg-lhms-soft text-lhms hover:bg-[#d7eadb]"
                        onClick={() => void openCandidateDetails(row)}
                      >
                        <Info size={16} />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="flex flex-col gap-3 border-t border-line px-4 py-1.5 sm:flex-row sm:items-center sm:justify-between">
          <p className="text-sm text-muted">
            {t('kams.pageRange', { from: rangeStart, to: rangeEnd, total })}
          </p>
          <div className="flex flex-wrap items-center gap-2">
            <label className="flex items-center gap-2 text-sm text-muted">
              {t('kams.rowsPerPage')}
              <select
                value={perPage}
                onChange={(event) => setPerPage(Number(event.target.value))}
                className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink"
              >
                {[10, 20, 50].map((size) => (
                  <option key={size} value={size}>{size}</option>
                ))}
              </select>
            </label>
            <PaginationButtons
              page={page}
              lastPage={lastPage}
              disabled={loading}
              onPrev={() => setPage((value) => Math.max(1, value - 1))}
              onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
            />
          </div>
        </div>
      </div>
      <Modal open={Boolean(viewing)} title={t('kams.candidateDetails')} onClose={() => { setViewing(null); setDetail(null) }}>
        {detailLoading || !detailCandidate ? (
          <LoadingScreen />
        ) : (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              <PersonPhoto name={personName(detailCandidate)} url={detailCandidate.photo_url} className="h-20 w-20 text-xl" />
            </div>
            <div>{t('users.firstName')} / {t('users.lastName')}: <strong>{personName(detailCandidate)}</strong></div>
            <div>{t('kams.candidateId')}: <strong>{detailCandidate.candidate_id}</strong></div>
            <div>{t('kams.gender')}: <strong>{detailCandidate.gender ? t(`kams.gender${detailCandidate.gender === 'female' ? 'Female' : 'Male'}`) : '—'}</strong></div>
            <div>{t('kams.birthDate')}: <strong>{detailCandidate.birth_date ? formatDay(String(detailCandidate.birth_date).slice(0, 10)) : '—'}</strong></div>
            <div>{t('kams.phoneWhatsapp')}: <strong>{detailCandidate.phone || '—'}</strong></div>
            <div>{t('kams.email')}: <strong>{detailCandidate.email || '—'}</strong></div>
            <div>{t('kams.address')}: <strong>{detailCandidate.address || '—'}</strong></div>
            <div>{t('kams.office')}: <strong>{detailCandidate.office?.name || '—'}</strong></div>
            <div>{t('kams.recruitmentSource')}: <strong>{t(`kams.source.${detailCandidate.source}`, { defaultValue: detailCandidate.source })}</strong></div>
            <div>{t('kams.trainerCoach')}: <strong>{detailCoach || '—'}</strong></div>
            {detailCandidate.guardian_name ? (
              <div>{t('kams.guardianName')}: <strong>{detailCandidate.guardian_name}</strong></div>
            ) : null}
            {detailCandidate.guardian_phone ? (
              <div>{t('kams.guardianPhone')}: <strong>{detailCandidate.guardian_phone}</strong></div>
            ) : null}
            <div>{t('common.status')}: <Badge tone={toneFor(detailCandidate.status)}>{t(`kams.status.${detailCandidate.status}`, { defaultValue: detailCandidate.status })}</Badge></div>
            <div>{t('kams.appliedOn')}: <strong>{detailCandidate.applied_at ? formatDay(detailCandidate.applied_at.slice(0, 10)) : '—'}</strong></div>
            {detailCandidate.member?.member_code ? (
              <div>{t('kams.memberId')}: <strong>{detailCandidate.member.member_code}</strong></div>
            ) : null}
            {detailCandidate.notes ? (
              <div className="rounded-xl bg-surface p-3">
                <div className="font-semibold">{t('kams.notes')}</div>
                <p className="mt-1 whitespace-pre-wrap text-muted">{detailCandidate.notes}</p>
              </div>
            ) : null}
            {latestInterview ? (
              <div className="rounded-xl bg-surface p-3">
                <div className="font-semibold">{t('kams.interview')}</div>
                {latestInterview.interview_date ? (
                  <div className="mt-1 text-muted">{t('kams.interviewDate')}: {formatDay(String(latestInterview.interview_date).slice(0, 10))}</div>
                ) : null}
                {latestInterview.interviewer_name ? (
                  <div className="mt-1 text-muted">{t('kams.interviewer')}: {String(latestInterview.interviewer_name)}</div>
                ) : null}
                <div className="mt-1 text-muted">
                  {t('kams.attendance')}: {latestInterview.attended === false || latestInterview.attended === 0
                    ? t('kams.absent')
                    : latestInterview.attended === true || latestInterview.attended === 1
                      ? t('kams.present')
                      : '—'}
                </div>
                <div className="mt-1 text-muted">
                  {t('kams.result')}: {latestInterview.result ? t(`kams.status.${String(latestInterview.result)}`, { defaultValue: String(latestInterview.result) }) : '—'}
                </div>
                {latestInterview.notes ? (
                  <div className="mt-2">
                    <div className="font-medium">{t('kams.notes')}</div>
                    <p className="mt-1 whitespace-pre-wrap text-muted">{String(latestInterview.notes)}</p>
                  </div>
                ) : null}
                {latestInterview.rejection_reason ? (
                  <div className="mt-1 text-muted">{t('kams.rejectionReason')}: {String(latestInterview.rejection_reason)}</div>
                ) : null}
                {latestInterview.abandonment_reason ? (
                  <div className="mt-1 text-muted">{t('kams.abandonmentReason')}: {String(latestInterview.abandonment_reason)}</div>
                ) : null}
              </div>
            ) : null}
            {latestObservation ? (
              <div className="rounded-xl bg-surface p-3">
                <div className="font-semibold">{t('kams.observation')}</div>
                {latestObservation.observation_date ? (
                  <div className="mt-1 text-muted">{t('kams.observationDate')}: {formatDay(String(latestObservation.observation_date).slice(0, 10))}</div>
                ) : null}
                {latestObservation.supervisor_name ? (
                  <div className="mt-1 text-muted">{t('kams.supervisor')}: {String(latestObservation.supervisor_name)}</div>
                ) : null}
                <div className="mt-1 text-muted">
                  {t('kams.attendance')}: {latestObservation.attended === false || latestObservation.attended === 0
                    ? t('kams.absent')
                    : latestObservation.attended === true || latestObservation.attended === 1
                      ? t('kams.present')
                      : '—'}
                </div>
                <div className="mt-1 text-muted">
                  {t('kams.finalDecision')}: {latestObservation.decision ? t(`kams.status.${String(latestObservation.decision)}`, { defaultValue: String(latestObservation.decision) }) : '—'}
                </div>
                {latestObservation.evaluation ? (
                  <div className="mt-2">
                    <div className="font-medium">{t('kams.evaluation')}</div>
                    <p className="mt-1 whitespace-pre-wrap text-muted">{String(latestObservation.evaluation)}</p>
                  </div>
                ) : null}
                {latestObservation.notes ? (
                  <div className="mt-2">
                    <div className="font-medium">{t('kams.notes')}</div>
                    <p className="mt-1 whitespace-pre-wrap text-muted">{String(latestObservation.notes)}</p>
                  </div>
                ) : null}
                {latestObservation.rejection_reason ? (
                  <div className="mt-1 text-muted">{t('kams.rejectionReason')}: {String(latestObservation.rejection_reason)}</div>
                ) : null}
              </div>
            ) : null}
            <Button className="w-full" variant="secondary" onClick={() => { setViewing(null); setDetail(null) }}>{t('common.close')}</Button>
          </div>
        )}
      </Modal>
      <PrintPreviewModal
        open={Boolean(printPreviewHtml)}
        title={t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />
    </div>
  )
}
