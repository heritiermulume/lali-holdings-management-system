import { useTranslation } from 'react-i18next'
import { Outlet, useLocation } from 'react-router-dom'
import { PageHeader } from '../ui'
import { useSystemModules } from '../../context/ModulesContext'
import { activeModuleItem, dashboardBusinessSlug, moduleFromPath } from '../../modules/registry'

export function ModuleWorkspaceLayout() {
  const { t, i18n } = useTranslation()
  const { pathname } = useLocation()
  const { modules } = useSystemModules()
  const module = moduleFromPath(pathname)
  const item = module ? activeModuleItem(pathname, module) : null
  const businessSlug = dashboardBusinessSlug(pathname)
  const business = businessSlug ? modules.find((entry) => entry.slug === businessSlug) : null
  const title = business
    ? (i18n.language.startsWith('fr') ? business.name_fr : business.name)
    : item
      ? t(item.labelKey)
      : module
        ? t(module.titleKey)
        : ''
  const subtitle = business
    ? (i18n.language.startsWith('fr') ? business.description_fr : business.description) || t('groupDash.moduleHint')
    : item
      ? t(item.descriptionKey)
      : undefined

  return (
    <div>
      <PageHeader title={title} subtitle={subtitle || undefined} titleClassName="text-lhms" />
      <Outlet />
    </div>
  )
}
