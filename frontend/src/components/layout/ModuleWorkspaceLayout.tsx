import { useTranslation } from 'react-i18next'
import { Outlet, useLocation } from 'react-router-dom'
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
  const navItems = module?.slug === 'settings' ? settingsNavItems(modules, i18n.language) : (module?.items ?? [])
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
  const isSettings = module?.slug === 'settings'

  return (
    <div>
      <div className={isSettings ? 'hidden lg:block' : undefined}>
        <PageHeader title={title} subtitle={subtitle || undefined} titleClassName="text-lhms" />
      </div>
      <Outlet />
    </div>
  )
}
