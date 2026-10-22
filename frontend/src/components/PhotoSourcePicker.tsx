import { Camera, Image as ImageIcon } from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Button } from './ui'

export function PhotoSourcePicker({
  children,
  onFile,
  title,
}: {
  children: (openMenu: () => void) => ReactNode
  onFile: (file: File) => void
  title?: string
}) {
  const { t } = useTranslation()
  const fileInput = useRef<HTMLInputElement>(null)
  const videoRef = useRef<HTMLVideoElement>(null)
  const streamRef = useRef<MediaStream | null>(null)
  const [menuOpen, setMenuOpen] = useState(false)
  const [cameraOpen, setCameraOpen] = useState(false)
  const [cameraError, setCameraError] = useState('')
  const [capturing, setCapturing] = useState(false)

  const stopCamera = () => {
    streamRef.current?.getTracks().forEach((track) => track.stop())
    streamRef.current = null
    if (videoRef.current) videoRef.current.srcObject = null
  }

  const closeCamera = () => {
    stopCamera()
    setCameraOpen(false)
    setCameraError('')
    setCapturing(false)
  }

  useEffect(() => () => stopCamera(), [])

  useEffect(() => {
    if (!cameraOpen) return
    let cancelled = false
    const start = async () => {
      if (!navigator.mediaDevices?.getUserMedia) {
        setCameraError(t('common.photoCameraError'))
        return
      }
      try {
        const stream = await navigator.mediaDevices.getUserMedia({
          audio: false,
          video: { facingMode: 'user', width: { ideal: 1280 }, height: { ideal: 1280 } },
        })
        if (cancelled) {
          stream.getTracks().forEach((track) => track.stop())
          return
        }
        streamRef.current = stream
        const video = videoRef.current
        if (video) {
          video.srcObject = stream
          await video.play()
        }
      } catch {
        if (!cancelled) setCameraError(t('common.photoCameraError'))
      }
    }
    void start()
    return () => {
      cancelled = true
      stopCamera()
    }
  }, [cameraOpen, t])

  const capturePhoto = async () => {
    const video = videoRef.current
    if (!video || video.videoWidth === 0) return
    setCapturing(true)
    const maxSize = 1280
    let width = video.videoWidth
    let height = video.videoHeight
    if (Math.max(width, height) > maxSize) {
      const scale = maxSize / Math.max(width, height)
      width = Math.round(width * scale)
      height = Math.round(height * scale)
    }
    const canvas = document.createElement('canvas')
    canvas.width = width
    canvas.height = height
    const context = canvas.getContext('2d')
    if (!context) {
      setCapturing(false)
      return
    }
    context.drawImage(video, 0, 0, width, height)
    const blob = await new Promise<Blob | null>((resolve) => {
      canvas.toBlob(resolve, 'image/jpeg', 0.92)
    })
    if (blob) {
      onFile(new File([blob], `photo-${Date.now()}.jpg`, { type: 'image/jpeg' }))
      closeCamera()
      return
    }
    setCapturing(false)
  }

  return (
    <>
      {children(() => setMenuOpen(true))}
      <input
        ref={fileInput}
        type="file"
        accept="image/jpeg,image/png,image/webp"
        className="hidden"
        onChange={(event) => {
          const file = event.target.files?.[0]
          if (file) onFile(file)
          event.target.value = ''
        }}
      />
      {menuOpen ? createPortal(
        <div className="fixed inset-0 z-[70] flex items-end justify-center p-0 sm:items-center sm:p-6">
          <button type="button" className="absolute inset-0 bg-ink/40" aria-label={t('common.close')} onClick={() => setMenuOpen(false)} />
          <div className="relative w-full max-w-sm overflow-hidden rounded-t-3xl bg-white p-2 shadow-2xl sm:rounded-3xl">
            <p className="px-3 pb-1 pt-3 text-sm font-semibold text-ink">{title || t('common.photo')}</p>
            <button
              type="button"
              className="flex w-full items-center gap-3 rounded-2xl px-3 py-3 text-left text-sm font-medium text-ink hover:bg-surface"
              onClick={() => {
                setMenuOpen(false)
                setCameraOpen(true)
              }}
            >
              <span className="grid h-10 w-10 place-items-center rounded-full bg-lhms-soft text-lhms">
                <Camera size={18} />
              </span>
              {t('common.photoCamera')}
            </button>
            <button
              type="button"
              className="flex w-full items-center gap-3 rounded-2xl px-3 py-3 text-left text-sm font-medium text-ink hover:bg-surface"
              onClick={() => {
                setMenuOpen(false)
                fileInput.current?.click()
              }}
            >
              <span className="grid h-10 w-10 place-items-center rounded-full bg-lhms-soft text-lhms">
                <ImageIcon size={18} />
              </span>
              {t('common.photoChooseFile')}
            </button>
            <button
              type="button"
              className="mt-1 w-full rounded-2xl px-3 py-3 text-sm font-semibold text-muted hover:bg-surface"
              onClick={() => setMenuOpen(false)}
            >
              {t('common.cancel')}
            </button>
          </div>
        </div>,
        document.body,
      ) : null}
      {cameraOpen ? createPortal(
        <div className="fixed inset-0 z-[80] flex items-end justify-center bg-ink/60 p-0 sm:items-center sm:p-6">
          <div className="relative flex max-h-[90vh] w-full max-w-lg flex-col overflow-hidden rounded-t-3xl bg-white shadow-2xl sm:rounded-3xl">
            <div className="flex shrink-0 items-center justify-between gap-3 bg-lhms px-5 py-4 text-white">
              <h2 className="text-lg font-bold">{t('common.photoCameraTitle')}</h2>
              <button type="button" className="text-sm text-white/80 hover:text-white" onClick={closeCamera} aria-label={t('common.close')}>
                ✕
              </button>
            </div>
            <div className="space-y-3 p-5">
              <div className="overflow-hidden rounded-2xl bg-ink">
                {cameraError ? (
                  <p className="grid aspect-square place-items-center px-4 text-center text-sm text-white">{cameraError}</p>
                ) : (
                  <video ref={videoRef} playsInline muted autoPlay className="aspect-square w-full object-cover" />
                )}
              </div>
              <div className="flex gap-2">
                <Button type="button" variant="secondary" className="flex-1" onClick={closeCamera}>
                  {t('common.cancel')}
                </Button>
                <Button type="button" className="flex-1" disabled={Boolean(cameraError) || capturing} onClick={() => void capturePhoto()}>
                  {t('common.photoTake')}
                </Button>
              </div>
            </div>
          </div>
        </div>,
        document.body,
      ) : null}
    </>
  )
}
