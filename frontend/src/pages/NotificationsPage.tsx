import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty } from '../components/ui'
import { LoadingScreen } from '../components/LoadingScreen'
import api from '../lib/api'

type Notification = {
  id: number
  title: string
  body: string
  type: string
  read_at?: string | null
  created_at: string
}

export function NotificationsPage() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Notification[]>([])
  const [loading, setLoading] = useState(true)

  const load = () => api.get('/notifications').then((res) => setRows(res.data.data ?? [])).finally(() => setLoading(false))

  useEffect(() => { void load() }, [])

  if (loading && rows.length === 0) return <LoadingScreen />

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button variant="secondary" onClick={async () => { await api.post('/notifications/read-all'); load() }}>{t('notifications.markAll')}</Button>
      </div>
      {rows.length === 0 ? <Empty>{t('notifications.empty')}</Empty> : (
        <div className="space-y-3">
          {rows.map((item) => (
            <Card key={item.id} className={`p-5 ${item.read_at ? 'opacity-70' : ''}`}>
              <div className="flex items-start justify-between gap-3">
                <div>
                  <div className="font-semibold">{item.title}</div>
                  <p className="mt-1 text-sm text-muted">{item.body}</p>
                  <div className="mt-2 text-xs text-muted">{new Date(item.created_at).toLocaleString()}</div>
                </div>
                <div className="flex flex-col items-end gap-2">
                  <Badge tone={item.type === 'alert' ? 'red' : 'green'}>{item.type}</Badge>
                  {!item.read_at ? (
                    <Button variant="ghost" onClick={async () => { await api.post(`/notifications/${item.id}/read`); load() }}>OK</Button>
                  ) : null}
                </div>
              </div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
