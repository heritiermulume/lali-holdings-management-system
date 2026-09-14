import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { cn } from '../lib/cn'

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(9,73,36,0.04)]', className)}>{children}</div>
}

export function Button({
  children,
  variant = 'primary',
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'ghost' | 'danger' }) {
  const styles = {
    primary: 'bg-lhms text-white hover:bg-lhms-deep',
    secondary: 'bg-lhms-soft text-lhms hover:bg-[#d7eadb]',
    ghost: 'bg-transparent text-ink hover:bg-surface',
    danger: 'bg-danger text-white hover:bg-[#a31818]',
  }

  return (
    <button
      className={cn('inline-flex min-h-11 items-center justify-center gap-2 rounded-xl px-4 text-sm font-semibold transition disabled:opacity-50', styles[variant], className)}
      {...props}
    >
      {children}
    </button>
  )
}

export function Field({
  label,
  className,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-ink">{label}</span>
      <input
        className={cn(
          'min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4',
          (props.readOnly || props.disabled) && 'bg-surface text-muted',
          className,
        )}
        {...props}
      />
    </label>
  )
}

export function Select({
  label,
  children,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; children: ReactNode }) {
  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-ink">{label}</span>
      <select
        className="min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
        {...props}
      >
        {children}
      </select>
    </label>
  )
}

export function Badge({ children, tone = 'green' }: { children: ReactNode; tone?: 'green' | 'lime' | 'amber' | 'red' | 'slate' }) {
  const tones = {
    green: 'bg-lhms-soft text-lhms',
    lime: 'bg-lime-soft text-lime',
    amber: 'bg-amber-50 text-warn',
    red: 'bg-danger-soft text-danger',
    slate: 'bg-slate-100 text-slate-600',
  }
  return <span className={cn('inline-flex rounded-full px-2.5 py-1 text-xs font-semibold', tones[tone])}>{children}</span>
}

export function PageHeader({ title, subtitle, actions, titleClassName }: { title: string; subtitle?: string; actions?: ReactNode; titleClassName?: string }) {
  return (
    <div className="mb-6 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
      <div>
        <h1 className={cn('text-2xl font-bold tracking-tight sm:text-3xl', titleClassName ?? 'text-ink')}>{title}</h1>
        {subtitle ? <p className="mt-1 text-sm text-muted">{subtitle}</p> : null}
      </div>
      {actions}
    </div>
  )
}

export function Empty({ children }: { children: ReactNode }) {
  return <div className="rounded-2xl border border-dashed border-line px-6 py-12 text-center text-sm text-muted">{children}</div>
}

export function Modal({
  open,
  title,
  children,
  onClose,
}: {
  open: boolean
  title: string
  children: ReactNode
  onClose: () => void
}) {
  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-6">
      <button className="absolute inset-0" aria-label="Close" onClick={onClose} />
      <div className="relative max-h-[90vh] w-full max-w-lg overflow-auto rounded-t-3xl bg-white p-5 shadow-2xl sm:rounded-3xl sm:p-6">
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-bold">{title}</h2>
          <button onClick={onClose} className="text-sm text-muted">✕</button>
        </div>
        {children}
      </div>
    </div>
  )
}
