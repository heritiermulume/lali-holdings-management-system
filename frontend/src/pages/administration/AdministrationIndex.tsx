import { Navigate } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Empty } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import { moduleRegistry } from '../../modules/registry'

export function AdministrationIndex() {
  const { t } = useTranslation()
  const { hasPermission, hasAnyPermission } = useAuth()
  const next = moduleRegistry.administration.items.find((item) => {
    if (item.anyPerm?.length) return hasAnyPermission(item.anyPerm)
    return Boolean(item.perm && hasPermission(item.perm))
  })

  if (next) return <Navigate to={next.to} replace />
  return <Empty>{t('common.noAccess')}</Empty>
}
