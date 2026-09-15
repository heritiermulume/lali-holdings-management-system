import { useEffect, useMemo, useRef, useState } from 'react'
import { cn } from '../lib/cn'

export type SearchComboboxItem = {
  id: string
  label: string
  hint?: string
}

export function SearchCombobox({
  label,
  value,
  items,
  placeholder,
  disabled,
  emptyLabel,
  onSelect,
}: {
  label: string
  value?: SearchComboboxItem | null
  items: SearchComboboxItem[]
  placeholder?: string
  disabled?: boolean
  emptyLabel?: string
  onSelect: (item: SearchComboboxItem | null) => void
}) {
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

  const options = useMemo(() => {
    const term = query.trim().toLowerCase()
    return items.filter((item) => {
      if (!term) return true
      return [item.label, item.hint ?? ''].join(' ').toLowerCase().includes(term)
    }).slice(0, 80)
  }, [items, query])

  return (
    <div ref={root} className="relative space-y-1.5">
      <span className="text-sm font-medium text-ink">{label}</span>
      <input
        aria-label={label}
        className={cn(
          'min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4',
          disabled && 'bg-surface text-muted',
        )}
        placeholder={placeholder}
        disabled={disabled}
        value={open ? query : (value?.label ?? query)}
        onChange={(e) => {
          setQuery(e.target.value)
          setOpen(true)
          if (!e.target.value) onSelect(null)
        }}
        onFocus={() => {
          if (disabled) return
          setQuery('')
          setOpen(true)
        }}
        autoComplete="off"
      />
      {open && !disabled ? (
        <div className="absolute z-[80] mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-white py-1 shadow-xl">
          {options.length === 0 ? (
            <div className="px-3 py-2 text-sm text-muted">{emptyLabel}</div>
          ) : options.map((item) => (
            <button
              key={item.id}
              type="button"
              className={cn(
                'flex w-full flex-col px-3 py-2 text-left text-sm hover:bg-surface',
                value?.id === item.id && 'bg-lhms-soft',
              )}
              onClick={() => {
                onSelect(item)
                setQuery('')
                setOpen(false)
              }}
            >
              <span className="font-semibold">{item.label}</span>
              {item.hint ? <span className="text-xs text-muted">{item.hint}</span> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
