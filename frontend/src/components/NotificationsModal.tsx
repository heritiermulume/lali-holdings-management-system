import { Bell, CheckCheck, Trash2 } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { Badge, Button, Empty, Modal } from './ui'
import { LoadingScreen } from './LoadingScreen'
import { PaginationButtons, showListPagination } from './PaginationButtons'
import { useLiveData } from '../hooks/useLiveData'
import { cn } from '../lib/cn'
import api from '../lib/api'
import { trackLoadSpinner } from '../lib/dataSync'

type Notification = {
  id: number
  title: string
  body: string
  type: string
  read_at?: string | null
  created_at: string
  data?: {
    path?: string | null
    member_id?: number | null
    office_id?: number | null
    entry_id?: number | null
    type?: string | null
  } | null
}

type Meta = {
  current_page: number
  last_page: number
  per_page: number
  total: number
  unread_count: number
}

function toneFor(type: string): 'red' | 'green' | 'amber' {
  if (type === 'alert' || type === 'finance_rejected') return 'red'
  if (type === 'finance_approved' || type === 'approval') return 'green'
  if (type === 'finance_request') return 'amber'
  return 'amber'
}

export function NotificationsModal({
  open,
  onClose,
  onUnreadChange,
}: {
  open: boolean
  onClose: () => void
  onUnreadChange?: (count: number) => void
}) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [rows, setRows] = useState<Notification[]>([])
  const [loading, setLoading] = useState(false)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(10)
  const [meta, setMeta] = useState<Meta>({
    current_page: 1,
    last_page: 1,
    per_page: 10,
    total: 0,
    unread_count: 0,
  })

  const applyResponse = useCallback((res: { data: { data?: Notification[]; meta?: Partial<Meta> } }, requestedPage: number, requestedPerPage: number) => {
    const nextMeta: Meta = {
      current_page: res.data.meta?.current_page ?? requestedPage,
      last_page: Math.max(1, res.data.meta?.last_page ?? 1),
      per_page: res.data.meta?.per_page ?? requestedPerPage,
      total: res.data.meta?.total ?? 0,
      unread_count: res.data.meta?.unread_count ?? 0,
    }
    const items = res.data.data ?? []
    if (items.length === 0 && nextMeta.current_page > 1 && nextMeta.total > 0) {
      setPage(nextMeta.current_page - 1)
      return false
    }
    setRows(items)
    setMeta(nextMeta)
    onUnreadChange?.(nextMeta.unread_count)
    return true
  }, [onUnreadChange])

  const load = useCallback(async (nextPage = page, nextPerPage = perPage) => {
    const stop = trackLoadSpinner(setLoading)
    try {
      const res = await api.get('/notifications', { params: { page: nextPage, per_page: nextPerPage } })
      applyResponse(res, nextPage, nextPerPage)
    } finally {
      stop()
    }
  }, [applyResponse, page, perPage])

  useEffect(() => {
    if (!open) return
    void load(page, perPage)
  }, [open, page, perPage, load])

  useLiveData(() => load(page, perPage), { enabled: open })

  const markRead = async (id: number) => {
    await api.post(`/notifications/${id}/read`)
    await load()
  }

  const markAll = async () => {
    await api.post('/notifications/read-all')
    await load()
  }

  const remove = async (id: number) => {
    await api.delete(`/notifications/${id}`)
    await load()
  }

  const openTarget = async (item: Notification) => {
    const path = typeof item.data?.path === 'string' ? item.data.path.trim() : ''
    if (!path.startsWith('/')) return
    if (!item.read_at) {
      try {
        await api.post(`/notifications/${item.id}/read`)
        onUnreadChange?.(Math.max(0, meta.unread_count - 1))
      } catch {
        // Navigation must succeed even if mark-read fails.
      }
    }
    setPage(1)
    onClose()
    navigate(path)
  }

  const lastPage = Math.max(1, meta.last_page)
  const safePage = Math.min(page, lastPage)
  const rangeStart = meta.total === 0 ? 0 : (safePage - 1) * meta.per_page + 1
  const rangeEnd = Math.min(safePage * meta.per_page, meta.total)

  return (
    <Modal
      open={open}
      title={t('notifications.title')}
      onClose={() => {
        setPage(1)
        onClose()
      }}
      size="lg"
    >
      <div className="space-y-4">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <p className="text-sm text-muted">{t('notifications.subtitle')}</p>
          {meta.unread_count > 0 ? (
            <Button variant="secondary" className="h-10" onClick={() => void markAll()}>
              <CheckCheck size={16} />
              {t('notifications.markAll')}
            </Button>
          ) : null}
        </div>

        {loading && rows.length === 0 ? <LoadingScreen /> : null}

        {!loading && rows.length === 0 ? (
          <div className="rounded-2xl border border-dashed border-line bg-surface px-4 py-10 text-center">
            <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-full bg-lhms-soft text-lhms">
              <Bell size={22} />
            </div>
            <Empty>{t('notifications.empty')}</Empty>
          </div>
        ) : null}

        {rows.length > 0 ? (
          <>
            <div className="max-h-[min(52vh,24rem)] space-y-2 overflow-y-auto pr-1">
              {rows.map((item) => {
                const unread = !item.read_at
                const targetPath = typeof item.data?.path === 'string' ? item.data.path.trim() : ''
                const canOpen = targetPath.startsWith('/')
                return (
                  <article
                    key={item.id}
                    className={cn(
                      'rounded-2xl border px-4 py-3 transition',
                      unread
                        ? 'border-lhms/20 bg-lhms-soft/60 shadow-[0_8px_24px_rgba(4,55,17,0.06)]'
                        : 'border-line bg-white opacity-80',
                    )}
                  >
                    <div className="flex items-start gap-3">
                      <span
                        className={cn(
                          'mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full',
                          unread ? 'bg-lhms' : 'bg-line',
                        )}
                        aria-hidden
                      />
                      <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <h3 className={cn('text-sm font-semibold text-ink', unread && 'text-lhms')}>{item.title}</h3>
                          <Badge tone={toneFor(item.type)}>{t(`notifications.types.${item.type}`, { defaultValue: item.type })}</Badge>
                        </div>
                        <p className="mt-1 text-sm leading-relaxed text-muted">{item.body}</p>
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
                          <time className="text-xs text-muted">{new Date(item.created_at).toLocaleString()}</time>
                          <div className="flex flex-wrap items-center gap-2">
                            {canOpen ? (
                              <Button variant="secondary" className="h-9 px-3" onClick={() => void openTarget(item)}>
                                {t('notifications.open')}
                              </Button>
                            ) : null}
                            {unread ? (
                              <Button variant="ghost" className="h-9 px-3" onClick={() => void markRead(item.id)}>
                                {t('notifications.markRead')}
                              </Button>
                            ) : (
                              <span className="text-xs font-medium text-muted">{t('notifications.read')}</span>
                            )}
                            <Button
                              type="button"
                              variant="ghost"
                              className="h-9 px-3 text-danger hover:bg-danger-soft"
                              aria-label={t('common.delete')}
                              onClick={() => void remove(item.id)}
                            >
                              <Trash2 size={16} />
                              {t('common.delete')}
                            </Button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </article>
                )
              })}
            </div>

            {showListPagination(meta.total) ? <div className="flex flex-col gap-3 border-t border-line pt-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="text-sm text-muted">
                {t('kams.pageRange', { from: rangeStart, to: rangeEnd, total: meta.total })}
              </p>
              <div className="flex flex-wrap items-center gap-2">
                <label className="flex items-center gap-2 text-sm text-muted">
                  {t('kams.rowsPerPage')}
                  <select
                    value={perPage}
                    onChange={(event) => {
                      setPerPage(Number(event.target.value))
                      setPage(1)
                    }}
                    className="h-10 rounded-full border border-line bg-white px-3 text-sm font-semibold text-ink"
                  >
                    {[5, 10, 20, 50].map((size) => (
                      <option key={size} value={size}>{size}</option>
                    ))}
                  </select>
                </label>
                <PaginationButtons
                  page={safePage}
                  lastPage={lastPage}
                  disabled={loading}
                  onPrev={() => setPage((value) => Math.max(1, value - 1))}
                  onNext={() => setPage((value) => Math.min(lastPage, value + 1))}
                />
              </div>
            </div> : null}
          </>
        ) : null}
      </div>
    </Modal>
  )
}
