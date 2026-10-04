import { NavLink, useLocation } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { navItemLabel, type ModuleNavItem } from '../../modules/registry'
import { cn } from '../../lib/cn'

export function SettingsMobileTabs({ items }: { items: ModuleNavItem[] }) {
  const { t } = useTranslation()

  if (items.length === 0) return null

  const core = items.filter((item) => !item.to.includes('/settings/modules/'))
  const modules = items.filter((item) => item.to.includes('/settings/modules/'))

  return (
    <nav className="-mx-1 mb-5 flex gap-2 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [-ms-overflow-style:none] [&::-webkit-scrollbar]:hidden">
      {core.map((item) => (
        <SettingsTab key={item.to} item={item} />
      ))}
      {modules.length > 0 ? (
        <span className="flex shrink-0 items-center px-1 text-[10px] font-semibold uppercase tracking-[0.16em] text-muted">
          {t('nav.modules')}
        </span>
      ) : null}
      {modules.map((item) => (
        <SettingsTab key={item.to} item={item} />
      ))}
    </nav>
  )
}

function SettingsTab({ item }: { item: ModuleNavItem }) {
  const { t } = useTranslation()
  const { pathname } = useLocation()
  const Icon = item.icon
  const companiesSelected = item.to === '/administration/settings/companies' && pathname.startsWith('/administration/settings/modules/')

  return (
    <NavLink
      to={item.to}
      end={item.end}
      className={({ isActive }) => cn(
        'flex shrink-0 items-center gap-1.5 rounded-full px-3.5 py-2 text-xs font-semibold whitespace-nowrap',
        isActive || companiesSelected ? 'bg-lhms text-white shadow-sm' : 'bg-surface text-ink',
      )}
    >
      <Icon size={14} className="shrink-0" aria-hidden />
      {navItemLabel(item, t)}
    </NavLink>
  )
}
