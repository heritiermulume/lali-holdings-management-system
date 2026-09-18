import { ArrowLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, Outlet, useLocation } from 'react-router-dom'
import { PageHeader } from '../ui'
import { useSystemModules } from '../../context/ModulesContext'
import {
  activeNavItem,
  dashboardBusinessSlug,
  moduleFromPath,
  navItemDescription,
  navItemLabel,
  settingsModuleSlug,
  settingsNavItems,
} from '../../modules/registry'

export function ModuleWorkspaceLayout() {
  const { t, i18n } = useTranslation()
  const { pathname } = useLocation()
  const { modules } = useSystemModules()
  const module = moduleFromPath(pathname)
  const isAdminSettings = pathname.startsWith('/administration/settings')
  const navItems = isAdminSettings || module?.slug === 'settings'
    ? settingsNavItems(modules, i18n.language)
    : (module?.items ?? [])
  const item = activeNavItem(pathname, navItems)
  const businessSlug = dashboardBusinessSlug(pathname)
  const settingsSlug = settingsModuleSlug(pathname)
  const business = businessSlug
    ? modules.find((entry) => entry.slug === businessSlug)
    : settingsSlug
      ? modules.find((entry) => entry.slug === settingsSlug)
      : null
  const title = business
    ? (i18n.language.startsWith('fr') ? business.name_fr : business.name)
    : item
      ? navItemLabel(item, t)
      : module
        ? t(module.titleKey)
        : ''
  const subtitle = business
    ? (i18n.language.startsWith('fr') ? business.description_fr : business.description) || (settingsSlug ? t('settings.moduleHint') : t('groupDash.moduleHint'))
    : item
      ? navItemDescription(item, t)
      : undefined

  return (
    <div>
      {businessSlug ? (
        <Link
          to="/administration/dashboard"
          className="mb-3 inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-lhms"
        >
          <ArrowLeft size={16} />
          {t('groupDash.backToOverview')}
        </Link>
      ) : null}
      <PageHeader title={title} subtitle={subtitle || undefined} titleClassName="text-lhms" />
      <Outlet />
    </div>
  )
}
