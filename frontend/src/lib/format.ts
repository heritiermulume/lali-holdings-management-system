export function money(value?: number | null, currency = 'USD') {
  return new Intl.NumberFormat(undefined, {
    style: 'currency',
    currency,
    maximumFractionDigits: 0,
  }).format(value ?? 0)
}

export function compact(value?: number | null) {
  return new Intl.NumberFormat(undefined, { notation: 'compact', maximumFractionDigits: 1 }).format(value ?? 0)
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
