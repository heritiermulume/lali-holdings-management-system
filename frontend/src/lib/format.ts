type DisplayPrefs = {
  decimalPlaces: number
  dateFormat: string
  timeZone: string
  /** Active currency used by money() when no override is passed. */
  currency: string
  /** Group reporting currency from finance settings / default FX currency. */
  reportingCurrency: string
  groupName: string
}

const displayPrefs: DisplayPrefs = {
  decimalPlaces: 2,
  dateFormat: 'DD/MM/YYYY',
  timeZone: 'UTC',
  currency: '',
  reportingCurrency: '',
  groupName: '',
}

export function applyDisplayPrefs(input?: {
  decimal_places?: number | string | null
  date_format?: string | null
  timezone?: string | null
  reporting_currency?: string | null
  group_name?: string | null
} | null) {
  const places = Number(input?.decimal_places)
  if (Number.isFinite(places)) displayPrefs.decimalPlaces = Math.max(0, Math.min(6, Math.trunc(places)))
  if (input?.date_format) displayPrefs.dateFormat = input.date_format
  if (input?.timezone) displayPrefs.timeZone = input.timezone
  if (input?.reporting_currency) {
    const code = String(input.reporting_currency).toUpperCase()
    const wasReporting = !displayPrefs.currency || displayPrefs.currency === displayPrefs.reportingCurrency
    displayPrefs.reportingCurrency = code
    if (wasReporting) displayPrefs.currency = code
  }
  if (input?.group_name) displayPrefs.groupName = String(input.group_name)
}

/** Override money() currency for the current workspace (company module vs group reporting). */
export function setActiveCurrency(code?: string | null) {
  const next = (code || displayPrefs.reportingCurrency || '').toUpperCase()
  displayPrefs.currency = next
}

export function reportingCurrency() {
  return displayPrefs.reportingCurrency || displayPrefs.currency
}

export function activeCurrency() {
  return displayPrefs.currency || displayPrefs.reportingCurrency
}

export function groupName() {
  return displayPrefs.groupName
}

function formatDay(date: Date) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: displayPrefs.timeZone || 'UTC',
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).formatToParts(date)
  const pick = (type: Intl.DateTimeFormatPartTypes) => parts.find((part) => part.type === type)?.value ?? ''
  return displayPrefs.dateFormat
    .replace('YYYY', pick('year'))
    .replace('MM', pick('month'))
    .replace('DD', pick('day'))
}

export function money(value?: number | null, currency?: string, digits?: number) {
  const places = digits ?? displayPrefs.decimalPlaces
  const code = (currency || displayPrefs.currency || displayPrefs.reportingCurrency || '').toUpperCase()
  if (!code) {
    return (value ?? 0).toLocaleString(undefined, {
      minimumFractionDigits: places,
      maximumFractionDigits: places,
    })
  }
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency: code,
      minimumFractionDigits: places,
      maximumFractionDigits: places,
    }).format(value ?? 0)
  } catch {
    return `${(value ?? 0).toFixed(places)} ${code}`
  }
}

export function dateTime(date?: string | null, at?: string | null) {
  const source = at || date
  if (!source) return '—'
  const parsed = new Date(source.length <= 10 ? `${source.slice(0, 10)}T00:00:00` : source)
  if (Number.isNaN(parsed.getTime())) return (date || at || '').slice(0, 10) || '—'
  const day = formatDay(parsed)
  if (!at || source.length <= 10) return day
  const time = new Intl.DateTimeFormat(undefined, {
    timeZone: displayPrefs.timeZone || 'UTC',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  }).format(parsed)
  return `${day} ${time}`
}

export function compact(value?: number | null) {
  return new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value ?? 0)
}

export function roleLabel(
  t: (key: string, options?: { defaultValue?: string }) => string,
  role?: { slug?: string | null; name?: string | null } | null,
  gender?: string | null,
) {
  if (!role) return '—'
  if (role.slug && (gender === 'female' || gender === 'male')) {
    const gendered = t(`users.roleNames.${role.slug}_${gender}`, { defaultValue: '' })
    if (gendered) return gendered
  }
  if (role.slug) return t(`users.roleNames.${role.slug}`, { defaultValue: role.name || role.slug })
  return role.name || '—'
}

export function permissionLabel(
  t: (key: string, options?: { defaultValue?: string }) => string,
  permission?: { slug?: string | null; name?: string | null } | null,
) {
  if (!permission?.slug) return permission?.name || '—'
  const specific = t(`settings.permissions.${permission.slug}`, { defaultValue: '' })
  if (specific) return specific
  const parts = permission.slug.split('.')
  const action = parts.at(-1)
  const group = parts.slice(0, -1).join('.')
  if (action && group) {
    const actionLabel = t(`settings.permissionActions.${action}`, { defaultValue: '' })
    const groupLabel = t(`settings.permissionGroups.${group}`, { defaultValue: group })
    if (actionLabel) return `${actionLabel} ${groupLabel}`
  }
  return permission.name || permission.slug
}

export function initials(name?: string) {
  return (name || 'LH')
    .split(' ')
    .slice(0, 2)
    .map((part) => part[0])
    .join('')
    .toUpperCase()
}

export type GreetingKey =
  | 'dashboard.goodMorning'
  | 'dashboard.goodAfternoon'
  | 'dashboard.goodEvening'
  | 'dashboard.goodNight'

export function greetingKey(date = new Date()): GreetingKey {
  const hour = date.getHours()
  if (hour >= 5 && hour < 12) return 'dashboard.goodMorning'
  if (hour >= 12 && hour < 18) return 'dashboard.goodAfternoon'
  if (hour >= 18 && hour < 22) return 'dashboard.goodEvening'
  return 'dashboard.goodNight'
}
