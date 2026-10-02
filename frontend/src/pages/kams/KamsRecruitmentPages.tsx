import { Camera, Check, CheckCircle2, Download, Eye, EyeOff, ListFilter, Pencil, Plus, Printer, Search, Trash2, Upload } from 'lucide-react'
import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Outlet } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { PrintPreviewModal } from '../../components/PrintPreviewModal'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { cn } from '../../lib/cn'
import { initials, roleLabel } from '../../lib/format'
import { buildBrandedTableHtml, printHtml } from '../../lib/printDocument'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { withBusy } from '../../lib/busy'
import { KamsWorkspaceTabs } from './KamsWorkspaceTabs'

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
type LookupPerson = { id: number; company_id: number; office_id?: number | null; user_id?: number | null; member_code: string; full_name: string; phone?: string | null; role_id?: number | null }
type LookupUser = { id: number; name: string; email?: string | null; primary_company_id?: number | null }
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
  sponsor_member_id?: number | null
  source: string
  status: string
  applied_at: string
  office?: { name?: string }
  member?: { member_code?: string } | null
  latest_interview?: {
    result?: string | null
    observation_starts_at?: string | null
    observation_ends_at?: string | null
  } | null
  latest_observation_day?: {
    decision?: string | null
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
  manager_member_id?: number | null
  assistant_manager_member_id?: number | null
  fixed_salary?: number | null
  manager_member?: { id: number; full_name: string } | null
  assistant_manager?: { id: number; full_name: string } | null
  manager_name?: string | null
  rank?: string | null
  role_id?: number | null
  role?: { id: number; name: string; slug?: string } | null
  first_week_completed?: boolean
  office?: { name?: string; code?: string }
  opening_positions?: Array<{ id: number; category: string; amount: string | number; is_opening_balance: boolean }>
  account?: MemberAccount
}
function memberAccount(value: unknown): MemberAccount {
  if (!value || typeof value !== 'object') return {}
  const account = value as MemberAccount
  return {
    email: typeof account.email === 'string' ? account.email : null,
    phone: typeof account.phone === 'string' ? account.phone : null,
    password: typeof account.password === 'string' ? account.password : null,
    is_active: Boolean(account.is_active),
  }
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
  'phone',
  'email',
  'address',
  'office_code',
  'role',
  'trainer_email',
  'joined_on',
  'status',
  'rank',
  'team_name',
  'guardian_name',
  'guardian_phone',
  'consignment_balance',
  'amounts_due',
  'distributor_savings',
] as const

function openingAmount(row: Member, category: string) {
  const match = row.opening_positions?.find((position) => position.category === category)
  return match ? String(match.amount) : ''
}

function agentImportRow(row: Member): string[] {
  return [
    row.member_code || '',
    row.first_name || '',
    row.last_name || '',
    row.full_name || personName(row),
    row.gender || '',
    row.phone || '',
    row.email || '',
    row.address || '',
    row.office?.code || '',
    row.role?.slug || row.role?.name || '',
    row.trainer?.email || '',
    row.joined_on ? row.joined_on.slice(0, 10) : '',
    row.status || '',
    row.rank || '',
    '',
    '',
    '',
    openingAmount(row, 'consignment'),
    openingAmount(row, 'amounts_due'),
    openingAmount(row, 'distributor_savings'),
  ]
}

export function KamsMembersWorkspace() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()

  return (
    <div>
      <KamsWorkspaceTabs
        tabs={[
          { to: '/modules/kams/members', end: true, label: t('kams.statistics'), show: hasPermission('kams.members.view') },
          { to: '/modules/kams/members/recruitment', end: true, label: t('kams.recruitment'), show: hasPermission('kams.recruitment.view') },
          { to: '/modules/kams/members/agents', end: true, label: t('kams.agents'), show: hasPermission('kams.members.view') },
        ]}
      />
      <Outlet />
    </div>
  )
}

export function KamsAgents() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()
  const [rows, setRows] = useState<Member[]>([])
  const [companies, setCompanies] = useState<LookupCompany[]>([])
  const [offices, setOffices] = useState<LookupOffice[]>([])
  const [users, setUsers] = useState<LookupUser[]>([])
  const [roles, setRoles] = useState<LookupRole[]>([])
  const [lookupMembers, setLookupMembers] = useState<LookupPerson[]>([])
  const [origin, setOrigin] = useState('')
  const [officeId, setOfficeId] = useState('')
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
  const [saving, setSaving] = useState(false)
  const [filterOpen, setFilterOpen] = useState(false)
  const [printMenuOpen, setPrintMenuOpen] = useState(false)
  const [printPreviewHtml, setPrintPreviewHtml] = useState('')
  const [credentials, setCredentials] = useState<Member[]>([])
  const photoInput = useRef<HTMLInputElement>(null)
  const filterRef = useRef<HTMLDivElement>(null)
  const printMenuRef = useRef<HTMLDivElement>(null)

  const load = (term = query) => {
    setLoading(true)
    return api.get('/modules/kams/members', {
      params: { per_page: 50, ...(origin ? { origin } : {}), ...(officeId ? { office_id: officeId } : {}), ...(term ? { search: term } : {}) },
    }).then((res) => setRows(res.data.data ?? [])).finally(() => setLoading(false))
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
    void load()
  }, [origin, officeId, query])

  useEffect(() => {
    api.get('/modules/kams/recruitment/lookups').then((res) => {
      setCompanies(res.data.data.companies)
      setOffices(res.data.data.offices)
      setUsers(res.data.data.users ?? [])
      setRoles(res.data.data.roles ?? [])
      setLookupMembers(res.data.data.members ?? [])
    })
  }, [])

  const resetForm = () => {
    setEditing(null)
    setForm({})
    setPhoto(null)
    setPhotoRemoved(false)
    setPhotoError('')
    setFormError('')
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

  const openCreate = () => {
    resetForm()
    setFormOpen(true)
  }

  const openEdit = (row: Member) => {
    resetForm()
    setEditing(row)
    setForm({
      company_id: row.company_id ? String(row.company_id) : '',
      office_id: row.office_id ? String(row.office_id) : '',
      first_name: row.first_name || '',
      last_name: row.last_name || '',
      gender: row.gender || '',
      birth_date: row.birth_date ? row.birth_date.slice(0, 10) : '',
      phone: row.phone || '',
      email: row.email || '',
      address: row.address || '',
      trainer_user_id: row.trainer_user_id ? String(row.trainer_user_id) : '',
      manager_member_id: row.manager_member_id ? String(row.manager_member_id) : '',
      assistant_manager_member_id: row.assistant_manager_member_id ? String(row.assistant_manager_member_id) : '',
      fixed_salary: row.fixed_salary != null ? String(row.fixed_salary) : '',
      role_id: row.role_id ? String(row.role_id) : '',
      joined_on: row.joined_on ? row.joined_on.slice(0, 10) : '',
    })
    setPhotoPreview(row.photo_url || null)
    setFormOpen(true)
  }

  const openView = async (row: Member) => {
    const res = await api.get(`/modules/kams/members/${row.id}`)
    setViewing(row)
    setDetail(res.data.data)
  }

  const submitAgent = async (event: FormEvent) => {
    event.preventDefault()
    if (!form.first_name?.trim() || !form.last_name?.trim() || !form.gender || !form.phone?.trim() || !form.company_id) {
      setFormError(t('kams.requiredAgentFields'))
      return
    }
    const payload = new FormData()
    payload.append('company_id', form.company_id)
    if (form.office_id) payload.append('office_id', form.office_id)
    payload.append('first_name', form.first_name.trim())
    payload.append('last_name', form.last_name.trim())
    payload.append('gender', form.gender)
    payload.append('phone', form.phone.trim())
    if (form.email) payload.append('email', form.email)
    if (form.birth_date) payload.append('birth_date', form.birth_date)
    if (form.address) payload.append('address', form.address)
    payload.append('trainer_user_id', form.trainer_user_id || '')
    payload.append('manager_member_id', form.manager_member_id || '')
    payload.append('assistant_manager_member_id', form.assistant_manager_member_id || '')
    if (form.fixed_salary) payload.append('fixed_salary', form.fixed_salary)
    payload.append('role_id', form.role_id || '')
    if (form.joined_on) payload.append('joined_on', form.joined_on)
    if (photo) payload.append('photo', photo)
    if (editing && photoRemoved && !photo) payload.append('remove_photo', '1')

    setSaving(true)
    setFormError('')
    try {
      if (editing) {
        payload.append('_method', 'PUT')
        await api.post(`/modules/kams/members/${editing.id}`, payload)
        setFormOpen(false)
        resetForm()
      } else {
        const res = await api.post('/modules/kams/members', payload)
        setFormOpen(false)
        resetForm()
        setCredentials([res.data.data as Member])
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
    row.trainer?.name || row.trainer_name || '',
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
    setPrintPreviewHtml(buildBrandedTableHtml(agentPrintOptions(rows)))
    const list = await loadAgentsForPrint()
    setPrintPreviewHtml(buildBrandedTableHtml(agentPrintOptions(list)))
  }

  const officeOptions = offices.filter((office) => String(office.company_id) === form.company_id)
  const companySelected = Boolean(form.company_id)
  const trainerOptions = users.map((user): SearchComboboxItem => ({
    id: String(user.id),
    label: user.name,
    hint: user.email || undefined,
  }))
  const selectedTrainer = trainerOptions.find((item) => item.id === form.trainer_user_id) || null
  const memberOptions = lookupMembers
    .filter((member) => !form.company_id || String(member.company_id) === form.company_id)
    .map((member): SearchComboboxItem => ({
      id: String(member.id),
      label: member.full_name,
      hint: member.member_code,
    }))
  const selectedManager = memberOptions.find((item) => item.id === form.manager_member_id) || null
  const selectedAssistant = memberOptions.find((item) => item.id === form.assistant_manager_member_id) || null
  const selectedRoleSlug = roles.find((role) => String(role.id) === form.role_id)?.slug

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
                origin && 'bg-lhms-soft text-lhms',
              )}
              onClick={() => setFilterOpen((open) => !open)}
            >
              <ListFilter size={16} />
            </button>
            {filterOpen ? (
              <div className="absolute right-0 z-30 mt-1 w-56 overflow-hidden rounded-xl border border-line bg-white py-1 shadow-xl">
                {[
                  { value: '', label: t('common.all') },
                  { value: 'new_recruit', label: t('kams.origin.new_recruit') },
                  { value: 'migrated', label: t('kams.origin.migrated') },
                ].map((option) => (
                  <button
                    key={option.value || 'all'}
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
              </div>
            ) : null}
          </div>
        </div>
        {offices.length > 1 ? (
          <div className="order-1 w-36 shrink-0 md:w-52 lg:w-64">
          <Select
            className="w-full min-w-0"
            label={t('kams.filterOffice')}
            value={officeId}
            onChange={(e) => setOfficeId(e.target.value)}
          >
            <option value="">{t('kams.allOffices')}</option>
            {offices.map((office) => (
              <option key={office.id} value={office.id}>{office.name}{office.code ? ` · ${office.code}` : ''}</option>
            ))}
          </Select>
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
            <Button variant="secondary" className="h-11 w-11 shrink-0 px-0" title={t('common.export')} aria-label={t('common.export')} onClick={() => void exportAgents()}>
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

      <Card className="overflow-hidden p-0">
        {loading && rows.length === 0 ? <LoadingScreen /> : (
          <>
        {rows.length === 0 ? <p className="px-4 py-8 text-center text-sm text-muted">{t('common.empty')}</p> : null}
        {rows.map((row) => (
          <div key={row.id} className="flex items-center gap-3 border-b border-line px-4 py-3 last:border-b-0 hover:bg-surface/70">
            <PersonPhoto name={personName(row)} url={row.photo_url} />
            <div className="min-w-0 flex-1">
              <div className="truncate font-semibold">{personName(row)}</div>
              <div className="truncate text-xs text-muted sm:text-sm">
                {row.member_code} · {row.office?.name || '—'} · {row.role ? roleLabel(t, row.role, row.gender) : memberRankLabel(t, row.rank, row.gender)}
              </div>
            </div>
            <div className="hidden shrink-0 items-center gap-2 sm:flex">
              <Badge tone={toneFor(row.status)}>{t(`kams.status.${row.status}`, { defaultValue: row.status })}</Badge>
            </div>
            <div className="flex shrink-0 items-center gap-1">
              {hasPermission('kams.members.activate') ? (
              <button
                type="button"
                role="switch"
                aria-checked={Boolean(row.account?.is_active)}
                aria-label={t('kams.accountAccess')}
                title={row.account?.is_active ? t('kams.accountOn') : t('kams.accountOff')}
                className="grid h-9 w-9 place-items-center rounded-lg hover:bg-white"
                onClick={() => void toggleAccount(row)}
              >
                <span className={cn('relative block h-4 w-7 rounded-full transition', row.account?.is_active ? 'bg-lhms' : 'bg-line')}>
                  <span className={cn('absolute top-0.5 left-0.5 h-3 w-3 rounded-full bg-white shadow-sm transition', row.account?.is_active && 'translate-x-3')} />
                </span>
              </button>
              ) : null}
              <button type="button" aria-label={t('common.view')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => void openView(row)}>
                <Eye size={16} />
              </button>
              {hasPermission('kams.members.update') ? (
              <button type="button" aria-label={t('common.edit')} className="grid h-9 w-9 place-items-center rounded-lg text-ink hover:bg-white" onClick={() => openEdit(row)}>
                <Pencil size={16} />
              </button>
              ) : null}
              {row.status === 'pending_verification' && hasPermission('kams.members.update') ? (
                <button type="button" aria-label={t('kams.verify')} className="grid h-9 w-9 place-items-center rounded-lg text-lhms hover:bg-white" onClick={() => void api.put(`/modules/kams/members/${row.id}`, { status: 'verified', reason: 'Verified from agents list' }).then(() => load())}>
                  <CheckCircle2 size={16} />
                </button>
              ) : null}
              {row.status === 'verified' && hasPermission('kams.members.activate') ? (
                <button type="button" aria-label={t('kams.activate')} className="grid h-9 w-9 place-items-center rounded-lg text-lhms hover:bg-white" onClick={() => void api.put(`/modules/kams/members/${row.id}`, { status: 'active', reason: 'Activated from agents list' }).then(() => load())}>
                  <Check size={16} />
                </button>
              ) : null}
              {hasPermission('kams.members.delete') ? (
              <button type="button" aria-label={t('common.delete')} className="grid h-9 w-9 place-items-center rounded-lg text-danger hover:bg-white" onClick={() => setDeleting(row)}>
                <Trash2 size={16} />
              </button>
              ) : null}
            </div>
          </div>
        ))}
          </>
        )}
      </Card>

      <Modal open={formOpen} title={editing ? t('kams.editAgent') : t('kams.addAgent')} onClose={() => { setFormOpen(false); resetForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submitAgent(e)}>
          <div className="flex flex-col items-center gap-2 pb-1">
            <div className="relative h-24 w-24">
              <button type="button" onClick={() => photoInput.current?.click()} aria-label={t('kams.photo')} className="block h-24 w-24 overflow-hidden rounded-full p-0">
                {photoPreview || form.first_name || form.last_name ? (
                  <PersonPhoto name={personName({ first_name: form.first_name, last_name: form.last_name })} url={photoPreview} className="h-24 w-24 text-2xl" />
                ) : (
                  <span className="grid h-24 w-24 place-items-center rounded-full bg-lhms text-white"><Camera size={28} /></span>
                )}
              </button>
              {photoPreview ? (
                <button type="button" aria-label={t('kams.removePhoto')} className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md" onClick={() => { setPhoto(null); setPhotoRemoved(true); setPhotoPreview((current) => { if (current?.startsWith('blob:')) URL.revokeObjectURL(current); return null }) }}>
                  <Trash2 size={14} />
                </button>
              ) : null}
              <button type="button" aria-label={t('kams.photo')} className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md" onClick={() => photoInput.current?.click()}>
                <Camera size={14} />
              </button>
            </div>
            <input ref={photoInput} type="file" accept="image/jpeg,image/png,image/webp" className="hidden" onChange={(event) => { pickPhoto(event.target.files?.[0]); event.target.value = '' }} />
            <p className="text-center text-xs text-muted">{t('kams.photoHint')}</p>
            {photoError ? <p className="text-center text-xs text-danger">{photoError}</p> : null}
          </div>
          <Select label={t('users.company')} required disabled={Boolean(editing)} value={form.company_id || ''} onChange={(e) => setForm({ ...form, company_id: e.target.value, office_id: '' })}>
            <option value="">—</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </Select>
          <Select label={t('kams.office')} value={form.office_id || ''} disabled={!companySelected} onChange={(e) => setForm({ ...form, office_id: e.target.value })}>
            <option value="">{companySelected ? '—' : t('kams.selectCompanyFirst')}</option>
            {officeOptions.map((office) => <option key={office.id} value={office.id}>{office.name} ({office.code})</option>)}
          </Select>
          <Select label={t('users.role')} value={form.role_id || ''} onChange={(e) => setForm({ ...form, role_id: e.target.value })}>
            <option value="">—</option>
            {roles.map((role) => (
              <option key={role.id} value={role.id}>{roleLabel(t, role, form.gender)}</option>
            ))}
          </Select>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('users.firstName')} value={form.first_name || ''} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
            <Field label={t('users.lastName')} value={form.last_name || ''} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />
          </div>
          <Select label={t('kams.gender')} required value={form.gender || ''} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
            <option value="">—</option>
            <option value="male">{t('kams.genderMale')}</option>
            <option value="female">{t('kams.genderFemale')}</option>
          </Select>
          <Field label={t('kams.phoneWhatsapp')} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} required />
          <Field label={t('kams.email')} type="email" value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Field label={t('kams.joinedOn')} type="date" value={form.joined_on || ''} onChange={(e) => setForm({ ...form, joined_on: e.target.value })} />
          <SearchCombobox
            label={t('kams.trainerCoach')}
            value={selectedTrainer}
            items={trainerOptions}
            placeholder={t('kams.searchUser')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, trainer_user_id: item?.id || '' })}
          />
          <SearchCombobox
            label={t('kams.teamManager')}
            value={selectedManager}
            items={memberOptions}
            placeholder={t('kams.searchUser')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, manager_member_id: item?.id || '' })}
          />
          <SearchCombobox
            label={t('kams.assistantManager')}
            value={selectedAssistant}
            items={memberOptions}
            placeholder={t('kams.searchUser')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, assistant_manager_member_id: item?.id || '' })}
          />
          {selectedRoleSlug === 'secretary' ? (
            <Field label={t('kams.fixedSalary')} type="number" value={form.fixed_salary || ''} onChange={(e) => setForm({ ...form, fixed_salary: e.target.value })} />
          ) : null}
          {formError ? <p className="text-sm text-danger">{formError}</p> : null}
          <Button type="submit" className="w-full" disabled={saving}>{saving ? t('common.loading') : t('common.save')}</Button>
        </form>
      </Modal>

      <Modal open={importOpen} title={t('kams.importMembers')} onClose={() => { setImportOpen(false); resetImport() }}>
        <form className="space-y-3" onSubmit={(e) => void submitImport(e)}>
          <Select label={t('users.company')} required value={importForm.company_id} onChange={(e) => setImportForm({ ...importForm, company_id: e.target.value })}>
            <option value="">—</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </Select>
          <Select label={t('kams.dataSource')} value={importForm.source} onChange={(e) => setImportForm({ ...importForm, source: e.target.value })}>
            {['registry', 'database', 'excel', 'google_sheets', 'other'].map((source) => (
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

      <Modal open={Boolean(viewing)} title={personName(viewing)} onClose={() => { setViewing(null); setDetail(null) }}>
        {detail ? (
          <div className="space-y-3 text-sm">
            <div className="flex justify-center pb-1">
              <PersonPhoto name={personName({ ...(detail as Member), full_name: String(detail.full_name || viewing?.full_name || '') })} url={typeof detail.photo_url === 'string' ? detail.photo_url : null} className="h-20 w-20 text-xl" />
            </div>
            <div>{t('kams.memberId')}: <strong>{String(detail.member_code)}</strong></div>
            <div>{t('kams.email')}: <strong>{String(detail.email || '—')}</strong></div>
            <div>{t('kams.phoneWhatsapp')}: <strong>{String(detail.phone || '—')}</strong></div>
            <div>{t('kams.joinedOn')}: {String(detail.joined_on || '—')}</div>
            {detail.migrated_at ? <div>{t('kams.migratedOn')}: {String(detail.migrated_at)}</div> : null}
            <div>{t('users.role')}: <strong>{typeof detail.role === 'object' && detail.role ? roleLabel(t, detail.role as { slug?: string; name?: string }, typeof detail.gender === 'string' ? detail.gender : null) : '—'}</strong></div>
            <div>{t('kams.trainerCoach')}: {typeof detail.trainer === 'object' && detail.trainer && 'name' in detail.trainer ? String((detail.trainer as { name: string }).name) : String(detail.trainer_name || '—')}</div>
            <AgentCredentialsCard
              key={viewing?.id}
              revealable
              email={memberAccount(detail.account).email}
              phone={memberAccount(detail.account).phone || (typeof detail.phone === 'string' ? detail.phone : undefined)}
              password={memberAccount(detail.account).password}
              isActive={Boolean(memberAccount(detail.account).is_active)}
            />
            <div className="font-semibold">{t('kams.openingBalances')}</div>
            {((detail.opening_positions as Member['opening_positions']) || []).map((position) => (
              <div key={position.id} className="rounded-xl bg-surface p-3">
                {position.category}: {position.amount} · {t('kams.notANewTransaction')}
              </div>
            ))}
            {detail.origin === 'new_recruit' && !detail.first_week_completed && hasPermission('kams.members.update') ? (
              <Button onClick={() => void api.post(`/modules/kams/members/${detail.id}/first-week`).then(() => viewing && openView(viewing))}>{t('kams.completeFirstWeek')}</Button>
            ) : null}
          </div>
        ) : null}
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
        title={t('common.printPreview')}
        html={printPreviewHtml}
        onClose={() => setPrintPreviewHtml('')}
      />
    </div>
  )
}

export { KamsRecruitmentDashboard } from './KamsRecapPage'

export function KamsCandidates() {
  const { t, i18n } = useTranslation()
  const { user, hasPermission } = useAuth()
  const canCreate = hasPermission('kams.recruitment.create')
  const canUpdate = hasPermission('kams.recruitment.update')
  const canDelete = hasPermission('kams.recruitment.delete')
  const [rows, setRows] = useState<Candidate[]>([])
  const [companies, setCompanies] = useState<LookupCompany[]>([])
  const [offices, setOffices] = useState<LookupOffice[]>([])
  const [people, setPeople] = useState<LookupPerson[]>([])
  const [users, setUsers] = useState<LookupUser[]>([])
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
  const today = isoDate(new Date())
  const [period, setPeriod] = useState<ListPeriod>('week')
  const [from, setFrom] = useState(today)
  const [to, setTo] = useState(today)
  const [officeId, setOfficeId] = useState('')
  const [filtersOpen, setFiltersOpen] = useState(false)
  const photoInput = useRef<HTMLInputElement>(null)
  const [action, setAction] = useState<'interview' | 'observation' | null>(null)
  const [pickingSupervisor, setPickingSupervisor] = useState(false)
  const [credentials, setCredentials] = useState<Member[]>([])
  const [loading, setLoading] = useState(true)

  const load = (term = query) => {
    setLoading(true)
    const range = period === 'custom' ? { from, to } : presetRange(period)
    return api.get('/modules/kams/candidates', {
      params: {
        per_page: 50,
        ...(term ? { search: term } : {}),
        ...(officeId ? { office_id: officeId } : {}),
        ...range,
      },
    }).then((res) => setRows(res.data.data ?? [])).finally(() => setLoading(false))
  }

  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(search.trim()), 300)
    return () => window.clearTimeout(timer)
  }, [search])

  useEffect(() => {
    void load()
  }, [query, period, from, to, officeId])

  useEffect(() => {
    api.get('/modules/kams/recruitment/lookups').then((res) => {
      setCompanies(res.data.data.companies)
      setOffices(res.data.data.offices)
      setPeople(res.data.data.members ?? res.data.data.sponsors ?? [])
      setUsers(res.data.data.users ?? [])
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
    setForm({ company_id: resolveKamsCompanyId() })
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
      guardian_member_id: row.guardian_member_id ? String(row.guardian_member_id) : '',
      sponsor_member_id: row.sponsor_member_id ? String(row.sponsor_member_id) : '',
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
    if (!form.first_name?.trim() || !form.last_name?.trim() || !form.gender || !form.phone?.trim() || !form.source || !companyId) {
      setFormError(t('kams.requiredFields'))
      return
    }

    const payload = new FormData()
    payload.append('company_id', companyId)
    if (form.office_id) payload.append('office_id', form.office_id)
    payload.append('first_name', form.first_name.trim())
    payload.append('last_name', form.last_name.trim())
    payload.append('gender', form.gender)
    payload.append('phone', form.phone.trim())
    payload.append('source', form.source)
    if (form.birth_date) payload.append('birth_date', form.birth_date)
    if (form.email) payload.append('email', form.email)
    if (form.address) payload.append('address', form.address)
    if (form.guardian_member_id) payload.append('guardian_member_id', form.guardian_member_id)
    if (form.sponsor_member_id) payload.append('sponsor_member_id', form.sponsor_member_id)
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
    const id = Number(detail.id)
    try {
      if (action === 'interview') {
        if (form.result === 'abandoned' && !form.abandonment_reason?.trim()) {
          window.alert(t('kams.abandonmentReasonRequired'))
          return
        }
        if (!form.interviewer_user_id) {
          window.alert(t('kams.searchUser'))
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
          result: form.result,
        })
      }
      if (action === 'observation') {
        if (!form.supervisor_member_id) {
          window.alert(t('kams.pickSupervisor'))
          return
        }
        if (form.decision === 'rejected' && !form.rejection_reason?.trim()) {
          window.alert(t('kams.rejectionReasonRequired'))
          return
        }
        const res = await api.post('/modules/kams/observation-days', {
          candidate_id: id,
          office_id: form.office_id ? Number(form.office_id) : null,
          observation_date: form.observation_date,
          supervisor_member_id: form.supervisor_member_id ? Number(form.supervisor_member_id) : null,
          attended: form.attended === '1',
          evaluation: form.evaluation,
          notes: form.notes,
          decision: form.decision,
          rejection_reason: form.rejection_reason || null,
        })
        if (form.decision === 'approved' && res.data.data?.member) {
          setCredentials([res.data.data.member as Member])
        }
      }
      setAction(null)
      setDetail(null)
      setPickingSupervisor(false)
      void load()
    } catch {
      window.alert(t('common.save'))
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
      window.alert(message === 'This application is already registered as a member.'
        ? t('kams.cannotDeleteRegistered')
        : t('kams.saveError'))
    }
  }

  const officeOptions = offices
    .filter((office) => String(office.company_id) === form.company_id)
    .map((office): SearchComboboxItem => ({
      id: String(office.id),
      label: office.name,
      hint: office.code,
    }))
  const selectedOffice = officeOptions.find((item) => item.id === form.office_id) ?? null
  const peopleOptions = people
    .filter((person) => !form.company_id || String(person.company_id) === form.company_id)
    .map((person): SearchComboboxItem => ({
      id: String(person.id),
      label: person.full_name,
      hint: [person.member_code, person.phone].filter(Boolean).join(' · '),
    }))
  const selectedGuardian = peopleOptions.find((item) => item.id === form.guardian_member_id) ?? null
  const selectedSponsor = peopleOptions.find((item) => item.id === form.sponsor_member_id) ?? null
  const supervisorPeople = people
    .filter((person) => !detail?.company_id || String(person.company_id) === String(detail.company_id))
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
  const interviewerOptions = users.map((item): SearchComboboxItem => ({
    id: String(item.id),
    label: item.name,
    hint: item.email || undefined,
  }))
  const selectedInterviewer = interviewerOptions.find((item) => item.id === form.interviewer_user_id) ?? null
  const companySelected = Boolean(form.company_id)
  const locale = i18n.language === 'en' ? 'en-GB' : 'fr-FR'
  const range = period === 'custom' ? { from, to } : presetRange(period)
  const formatDay = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString(locale)

  return (
    <div>
      <div className="mb-4 flex flex-col gap-3 sm:items-end">
        <div className="flex w-full items-center justify-between gap-3">
          <p className="min-w-0 truncate text-xs text-muted whitespace-nowrap">
            {range.from && range.to ? t('kams.periodRange', { start: formatDay(range.from), end: formatDay(range.to) }) : null}
          </p>
          <button
            type="button"
            onClick={() => setFiltersOpen((open) => !open)}
            className={cn('w-fit shrink-0 rounded-full px-3 py-2 text-xs font-semibold sm:text-sm', filtersOpen ? 'bg-lhms text-white' : 'bg-white text-lhms')}
          >
            {filtersOpen ? t('common.hide') : t('common.filter')}
          </button>
        </div>
        {filtersOpen ? <div className="flex w-full flex-col gap-3 sm:items-end">
          {offices.length > 1 ? (
            <Select
              className="w-full min-w-0 sm:w-72"
              label={t('kams.filterOffice')}
              value={officeId}
              onChange={(e) => setOfficeId(e.target.value)}
            >
              <option value="">{t('kams.allOffices')}</option>
              {offices.map((office) => (
                <option key={office.id} value={office.id}>{office.name}{office.code ? ` · ${office.code}` : ''}</option>
              ))}
            </Select>
          ) : null}
          <div className="flex flex-col gap-2 sm:items-end">
            <div className="flex w-full overflow-x-auto rounded-full bg-white p-1 sm:w-auto">
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
              <div className="grid w-full grid-cols-2 gap-2 sm:w-80">
                <Field className="min-w-0 max-w-full" label={t('dashboard.dateFrom')} type="date" value={from} max={to || today} onChange={(event) => setFrom(event.target.value)} />
                <Field className="min-w-0 max-w-full" label={t('dashboard.dateTo')} type="date" value={to} min={from} max={today} onChange={(event) => setTo(event.target.value)} />
              </div>
            ) : null}
          </div>
        </div> : null}
      </div>
      <div className="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
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
          <Button onClick={openCreate} className="shrink-0">
            <Plus size={16} /> {t('kams.newCandidate')}
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
            setDetail(row)
            setAction('interview')
            setForm({
              interview_starts_at: new Date().toISOString(),
              result: '',
              attended: '1',
              abandonment_reason: '',
              interviewer_user_id: user?.id ? String(user.id) : '',
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
              onClick: interviewState === 'current' && canCreate ? openInterview : undefined,
            },
            {
              key: 'observation',
              label: t('kams.observation'),
              state: observationState,
              onClick: observationState === 'current' && canCreate ? openObservation : undefined,
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
                      window.alert(t('kams.cannotDeleteRegistered'))
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

      <Modal open={open} title={editingId ? t('kams.editCandidate') : t('kams.newCandidate')} onClose={() => { setOpen(false); resetCandidateForm() }}>
        <form className="space-y-3" onSubmit={(e) => void submit(e)}>
          <div className="flex flex-col items-center gap-2 pb-1">
            <div className="relative h-24 w-24">
              <button
                type="button"
                onClick={() => photoInput.current?.click()}
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
                onClick={() => photoInput.current?.click()}
              >
                <Camera size={14} />
              </button>
            </div>
            <input
              ref={photoInput}
              type="file"
              accept="image/jpeg,image/png,image/webp"
              className="hidden"
              onChange={(event) => {
                pickPhoto(event.target.files?.[0])
                event.target.value = ''
              }}
            />
            <p className="text-center text-xs text-muted">{t('kams.photoHint')}</p>
            {photoError ? <p className="text-center text-xs text-danger">{photoError}</p> : null}
          </div>
          <SearchCombobox
            label={t('kams.office')}
            value={selectedOffice}
            items={officeOptions}
            disabled={!companySelected}
            placeholder={companySelected ? t('kams.searchOffice') : t('kams.selectCompanyFirst')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, office_id: item?.id ?? '' })}
          />
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label={t('users.firstName')} value={form.first_name || ''} onChange={(e) => setForm({ ...form, first_name: e.target.value })} required />
            <Field label={t('users.lastName')} value={form.last_name || ''} onChange={(e) => setForm({ ...form, last_name: e.target.value })} required />
          </div>
          <Select label={t('kams.gender')} required value={form.gender || ''} onChange={(e) => setForm({ ...form, gender: e.target.value })}>
            <option value="">—</option>
            <option value="male">{t('kams.genderMale')}</option>
            <option value="female">{t('kams.genderFemale')}</option>
          </Select>
          <Field label={t('kams.birthDate')} type="date" value={form.birth_date || ''} onChange={(e) => setForm({ ...form, birth_date: e.target.value })} />
          <Field label={t('kams.phoneWhatsapp')} value={form.phone || ''} onChange={(e) => setForm({ ...form, phone: e.target.value })} required />
          <Field label={t('kams.email')} type="email" value={form.email || ''} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Field label={t('kams.address')} value={form.address || ''} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          <SearchCombobox
            label={t('kams.guardianName')}
            value={selectedGuardian}
            items={peopleOptions}
            disabled={!companySelected}
            placeholder={companySelected ? t('kams.searchPerson') : t('kams.selectCompanyFirst')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, guardian_member_id: item?.id ?? '' })}
          />
          <Select label={t('kams.recruitmentSource')} required value={form.source || ''} onChange={(e) => setForm({ ...form, source: e.target.value })}>
            <option value="">—</option>
            {['poster', 'qr_code', 'online_form', 'sponsorship', 'social_media', 'other'].map((source) => (
              <option key={source} value={source}>{t(`kams.source.${source}`)}</option>
            ))}
          </Select>
          <SearchCombobox
            label={t('kams.sponsor')}
            value={selectedSponsor}
            items={peopleOptions}
            disabled={!companySelected}
            placeholder={companySelected ? t('kams.searchPerson') : t('kams.selectCompanyFirst')}
            emptyLabel={t('common.empty')}
            onSelect={(item) => setForm({ ...form, sponsor_member_id: item?.id ?? '' })}
          />
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

      <Modal open={Boolean(detail && action)} title={personName(detail as Candidate)} onClose={() => { setDetail(null); setAction(null); setPickingSupervisor(false) }}>
        <form className="space-y-3" onSubmit={(e) => void saveStage(e)}>
          {action === 'interview' ? (
            <>
              <p className="rounded-xl bg-surface px-3 py-2 text-sm text-muted">
                {t('kams.interviewPeriod')}: {formatDateTime(form.interview_starts_at)} → {t('kams.untilSave')}
              </p>
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
                <option value="admitted">{t('kams.status.admitted')}</option>
                <option value="rejected">{t('kams.status.rejected')}</option>
                <option value="on_hold">{t('kams.status.on_hold')}</option>
                <option value="abandoned">{t('kams.status.abandoned')}</option>
              </Select>
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
              <p className="text-sm text-muted">{t('kams.observationHours')}</p>
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
                <option value="approved">{t('kams.status.approved')}</option>
                <option value="rejected">{t('kams.status.rejected')}</option>
                <option value="on_hold">{t('kams.status.on_hold')}</option>
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
            {supervisorResults.length === 0 ? <p className="text-sm text-muted">{t('common.empty')}</p> : null}
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

  useEffect(() => {
    api.get('/modules/kams/recruitment/communications', { params: category ? { category } : {} }).then((res) => setRows(res.data.data))
  }, [category])

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
