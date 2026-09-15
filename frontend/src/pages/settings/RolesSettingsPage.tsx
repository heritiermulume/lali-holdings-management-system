import { ChevronDown, Shield } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card } from '../../components/ui'
import { useSystemModules } from '../../context/ModulesContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { permissionLabel, roleLabel } from '../../lib/format'
import { businessModuleIcon } from '../../modules/registry'
import type { SystemModule } from '../../types'

type Permission = { id: number; slug: string; name: string; group: string }
type RoleRow = {
  id: number
  name: string
  slug: string
  module_slug?: string | null
  description?: string | null
  full_access: boolean
  office_scoped?: boolean
  permissions: Permission[]
}

const BUSINESS_MODULES = ['kams', 'heavy-equipment', 'real-estate', 'rice-mills', 'lali-tv']

const MODULE_AREA_ORDER: Record<string, string[]> = {
  kams: ['kams.dashboard', 'kams.members', 'kams.recruitment', 'kams.clients', 'kams.products', 'kams.orders', 'kams.campaigns'],
}

function isPlatformRole(role: RoleRow) {
  return role.full_access || !role.module_slug || role.module_slug === 'system'
}

function permissionAction(slug: string) {
  return slug.split('.').at(-1) || ''
}

function isViewPermission(permission: Permission) {
  return permissionAction(permission.slug) === 'view'
}

function moduleTitle(module: SystemModule | undefined, slug: string, localeFr: boolean, t: (key: string) => string) {
  if (module) return localeFr ? module.name_fr : module.name
  return t(`nav.${slug}`)
}

export function RolesSettingsPage() {
  const { t, i18n } = useTranslation()
  const { modules } = useSystemModules()
  const localeFr = i18n.language.startsWith('fr')
  const [roles, setRoles] = useState<RoleRow[]>([])
  const [permissionGroups, setPermissionGroups] = useState<Record<string, Permission[]>>({})
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [permissionIds, setPermissionIds] = useState<number[]>([])
  const [openModules, setOpenModules] = useState<string[]>(['system', 'kams'])
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  const selected = roles.find((role) => role.id === selectedId) ?? null

  const load = () => {
    setLoading(true)
    Promise.all([
      api.get('/roles'),
      api.get('/permissions'),
    ]).then(([rolesRes, permsRes]) => {
      const nextRoles = (rolesRes.data.data ?? []) as RoleRow[]
      setRoles(nextRoles)
      setPermissionGroups(permsRes.data.data ?? {})
      const first = nextRoles[0]
      setSelectedId((current) => current ?? first?.id ?? null)
      setOpenModules((current) => current.includes('system') ? current : ['system', ...current])
    }).finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [])

  useEffect(() => {
    const role = roles.find((entry) => entry.id === selectedId)
    if (!role) return
    setPermissionIds(role.permissions.map((permission) => permission.id))
    const group = isPlatformRole(role) ? 'system' : role.module_slug
    if (group) {
      setOpenModules((current) => current.includes(group) ? current : [...current, group])
    }
  }, [selectedId, roles])

  const moduleSections = useMemo(() => {
    const known = modules.length > 0
      ? [...modules].sort((a, b) => a.sort_order - b.sort_order)
      : BUSINESS_MODULES.map((slug) => ({ slug, name: slug, name_fr: slug, sort_order: 0 }) as SystemModule)

    return known.map((module) => ({
      module,
      roles: roles.filter((role) => !isPlatformRole(role) && role.module_slug === module.slug),
    }))
  }, [modules, roles])

  const platformRoles = useMemo(() => roles.filter(isPlatformRole), [roles])

  const visiblePermissionGroups = useMemo(() => {
    const moduleSlug = selected?.module_slug
    if (!selected || selected.full_access || !moduleSlug) {
      return {}
    }
    const hasAreas = Object.keys(permissionGroups).some((group) => group.startsWith(`${moduleSlug}.`))
    const entries = Object.entries(permissionGroups).filter(([group]) => (
      hasAreas ? group.startsWith(`${moduleSlug}.`) : group === moduleSlug
    ))
    const order = MODULE_AREA_ORDER[moduleSlug] ?? []
    entries.sort((a, b) => {
      const indexA = order.indexOf(a[0])
      const indexB = order.indexOf(b[0])
      return (indexA === -1 ? 99 : indexA) - (indexB === -1 ? 99 : indexB)
    })
    return Object.fromEntries(entries)
  }, [permissionGroups, selected])

  const dirty = useMemo(() => {
    if (!selected) return false
    const currentPerms = [...permissionIds].sort().join(',')
    const savedPerms = selected.permissions.map((permission) => permission.id).sort().join(',')
    return currentPerms !== savedPerms
  }, [selected, permissionIds])

  const togglePermission = (group: Permission[], permission: Permission) => {
    const view = group.find(isViewPermission)
    const groupIds = group.map((item) => item.id)

    setPermissionIds((current) => {
      const enabled = current.includes(permission.id)
      if (isViewPermission(permission)) {
        if (enabled) {
          return current.filter((id) => !groupIds.includes(id))
        }
        return [...current, permission.id]
      }
      if (!view || !current.includes(view.id)) {
        return current
      }
      return enabled ? current.filter((id) => id !== permission.id) : [...current, permission.id]
    })
  }

  const toggleModule = (slug: string) => {
    setOpenModules((current) => current.includes(slug) ? current.filter((item) => item !== slug) : [...current, slug])
  }

  const selectRole = (role: RoleRow) => {
    setSelectedId(role.id)
    setPermissionIds(role.permissions.map((permission) => permission.id))
  }

  const save = async () => {
    if (!selected || !dirty) return
    setSaving(true)
    try {
      const res = await api.put(`/roles/${selected.id}`, {
        permission_ids: selected.full_access ? selected.permissions.map((permission) => permission.id) : permissionIds,
      })
      const updated = res.data.data as RoleRow
      setRoles((current) => current.map((role) => (role.id === updated.id ? { ...role, ...updated } : role)))
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-muted">{t('common.loading')}</p>
  }

  return (
    <div className="grid gap-4 lg:grid-cols-[280px_minmax(0,1fr)]">
      <Card className="p-2">
        <div className="space-y-2">
          <div className="rounded-2xl border border-lhms/30 bg-lhms/5">
            <div className="flex w-full items-center gap-2 px-3 py-2.5 text-left">
              <Shield size={16} className="shrink-0 text-lhms" />
              <span className="min-w-0 flex-1 truncate text-sm font-bold">{t('settings.rolesAllModules')}</span>
              <span className="text-[11px] font-semibold uppercase tracking-wide text-lhms">{t('settings.rolesAllAccess')}</span>
            </div>
            <div className="space-y-1 px-2 pb-2">
              {platformRoles.map((role) => (
                <button
                  key={role.id}
                  type="button"
                  onClick={() => selectRole(role)}
                  className={cn(
                    'flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm',
                    selectedId === role.id ? 'bg-lhms text-white' : 'hover:bg-white',
                  )}
                >
                  <span className="font-medium">{roleLabel(t, role)}</span>
                </button>
              ))}
            </div>
          </div>
          {moduleSections.map(({ module, roles: moduleRoles }) => {
            const open = openModules.includes(module.slug)
            const Icon = businessModuleIcon(module.slug)
            return (
              <div key={module.slug} className="rounded-2xl bg-surface/70">
                <button
                  type="button"
                  onClick={() => toggleModule(module.slug)}
                  className="flex w-full items-center gap-2 px-3 py-2.5 text-left"
                >
                  <Icon size={16} className="shrink-0 text-lhms" />
                  <span className="min-w-0 flex-1 truncate text-sm font-bold">{moduleTitle(module, module.slug, localeFr, t)}</span>
                  <span className="text-[11px] text-muted">{moduleRoles.length}</span>
                  <ChevronDown size={16} className={cn('shrink-0 text-muted transition', open ? 'rotate-0' : '-rotate-90')} />
                </button>
                {open ? (
                  <div className="space-y-1 px-2 pb-2">
                    {moduleRoles.length === 0 ? (
                      <p className="px-3 py-2 text-xs text-muted">{t('settings.rolesEmptyModule')}</p>
                    ) : moduleRoles.map((role) => (
                      <button
                        key={role.id}
                        type="button"
                        onClick={() => selectRole(role)}
                        className={cn(
                          'flex w-full items-center justify-between rounded-xl px-3 py-2 text-left text-sm',
                          selectedId === role.id ? 'bg-lhms text-white' : 'hover:bg-white',
                        )}
                      >
                        <span className="font-medium">{roleLabel(t, role)}</span>
                        {role.full_access ? <span className="ml-2 text-[10px] uppercase tracking-wide opacity-80">{t('settings.rolesAllAccess')}</span> : null}
                      </button>
                    ))}
                  </div>
                ) : null}
              </div>
            )
          })}
        </div>
      </Card>

      {selected ? (
        <div className="space-y-4">
          <Card className="p-4 sm:p-6">
            <div className="text-xs font-semibold uppercase tracking-wide text-muted">
              {isPlatformRole(selected)
                ? t('settings.rolesAllModules')
                : moduleTitle(modules.find((entry) => entry.slug === selected.module_slug), selected.module_slug || '', localeFr, t)}
            </div>
            <h2 className="mt-1 text-base font-bold">{roleLabel(t, selected)}</h2>
            <p className="mt-1 text-sm text-muted">
              {selected.full_access
                ? t('settings.rolesFullAccessHint')
                : selected.office_scoped
                  ? t('settings.rolesOfficeScopedHint')
                  : t('settings.rolesModuleHint')}
            </p>
          </Card>

          <Card className="p-4 sm:p-6">
            <h3 className="text-sm font-bold">{t('settings.rolesPermissions')}</h3>
            {selected.full_access ? (
              <p className="mt-2 text-sm text-muted">{t('settings.rolesPermissionsLocked')}</p>
            ) : (
              <div className="mt-3 grid gap-4 sm:grid-cols-2">
                {Object.entries(visiblePermissionGroups).map(([group, permissions]) => (
                  <fieldset key={group} className="rounded-2xl border border-line p-3">
                    <legend className="px-1 text-xs font-semibold uppercase tracking-wide text-muted">
                      {t(`settings.permissionGroups.${group}`, { defaultValue: group })}
                    </legend>
                    <div className="space-y-1">
                      {permissions.map((permission) => {
                        const view = permissions.find(isViewPermission)
                        const viewOn = !view || permissionIds.includes(view.id)
                        const dependent = Boolean(view) && !isViewPermission(permission)
                        const checked = permissionIds.includes(permission.id) && (!dependent || viewOn)
                        return (
                        <label key={permission.id} className={cn('flex min-h-9 items-center gap-2 text-sm', dependent && !viewOn && 'text-muted')}>
                          <input
                            type="checkbox"
                            checked={checked}
                            disabled={dependent && !viewOn}
                            onChange={() => togglePermission(permissions, permission)}
                          />
                          {permissionLabel(t, permission)}
                        </label>
                        )
                      })}
                    </div>
                  </fieldset>
                ))}
              </div>
            )}
          </Card>

          <div className="flex justify-end">
            <Button type="button" disabled={!dirty || saving} onClick={() => void save()}>
              {saving ? t('common.loading') : dirty ? t('common.save') : t('common.saved')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
