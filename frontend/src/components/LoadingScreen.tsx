import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { cn } from '../lib/cn'
import { subscribeBusy, type BusyKind } from '../lib/busy'

function LoadingMark({ light = false }: { light?: boolean }) {
  return (
    <div className="relative grid h-20 w-20 place-items-center">
      <span className={cn('lhms-loader-ring absolute inset-0 rounded-full border-[3px]', light ? 'border-white/15 border-t-white' : 'border-lhms/15 border-t-lhms')} />
      <span className={cn('lhms-loader-ring lhms-loader-ring-slow absolute inset-2 rounded-full border-[3px]', light ? 'border-lime/20 border-b-lime' : 'border-lime/20 border-b-lime')} />
      <span className={cn(
        'relative grid h-12 w-12 place-items-center rounded-2xl text-[11px] font-extrabold tracking-[0.18em]',
        light ? 'bg-white/15 text-white shadow-[0_10px_30px_rgba(0,0,0,0.18)]' : 'bg-lhms text-white shadow-[0_12px_28px_rgba(9,73,36,0.28)]',
      )}>
        LH
      </span>
    </div>
  )
}

function LoadingCopy({
  kind,
  title,
  message,
  light = false,
}: {
  kind: BusyKind
  title?: string
  message?: string
  light?: boolean
}) {
  const { t } = useTranslation()
  const heading = title || (kind === 'import' ? t('common.importing') : t('common.loadingData'))
  const hint = message || (kind === 'import' ? t('common.importingHint') : t('common.loadingHint'))

  return (
    <div className="relative z-10 flex max-w-sm flex-col items-center text-center">
      <LoadingMark light={light} />
      <div className={cn('mt-5 text-lg font-extrabold tracking-tight', light ? 'text-white' : 'text-ink')}>{heading}</div>
      <p className={cn('mt-2 text-sm leading-6', light ? 'text-white/70' : 'text-muted')}>{hint}</p>
      <div className={cn('lhms-loader-bar mt-6 h-1.5 w-44 overflow-hidden rounded-full', light ? 'bg-white/15' : 'bg-lhms-soft')}>
        <span className={cn('block h-full w-1/2 rounded-full', light ? 'bg-white' : 'bg-lhms')} />
      </div>
    </div>
  )
}

export function LoadingScreen({
  variant = 'section',
  kind = 'data',
  title,
  message,
}: {
  variant?: 'page' | 'overlay' | 'section'
  kind?: BusyKind
  title?: string
  message?: string
}) {
  if (variant === 'page') {
    return (
      <div className="relative grid min-h-dvh place-items-center overflow-hidden bg-lhms px-6">
        <div className="pointer-events-none absolute -right-24 -top-24 h-80 w-80 rounded-full bg-lime/25 blur-3xl" />
        <div className="pointer-events-none absolute bottom-8 left-8 h-64 w-64 rounded-full bg-white/10 blur-2xl" />
        <LoadingCopy kind={kind} title={title} message={message} light />
      </div>
    )
  }

  if (variant === 'overlay') {
    return (
      <div className="fixed inset-0 z-[100] grid place-items-center bg-ink/45 px-5 backdrop-blur-md" role="alert" aria-busy="true" aria-live="polite">
        <div className="relative w-full max-w-sm overflow-hidden rounded-[28px] border border-white/60 bg-white/95 px-8 py-10 shadow-[0_30px_80px_rgba(9,73,36,0.22)]">
          <div className="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-lhms-soft blur-2xl" />
          <LoadingCopy kind={kind} title={title} message={message} />
        </div>
      </div>
    )
  }

  return (
    <div className="grid min-h-[48vh] place-items-center px-4 py-10" role="status" aria-busy="true" aria-live="polite">
      <div className="relative w-full max-w-sm overflow-hidden rounded-[28px] border border-line bg-white px-8 py-10 shadow-[0_18px_50px_rgba(9,73,36,0.08)]">
        <div className="pointer-events-none absolute -right-10 -top-10 h-32 w-32 rounded-full bg-lhms-soft blur-2xl" />
        <LoadingCopy kind={kind} title={title} message={message} />
      </div>
    </div>
  )
}

export function BusyOverlay() {
  const [visible, setVisible] = useState(false)
  const [kind, setKind] = useState<BusyKind>('data')

  useEffect(() => {
    let timer: number | undefined
    const unsubscribe = subscribeBusy((pending, nextKind) => {
      window.clearTimeout(timer)
      if (pending <= 0) {
        setVisible(false)
        return
      }
      setKind(nextKind)
      timer = window.setTimeout(() => setVisible(true), nextKind === 'import' ? 0 : 120)
    })
    return () => {
      window.clearTimeout(timer)
      unsubscribe()
    }
  }, [])

  useEffect(() => {
    if (!visible) return
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      document.body.style.overflow = previous
    }
  }, [visible])

  if (!visible) return null
  return <LoadingScreen variant="overlay" kind={kind} />
}
