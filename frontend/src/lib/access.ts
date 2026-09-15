import type { User } from '../types'

export const DASHBOARD_PERMS = ['dashboard.group', 'dashboard.company']

export const SETTINGS_PERMS = [
  'settings.manage',
  'organization.view',
  'companies.view',
  'roles.manage',
  'roles.view',
]

export function canAccessModule(user: User | null | undefined, slug: string) {
  if (!user) return false
  if (user.is_super_admin || user.is_group_admin) return true
  if (user.accessible_module_slugs?.includes(slug)) return true
  return (user.permissions ?? []).some((perm) => perm === slug || perm.startsWith(`${slug}.`))
}
