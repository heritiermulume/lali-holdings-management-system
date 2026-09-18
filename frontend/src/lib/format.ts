export function money(value?: number | null, currency = 'USD', digits = 2) {
  try {
    return new Intl.NumberFormat(undefined, {
      style: 'currency',
      currency,
      minimumFractionDigits: digits,
      maximumFractionDigits: digits,
    }).format(value ?? 0)
  } catch {
    return `${(value ?? 0).toFixed(digits)} ${currency}`
  }
}

export function dateTime(date?: string | null, at?: string | null) {
  const day = (date || at || '').slice(0, 10)
  if (!day) return '—'
  if (!at) return day
  const parsed = new Date(at)
  if (Number.isNaN(parsed.getTime())) return day
  const time = new Intl.DateTimeFormat(undefined, {
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
    if (actionLabel) return `${actionLabel} — ${groupLabel}`
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
