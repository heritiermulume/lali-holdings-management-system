import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '../lib/cn'
import { worldCountries, type WorldCountry } from '../data/worldCountries'

function countryLabel(country: WorldCountry, locale: string) {
  return locale.startsWith('fr') ? country.nameFr : country.name
}

export function WorldCountryCombobox({
  value,
  excludeIso = [],
  onSelect,
}: {
  value?: WorldCountry | null
  excludeIso?: string[]
  onSelect: (country: WorldCountry) => void
}) {
  const { t, i18n } = useTranslation()
  const locale = i18n.language
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const root = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      if (!root.current?.contains(event.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [])

  const excluded = useMemo(() => new Set(excludeIso.map((iso) => iso.toUpperCase())), [excludeIso])

  const options = useMemo(() => {
    const term = query.trim().toLowerCase()
    return worldCountries
      .filter((country) => !excluded.has(country.iso))
      .filter((country) => {
        if (!term) return true
        return [country.name, country.nameFr, country.iso, country.phone ?? '']
          .join(' ')
          .toLowerCase()
          .includes(term)
      })
      .slice(0, 80)
  }, [excluded, query])

  return (
    <div ref={root} className="relative space-y-1.5">
      <span className="text-sm font-medium text-ink">{t('org.pickCountry')}</span>
      <input
        className="min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
        placeholder={t('org.searchCountry')}
        value={open ? query : (value ? `${countryLabel(value, locale)} (${value.iso})` : query)}
        onChange={(e) => {
          setQuery(e.target.value)
          setOpen(true)
        }}
        onFocus={() => {
          setQuery('')
          setOpen(true)
        }}
        autoComplete="off"
      />
      {open ? (
        <div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-white py-1 shadow-xl">
          {options.length === 0 ? (
            <div className="px-3 py-2 text-sm text-muted">{t('common.empty')}</div>
          ) : options.map((country) => (
            <button
              key={country.iso}
              type="button"
              className={cn(
                'flex w-full items-start justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-surface',
                value?.iso === country.iso && 'bg-lhms-soft',
              )}
              onClick={() => {
                onSelect(country)
                setQuery('')
                setOpen(false)
              }}
            >
              <span>
                <span className="font-semibold">{countryLabel(country, locale)}</span>
                <span className="mt-0.5 block text-xs text-muted">{country.iso}{country.phone ? ` · ${country.phone}` : ''}</span>
              </span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
