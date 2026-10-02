import type { LucideIcon } from 'lucide-react'
import {
  Bell,
  Building2,
  Crown,
  Globe2,
  Landmark,
  LayoutDashboard,
  ListChecks,
  ScrollText,
  Settings,
  Shield,
  SlidersHorizontal,
  Truck,
  Tv,
  UserCog,
  Users,
  Warehouse,
  Wheat,
} from 'lucide-react'
import type { TFunction } from 'i18next'
import type { SystemModule } from '../types'

export type ModuleNavItem = {
  to: string
  labelKey: string
  descriptionKey: string
  label?: string
  description?: string
  icon: LucideIcon
  end?: boolean
  perm?: string
  anyPerm?: string[]
}

export function navItemLabel(item: ModuleNavItem, t: TFunction) {
  return item.label ?? t(item.labelKey)
}

export function navItemDescription(item: ModuleNavItem, t: TFunction) {
  return item.description ?? t(item.descriptionKey)
}

export type ModuleDefinition = {
  slug: string
  nameKey: string
  titleKey: string
  icon: LucideIcon
  items: ModuleNavItem[]
}

export const moduleRegistry: Record<string, ModuleDefinition> = {
  administration: {
    slug: 'administration',
    nameKey: 'nav.administration',
    titleKey: 'nav.administration',
    icon: Shield,
    items: [
      { to: '/administration/dashboard', labelKey: 'nav.dashboard', descriptionKey: 'dashboard.overview', icon: LayoutDashboard, anyPerm: ['administration.view'] },
      { to: '/administration/users', labelKey: 'nav.users', descriptionKey: 'users.subtitle', icon: Users, perm: 'users.view' },
      { to: '/administration/audit', labelKey: 'nav.audit', descriptionKey: 'audit.subtitle', icon: ScrollText, perm: 'audit.view' },
      { to: '/administration/settings', labelKey: 'nav.settings', descriptionKey: 'settings.generalHint', icon: Settings, anyPerm: ['settings.manage', 'organization.view', 'companies.view', 'roles.manage', 'roles.view'] },
    ],
  },
  dashboard: {
    slug: 'dashboard',
    nameKey: 'nav.dashboard',
    titleKey: 'groupDash.title',
    icon: LayoutDashboard,
    items: [
      { to: '/administration/dashboard', labelKey: 'groupDash.overview', descriptionKey: 'groupDash.overviewHint', icon: LayoutDashboard, end: true, anyPerm: ['administration.view'] },
      { to: '/administration/dashboard/actions', labelKey: 'groupDash.actions', descriptionKey: 'groupDash.actionsHint', icon: ListChecks, anyPerm: ['administration.view'] },
    ],
  },
  kams: {
    slug: 'kams',
    nameKey: 'nav.kams',
    titleKey: 'kams.title',
    icon: Crown,
    items: [
      { to: '/modules/kams', labelKey: 'nav.dashboard', descriptionKey: 'kams.dashboardHint', icon: LayoutDashboard, end: true, perm: 'kams.dashboard.view' },
      { to: '/modules/kams/members', labelKey: 'kams.members', descriptionKey: 'kams.membersHint', icon: Users, perm: 'kams.members.view', anyPerm: ['kams.members.view', 'kams.recruitment.view'] },
      { to: '/modules/kams/stock', labelKey: 'kams.stock', descriptionKey: 'kams.stockHint', icon: Warehouse, anyPerm: ['kams.products.view', 'kams.orders.view'] },
      { to: '/modules/kams/finance', labelKey: 'kams.finance', descriptionKey: 'kams.financeHint', icon: Landmark, anyPerm: ['kams.finance.dashboard.view', 'kams.finance.account.view', 'kams.finance.company.view'] },
      { to: '/modules/kams/clients', labelKey: 'kams.clientsAndSuppliers', descriptionKey: 'kams.clientsAndSuppliersHint', icon: Users, perm: 'kams.clients.view' },
    ],
  },
  users: {
    slug: 'users',
    nameKey: 'nav.users',
    titleKey: 'nav.users',
    icon: Users,
    items: [
      { to: '/administration/users', labelKey: 'nav.users', descriptionKey: 'users.subtitle', icon: Users, end: true, perm: 'users.view' },
    ],
  },
  notifications: {
    slug: 'notifications',
    nameKey: 'nav.notifications',
    titleKey: 'nav.notifications',
    icon: Bell,
    items: [
      { to: '/notifications', labelKey: 'nav.notifications', descriptionKey: 'notifications.subtitle', icon: Bell, end: true, perm: 'notifications.view' },
    ],
  },
  audit: {
    slug: 'audit',
    nameKey: 'nav.audit',
    titleKey: 'nav.audit',
    icon: ScrollText,
    items: [
      { to: '/administration/audit', labelKey: 'nav.audit', descriptionKey: 'audit.subtitle', icon: ScrollText, end: true, perm: 'audit.view' },
    ],
  },
  settings: {
    slug: 'settings',
    nameKey: 'nav.settings',
    titleKey: 'nav.settings',
    icon: Settings,
    items: [
      { to: '/administration/settings', labelKey: 'settings.general', descriptionKey: 'settings.generalHint', icon: SlidersHorizontal, end: true, perm: 'settings.manage' },
      { to: '/administration/settings/organization', labelKey: 'nav.organization', descriptionKey: 'settings.organizationHint', icon: Globe2, perm: 'organization.view' },
      { to: '/administration/settings/companies', labelKey: 'nav.companies', descriptionKey: 'settings.companiesHint', icon: Building2, perm: 'companies.view' },
      { to: '/administration/settings/roles', labelKey: 'nav.roles', descriptionKey: 'settings.rolesNavHint', icon: UserCog, perm: 'roles.manage' },
      { to: '/administration/settings/finance', labelKey: 'settings.finance', descriptionKey: 'settings.financeHint', icon: Landmark, perm: 'settings.manage' },
      { to: '/administration/settings/notifications', labelKey: 'settings.notifications', descriptionKey: 'settings.notificationsHint', icon: Bell, perm: 'settings.manage' },
      { to: '/administration/settings/security', labelKey: 'settings.security', descriptionKey: 'settings.securityHint', icon: Shield, perm: 'settings.manage' },
    ],
  },
}

export function settingsNavItems(modules: SystemModule[], locale: string): ModuleNavItem[] {
  const langFr = locale.startsWith('fr')
  return [
    ...moduleRegistry.settings.items,
    ...modules.map((module) => ({
      to: `/administration/settings/modules/${module.slug}`,
      labelKey: 'settings.module',
      descriptionKey: 'settings.moduleHint',
      label: langFr ? module.name_fr : module.name,
      description: (langFr ? module.description_fr : module.description) || undefined,
      icon: businessModuleIcon(module.slug),
      perm: 'settings.manage',
    })),
  ]
}

const platformPrefixes: Array<[string, string]> = [
  ['/administration', 'administration'],
  ['/users', 'administration'],
  ['/notifications', 'notifications'],
  ['/audit', 'administration'],
  ['/settings', 'administration'],
  ['/organization', 'administration'],
  ['/companies', 'administration'],
]

export function moduleFromPath(pathname: string): ModuleDefinition | null {
  const match = pathname.match(/^\/modules\/([^/]+)/)
  if (match) return moduleRegistry[match[1]] ?? null
  const found = platformPrefixes.find(([prefix]) => pathname === prefix || pathname.startsWith(`${prefix}/`))
  return found ? moduleRegistry[found[1]] ?? null : null
}

export function settingsModuleSlug(pathname: string): string | null {
  const match = pathname.match(/^\/(?:administration\/)?settings\/modules\/([^/]+)$/)
  return match?.[1] ?? null
}

const businessIcons: Record<string, LucideIcon> = {
  kams: Crown,
  'heavy-equipment': Truck,
  'real-estate': Building2,
  'rice-mills': Wheat,
  'lali-tv': Tv,
}

export function businessModuleIcon(slug: string): LucideIcon {
  return businessIcons[slug] ?? Crown
}

export function dashboardBusinessSlug(pathname: string): string | null {
  const match = pathname.match(/^\/(?:administration|modules)\/dashboard\/([^/]+)$/)
  if (!match) return null
  if (['actions', 'modules'].includes(match[1])) return null
  return match[1]
}

export function activeNavItem(pathname: string, items: ModuleNavItem[]): ModuleNavItem | null {
  const exact = items.find((item) => item.end && pathname === item.to)
  if (exact) return exact
  return [...items]
    .filter((item) => !item.end && (pathname === item.to || pathname.startsWith(`${item.to}/`)))
    .sort((a, b) => b.to.length - a.to.length)[0] ?? null
}

export function activeModuleItem(pathname: string, module: ModuleDefinition): ModuleNavItem | null {
  return activeNavItem(pathname, module.items)
}
