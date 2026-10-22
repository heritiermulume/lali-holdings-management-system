export const APP_LOCALES = ['fr', 'en', 'sw'] as const

export type AppLocale = (typeof APP_LOCALES)[number]

export function normalizeLocale(value?: string | null): AppLocale {
  const code = (value || '').toLowerCase().slice(0, 2)
  if (code === 'en' || code === 'sw') return code
  return 'fr'
}

export function intlLocale(value?: string | null): string {
  const code = normalizeLocale(value)
  if (code === 'en') return 'en-GB'
  if (code === 'sw') return 'sw'
  return 'fr-FR'
}
