import type { ButtonHTMLAttributes, InputHTMLAttributes, ReactNode, SelectHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '../lib/cn'

export function Card({ children, className }: { children: ReactNode; className?: string }) {
  return <div className={cn('rounded-2xl border border-line bg-white shadow-[0_10px_40px_rgba(9,73,36,0.04)]', className)}>{children}</div>
}

export function Button({
  children,
  variant = 'primary',
  className,
  ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: 'primary' | 'secondary' | 'ghost' | 'danger' | 'accent' }) {
  const styles = {
    primary: 'bg-lhms text-white hover:bg-lhms-deep',
    secondary: 'bg-lhms-soft text-lhms hover:bg-[#d7eadb]',
    ghost: 'bg-transparent text-ink hover:bg-surface',
    danger: 'bg-danger text-white hover:bg-[#a31818]',
    accent: 'bg-[#fde8d6] text-[#b45309] hover:bg-[#f8d4b0]',
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

function applyInputValidityMessage(
  el: HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement,
  t: (key: string, options?: Record<string, string>) => string,
) {
  if (el.validity.valid) {
    el.setCustomValidity('')
    return
  }
  if (el.validity.valueMissing) {
    el.setCustomValidity(t('validation.required'))
    return
  }
  if (el.validity.rangeOverflow) {
    el.setCustomValidity(t('validation.max', { max: 'max' in el ? String(el.max) : '' }))
    return
  }
  if (el.validity.rangeUnderflow) {
    el.setCustomValidity(t('validation.min', { min: 'min' in el ? String(el.min) : '' }))
    return
  }
  if (el.validity.stepMismatch) {
    el.setCustomValidity(t('validation.step'))
    return
  }
  if (el.validity.typeMismatch) {
    el.setCustomValidity(t('validation.type'))
    return
  }
  if (el.validity.tooShort) {
    const minLength = 'minLength' in el ? String(el.minLength) : ''
    el.setCustomValidity(t('validation.tooShort', { min: minLength }))
    return
  }
  if (el.validity.tooLong) {
    const maxLength = 'maxLength' in el ? String(el.maxLength) : ''
    el.setCustomValidity(t('validation.tooLong', { max: maxLength }))
    return
  }
  if (el.validity.patternMismatch) {
    el.setCustomValidity(t('validation.pattern'))
    return
  }
  el.setCustomValidity(t('validation.invalid'))
}

export function Field({
  label,
  className,
  onInvalid,
  onInput,
  ...props
}: InputHTMLAttributes<HTMLInputElement> & { label: string }) {
  const { t } = useTranslation()

  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-ink">
        {label}
        {props.required ? <span className="text-danger"> *</span> : null}
      </span>
      <input
        className={cn(
          'min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4',
          (props.readOnly || props.disabled) && 'bg-surface text-muted',
          className,
        )}
        {...props}
        onInvalid={(event) => {
          applyInputValidityMessage(event.currentTarget, t)
          onInvalid?.(event)
        }}
        onInput={(event) => {
          event.currentTarget.setCustomValidity('')
          onInput?.(event)
        }}
      />
    </label>
  )
}

export function Select({
  label,
  children,
  className,
  onInvalid,
  onChange,
  ...props
}: SelectHTMLAttributes<HTMLSelectElement> & { label: string; children: ReactNode }) {
  const { t } = useTranslation()

  return (
    <label className="block space-y-1.5">
      <span className="text-sm font-medium text-ink">
        {label}
        {props.required ? <span className="text-danger"> *</span> : null}
      </span>
      <select
        className={cn(
          'min-h-11 w-full rounded-xl border border-line bg-white px-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4',
          props.disabled && 'bg-surface text-muted',
          className,
        )}
        {...props}
        onInvalid={(event) => {
          applyInputValidityMessage(event.currentTarget, t)
          onInvalid?.(event)
        }}
        onChange={(event) => {
          event.currentTarget.setCustomValidity('')
          onChange?.(event)
        }}
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
  size = 'default',
}: {
  open: boolean
  title: string
  children: ReactNode
  onClose: () => void
  size?: 'default' | 'lg'
}) {
  const { t } = useTranslation()
  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-end justify-center bg-ink/40 p-0 sm:items-center sm:p-6">
      <button className="absolute inset-0" aria-label={t('common.close')} onClick={onClose} />
      <div className={cn(
        'relative flex max-h-[90vh] w-full flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl',
        size === 'lg' ? 'max-w-3xl' : 'max-w-lg',
      )}>
        <div className="flex shrink-0 items-center justify-between gap-3 px-5 pb-3 pt-5 sm:px-6 sm:pt-6">
          <h2 className="min-w-0 text-lg font-bold">{title}</h2>
          <button type="button" onClick={onClose} className="shrink-0 text-sm text-muted" aria-label={t('common.close')}>✕</button>
        </div>
        <div className="min-h-0 flex-1 overflow-auto px-5 pb-5 sm:px-6 sm:pb-6">
          {children}
        </div>
      </div>
    </div>
  )
}
