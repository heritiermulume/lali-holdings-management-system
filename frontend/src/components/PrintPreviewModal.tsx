import { Printer } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { printHtml } from '../lib/printDocument'

export function PrintPreviewModal({
  open,
  title,
  html,
  onClose,
}: {
  open: boolean
  title: string
  html: string
  onClose: () => void
}) {
  const { t } = useTranslation()
  const [src, setSrc] = useState('')

  useEffect(() => {
    if (!open || !html) {
      setSrc('')
      return
    }
    const url = URL.createObjectURL(new Blob([html], { type: 'text/html;charset=utf-8' }))
    setSrc(url)
    return () => URL.revokeObjectURL(url)
  }, [open, html])

  if (!open) return null

  return (
    <div className="fixed inset-0 z-[60] flex flex-col bg-ink/55">
      <div className="flex shrink-0 items-center justify-between gap-3 bg-lhms px-4 py-3 text-white sm:px-6">
        <div className="min-w-0">
          <div className="text-[10px] font-bold tracking-[0.28em] text-white/60">LHMS</div>
          <h2 className="truncate text-base font-extrabold sm:text-lg">{title}</h2>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <button
            type="button"
            className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-white px-4 text-sm font-semibold text-lhms hover:bg-lhms-soft"
            onClick={() => printHtml(html)}
          >
            <Printer size={16} /> {t('common.print')}
          </button>
          <button type="button" onClick={onClose} className="grid h-11 w-11 place-items-center rounded-xl text-white/80 hover:bg-white/10" aria-label={t('common.close')}>
            ✕
          </button>
        </div>
      </div>
      <div className="min-h-0 flex-1 overflow-auto bg-[#d5e0d8] p-3 sm:p-6">
        <div className="mx-auto min-h-full max-w-6xl overflow-hidden rounded-2xl bg-white shadow-2xl">
          {src ? (
            <iframe title={title} src={src} className="block h-[min(78vh,920px)] w-full border-0 bg-white" />
          ) : null}
        </div>
      </div>
    </div>
  )
}
