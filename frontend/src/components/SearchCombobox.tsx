import type { LucideIcon } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { cn } from '../lib/cn'

export type SearchComboboxItem = {
  id: string
  label: string
  hint?: string
  imageUrl?: string | null
  icon?: LucideIcon
}

function ItemThumb({ imageUrl, icon: Icon }: { imageUrl?: string | null; icon?: LucideIcon }) {
  return (
    <span className="grid h-9 w-9 shrink-0 place-items-center overflow-hidden rounded-lg bg-lhms-soft text-lhms">
      {imageUrl ? (
        <img src={imageUrl} alt="" className="h-full w-full object-cover" />
      ) : Icon ? (
        <Icon size={18} strokeWidth={2} />
      ) : null}
    </span>
  )
}

export function SearchCombobox({
  label,
  value,
  items,
  placeholder,
  disabled,
  emptyLabel,
  required,
  onSelect,
}: {
  label: string
  value?: SearchComboboxItem | null
  items: SearchComboboxItem[]
  placeholder?: string
  disabled?: boolean
  emptyLabel?: string
  required?: boolean
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
      <span className="text-sm font-medium text-ink">
        {label}
        {required ? <span className="text-danger"> *</span> : null}
      </span>
      <input
        className="sr-only"
        tabIndex={-1}
        value={value?.id ?? ''}
        required={required}
        onChange={() => undefined}
        aria-hidden
      />
      <div
        className={cn(
          'flex min-h-11 items-center gap-2 rounded-xl border border-line bg-white px-2.5 ring-lhms/20 focus-within:border-lhms focus-within:ring-4',
          disabled && 'bg-surface text-muted',
        )}
      >
        {value ? <ItemThumb imageUrl={value.imageUrl} icon={value.icon} /> : null}
        <input
          aria-label={label}
          className="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none"
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
      </div>
      {open && !disabled ? (
        <div className="absolute z-[80] mt-1 max-h-56 w-full overflow-auto rounded-xl border border-line bg-white py-1 shadow-xl">
          {options.length === 0 ? (
            <div className="px-3 py-2 text-sm text-muted">{emptyLabel}</div>
          ) : options.map((item) => (
            <button
              key={item.id}
              type="button"
              className={cn(
                'flex w-full items-center gap-2.5 px-3 py-2 text-left text-sm hover:bg-surface',
                value?.id === item.id && 'bg-lhms-soft',
              )}
              onClick={() => {
                onSelect(item)
                setQuery('')
                setOpen(false)
              }}
            >
              <ItemThumb imageUrl={item.imageUrl} icon={item.icon} />
              <span className="min-w-0 flex-1">
                <span className="block font-semibold">{item.label}</span>
                {item.hint ? <span className="block text-xs text-muted">{item.hint}</span> : null}
              </span>
            </button>
          ))}
        </div>
      ) : null}
    </div>
  )
}
