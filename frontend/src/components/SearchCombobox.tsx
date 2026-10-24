import type { LucideIcon } from 'lucide-react'
import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
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
  onOpen,
  onSelect,
}: {
  label: string
  value?: SearchComboboxItem | null
  items: SearchComboboxItem[]
  placeholder?: string
  disabled?: boolean
  emptyLabel?: string
  required?: boolean
  onOpen?: () => void
  onSelect: (item: SearchComboboxItem | null) => void
}) {
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState(false)
  const [menuStyle, setMenuStyle] = useState<{ top: number; left: number; width: number } | null>(null)
  const root = useRef<HTMLDivElement>(null)
  const menu = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const onClick = (event: MouseEvent) => {
      const target = event.target as Node
      if (root.current?.contains(target) || menu.current?.contains(target)) return
      setOpen(false)
    }
    document.addEventListener('mousedown', onClick)
    return () => document.removeEventListener('mousedown', onClick)
  }, [])

  useLayoutEffect(() => {
    if (!open) return
    const place = () => {
      const anchor = root.current?.querySelector('[data-combobox-anchor]')
      if (!anchor) return
      const rect = anchor.getBoundingClientRect()
      setMenuStyle({ top: rect.bottom + 4, left: rect.left, width: rect.width })
    }
    place()
    window.addEventListener('resize', place)
    window.addEventListener('scroll', place, true)
    return () => {
      window.removeEventListener('resize', place)
      window.removeEventListener('scroll', place, true)
    }
  }, [open])

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
        data-combobox-anchor
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
            onOpen?.()
          }}
          autoComplete="off"
        />
      </div>
      {open && !disabled && menuStyle ? createPortal(
        <div
          ref={menu}
          style={{ top: menuStyle.top, left: menuStyle.left, width: menuStyle.width }}
          className="fixed z-[80] max-h-56 overflow-auto rounded-xl border border-line bg-white py-1 shadow-xl"
        >
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
        </div>,
        document.body,
      ) : null}
    </div>
  )
}
