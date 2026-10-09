import type { SelectHTMLAttributes } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '../lib/cn'

export type OfficeFilterOption = {
  id: number
  name: string
  code?: string | null
}

type Props = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'children'> & {
  offices: OfficeFilterOption[]
  allLabel?: string
  label?: string
}

/** Compact pill office filter used across list/dashboard toolbars. */
export function OfficeFilterSelect({
  offices,
  allLabel,
  label,
  className,
  ...props
}: Props) {
  const { t } = useTranslation()
  const aria = label || t('kams.filterOffice')
  const all = allLabel || t('kams.allOffices')

  return (
    <select
      className={cn(
        'min-h-10 w-full shrink-0 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink outline-none ring-lhms/20 focus:border-lhms focus:ring-4 sm:w-44',
        props.disabled && 'bg-surface text-muted',
        className,
      )}
      aria-label={aria}
      {...props}
    >
      <option value="">{all}</option>
      {offices.map((office) => (
        <option key={office.id} value={office.id}>
          {office.name}{office.code ? ` · ${office.code}` : ''}
        </option>
      ))}
    </select>
  )
}
