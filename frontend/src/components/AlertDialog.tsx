import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react'
import { useEffect, useId, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { registerAlertHandler, type AlertRequest, type AlertTone } from '../lib/alert'
import { cn } from '../lib/cn'
import { Button } from './ui'

type ActiveAlert = AlertRequest & {
  resolve: (value: boolean) => void
}

const toneStyles: Record<AlertTone, {
  iconWrap: string
  icon: typeof Info
  ring: string
  button: 'primary' | 'danger' | 'accent'
}> = {
  info: {
    iconWrap: 'bg-lhms-soft text-lhms',
    icon: Info,
    ring: 'ring-lhms/15',
    button: 'primary',
  },
  success: {
    iconWrap: 'bg-lime-soft text-lime',
    icon: CheckCircle2,
    ring: 'ring-lime/20',
    button: 'primary',
  },
  warning: {
    iconWrap: 'bg-[#fff4e8] text-warn',
    icon: AlertTriangle,
    ring: 'ring-[#b45309]/15',
    button: 'accent',
  },
  danger: {
    iconWrap: 'bg-danger-soft text-danger',
    icon: XCircle,
    ring: 'ring-danger/15',
    button: 'danger',
  },
}

function defaultTitle(tone: AlertTone, t: (key: string) => string) {
  if (tone === 'success') return t('common.alertSuccess')
  if (tone === 'warning') return t('common.alertWarning')
  if (tone === 'danger') return t('common.alertError')
  return t('common.alertInfo')
}

export function AlertProvider({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const titleId = useId()
  const messageId = useId()
  const [active, setActive] = useState<ActiveAlert | null>(null)

  useEffect(() => {
    registerAlertHandler((request) => new Promise<boolean>((resolve) => {
      setActive({ ...request, resolve })
    }))
    return () => registerAlertHandler(null)
  }, [])

  const close = (value: boolean) => {
    const current = active
    setActive(null)
    current?.resolve(value)
  }

  useEffect(() => {
    if (!active) return
    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') close(false)
      if (event.key === 'Enter') close(true)
    }
    window.addEventListener('keydown', onKeyDown)
    return () => window.removeEventListener('keydown', onKeyDown)
  }, [active])

  const tone = active?.tone ?? 'info'
  const styles = toneStyles[tone]
  const Icon = styles.icon

  return (
    <>
      {children}
      {active ? (
        <div className="fixed inset-0 z-[80] flex items-end justify-center p-4 sm:items-center sm:p-6">
          <button
            type="button"
            className="absolute inset-0 bg-ink/45 backdrop-blur-[2px] transition"
            aria-label={t('common.close')}
            onClick={() => close(false)}
          />
          <div
            role="alertdialog"
            aria-modal="true"
            aria-labelledby={titleId}
            aria-describedby={messageId}
            className={cn(
              'relative w-full max-w-sm origin-bottom animate-[alert-in_180ms_ease-out] rounded-[1.75rem] bg-white p-6 shadow-[0_24px_80px_rgba(18,32,24,0.28)] ring-1 ring-black/5 sm:origin-center',
              styles.ring,
            )}
          >
            <div className={cn('mx-auto mb-4 grid h-14 w-14 place-items-center rounded-2xl', styles.iconWrap)}>
              <Icon size={28} strokeWidth={2.1} />
            </div>
            <div className="space-y-2 text-center">
              <h2 id={titleId} className="text-lg font-bold tracking-tight text-ink">
                {active.title || defaultTitle(tone, t)}
              </h2>
              <p id={messageId} className="text-sm leading-relaxed text-muted">
                {active.message}
              </p>
            </div>
            <div className={cn('mt-6 flex gap-2', active.mode === 'confirm' ? 'flex-col-reverse sm:flex-row' : '')}>
              {active.mode === 'confirm' ? (
                <Button variant="secondary" className="flex-1" onClick={() => close(false)}>
                  {active.cancelLabel || t('common.cancel')}
                </Button>
              ) : null}
              <Button
                variant={styles.button}
                className="flex-1"
                autoFocus
                onClick={() => close(true)}
              >
                {active.confirmLabel || (active.mode === 'confirm' ? t('common.confirm') : t('common.ok'))}
              </Button>
            </div>
          </div>
        </div>
      ) : null}
    </>
  )
}
