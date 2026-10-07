import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { cn } from '../lib/cn'

export function PaginationButtons({
  page,
  lastPage,
  onPrev,
  onNext,
  disabled = false,
  className,
}: {
  page: number
  lastPage: number
  onPrev: () => void
  onNext: () => void
  disabled?: boolean
  className?: string
}) {
  const { t } = useTranslation()
  const safeLast = Math.max(1, lastPage)
  const safePage = Math.min(Math.max(1, page), safeLast)
  const prevDisabled = disabled || safePage <= 1
  const nextDisabled = disabled || safePage >= safeLast

  return (
    <div className={cn('flex items-center gap-2', className)}>
      <button
        type="button"
        className={cn(
          'inline-flex h-10 items-center justify-center gap-1 rounded-full px-3 text-sm font-semibold sm:px-3.5',
          prevDisabled ? 'cursor-not-allowed bg-surface text-muted' : 'bg-lhms text-white hover:opacity-95',
        )}
        disabled={prevDisabled}
        aria-label={t('common.prev')}
        onClick={onPrev}
      >
        <ChevronLeft size={16} />
        <span className="hidden sm:inline">{t('common.prev')}</span>
      </button>
      <span className="px-1 text-sm font-semibold tabular-nums">{safePage} / {safeLast}</span>
      <button
        type="button"
        className={cn(
          'inline-flex h-10 items-center justify-center gap-1 rounded-full px-3 text-sm font-semibold sm:px-3.5',
          nextDisabled ? 'cursor-not-allowed bg-surface text-muted' : 'bg-lhms text-white hover:opacity-95',
        )}
        disabled={nextDisabled}
        aria-label={t('common.next')}
        onClick={onNext}
      >
        <span className="hidden sm:inline">{t('common.next')}</span>
        <ChevronRight size={16} />
      </button>
    </div>
  )
}
