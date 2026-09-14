import type { LucideIcon } from 'lucide-react'
import {
  Bell,
  Building2,
  Crown,
  Globe2,
  LayoutDashboard,
  ListChecks,
  Megaphone,
  Package,
  ScrollText,
  Settings,
  ShoppingBag,
  Truck,
  Tv,
  Users,
  Wheat,
} from 'lucide-react'

export type ModuleNavItem = {
  to: string
  labelKey: string
  descriptionKey: string
  icon: LucideIcon
  end?: boolean
  perm?: string
}

export type ModuleDefinition = {
  slug: string
  nameKey: string
  titleKey: string
  icon: LucideIcon
  items: ModuleNavItem[]
}

export const moduleRegistry: Record<string, ModuleDefinition> = {
  dashboard: {
    slug: 'dashboard',
    nameKey: 'nav.dashboard',
    titleKey: 'groupDash.title',
    icon: LayoutDashboard,
    items: [
      { to: '/modules/dashboard', labelKey: 'groupDash.overview', descriptionKey: 'groupDash.overviewHint', icon: LayoutDashboard, end: true },
      { to: '/modules/dashboard/actions', labelKey: 'groupDash.actions', descriptionKey: 'groupDash.actionsHint', icon: ListChecks },
    ],
  },
  kams: {
    slug: 'kams',
    nameKey: 'nav.kams',
    titleKey: 'kams.title',
    icon: Crown,
    items: [
      { to: '/modules/kams', labelKey: 'nav.dashboard', descriptionKey: 'kams.dashboardHint', icon: LayoutDashboard, end: true },
      { to: '/modules/kams/clients', labelKey: 'kams.clients', descriptionKey: 'kams.clientsHint', icon: Users },
      { to: '/modules/kams/products', labelKey: 'kams.products', descriptionKey: 'kams.productsHint', icon: Package },
      { to: '/modules/kams/orders', labelKey: 'kams.orders', descriptionKey: 'kams.ordersHint', icon: ShoppingBag },
      { to: '/modules/kams/campaigns', labelKey: 'kams.campaigns', descriptionKey: 'kams.campaignsHint', icon: Megaphone },
    ],
  },
  users: {
    slug: 'users',
    nameKey: 'nav.users',
    titleKey: 'nav.users',
    icon: Users,
    items: [
      { to: '/users', labelKey: 'nav.users', descriptionKey: 'users.subtitle', icon: Users, end: true, perm: 'users.view' },
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
      { to: '/audit', labelKey: 'nav.audit', descriptionKey: 'audit.subtitle', icon: ScrollText, end: true, perm: 'audit.view' },
    ],
  },
  settings: {
    slug: 'settings',
    nameKey: 'nav.settings',
    titleKey: 'nav.settings',
    icon: Settings,
    items: [
      { to: '/organization', labelKey: 'nav.organization', descriptionKey: 'settings.organizationHint', icon: Globe2, end: true, perm: 'organization.view' },
      { to: '/companies', labelKey: 'nav.companies', descriptionKey: 'settings.companiesHint', icon: Building2, perm: 'companies.view' },
    ],
  },
}

const platformPrefixes: Array<[string, string]> = [
  ['/users', 'users'],
  ['/notifications', 'notifications'],
  ['/audit', 'audit'],
  ['/settings', 'settings'],
  ['/organization', 'settings'],
  ['/companies', 'settings'],
]

export function moduleFromPath(pathname: string): ModuleDefinition | null {
  const match = pathname.match(/^\/modules\/([^/]+)/)
  if (match) return moduleRegistry[match[1]] ?? null
  const found = platformPrefixes.find(([prefix]) => pathname === prefix || pathname.startsWith(`${prefix}/`))
  return found ? moduleRegistry[found[1]] ?? null : null
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
  const match = pathname.match(/^\/modules\/dashboard\/([^/]+)$/)
  if (!match) return null
  if (['actions', 'modules'].includes(match[1])) return null
  return match[1]
}

export function activeModuleItem(pathname: string, module: ModuleDefinition): ModuleNavItem | null {
  const exact = module.items.find((item) => item.end && pathname === item.to)
  if (exact) return exact
  return [...module.items]
    .filter((item) => !item.end && (pathname === item.to || pathname.startsWith(`${item.to}/`)))
    .sort((a, b) => b.to.length - a.to.length)[0] ?? null
}
