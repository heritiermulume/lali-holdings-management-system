import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card } from '../components/ui'
import api from '../lib/api'

type Log = {
  id: number
  action: string
  auditable_type: string
  created_at: string
  ip_address?: string
  user?: { name: string; email: string }
}

export function AuditPage() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Log[]>([])

  useEffect(() => {
    api.get('/audit-logs').then((res) => setRows(res.data.data))
  }, [])

  return (
    <div>
      {rows.length === 0 ? (
        <Card className="p-5 text-sm text-muted">{t('common.empty')}</Card>
      ) : (
        <Card className="divide-y divide-line">
          {rows.map((row) => (
            <div key={row.id} className="px-5 py-4">
              <div className="font-semibold">
                {row.user?.name || t('common.system')} → {row.action} → {row.auditable_type.split('\\').pop()}
              </div>
              <div className="mt-1 text-sm text-muted">
                {new Date(row.created_at).toLocaleString()} · {row.ip_address || '—'}
              </div>
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}
