import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../context/AuthContext'
import { canAccessModule } from '../lib/access'
import { Empty } from './ui'

export function RequirePermission({
  perm,
  anyPerm,
  children,
}: {
  perm?: string
  anyPerm?: string[]
  children: ReactNode
}) {
  const { hasPermission } = useAuth()
  const { t } = useTranslation()
  const allowed = anyPerm?.length
    ? anyPerm.some((item) => hasPermission(item))
    : Boolean(perm && hasPermission(perm))

  if (!allowed) {
    return <Empty>{t('common.noAccess')}</Empty>
  }

  return children
}

export function RequireModule({ slug, children }: { slug: string; children: ReactNode }) {
  const { user } = useAuth()
  const { t } = useTranslation()

  if (!canAccessModule(user, slug)) {
    return <Empty>{t('modules.noAccess')}</Empty>
  }

  return children
}
