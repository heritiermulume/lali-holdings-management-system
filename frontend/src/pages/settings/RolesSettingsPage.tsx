import {
  Check,
  ChevronDown,
  Minus,
  Search,
  Shield,
  ShieldCheck,
} from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import { useSystemModules } from '../../context/ModulesContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { trackLoadSpinner } from '../../lib/dataSync'
import { cn } from '../../lib/cn'
import { permissionLabel, roleLabel } from '../../lib/format'
import { businessModuleIcon } from '../../modules/registry'
import type { SystemModule } from '../../types'
import {
  buildPermissionTree,
  collectPermissions,
  filterTreeByGates,
  filterTreeByQuery,
  isPermissionEnabled,
  isPermissionGatedOff,
  setSectionPermissionIds,
  togglePermissionIds,
  type Permission,
  type PermissionNode,
  type PermissionSection,
} from './rolePermissionTree'

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

function isPlatformRole(role: RoleRow) {
  return role.full_access || !role.module_slug || role.module_slug === 'system'
}

function moduleTitle(module: SystemModule | undefined, slug: string, localeFr: boolean, t: (key: string) => string) {
  if (module) return localeFr ? module.name_fr : module.name
  return t(`nav.${slug}`)
}

function sectionStats(section: PermissionSection, permissionIds: number[], allVisible: Permission[]) {
  const visible = section.permissions.filter(
    (permission) => !isPermissionGatedOff(permissionIds, permission, allVisible),
  )
  const total = visible.length
  const enabled = visible.filter((permission) => isPermissionEnabled(permissionIds, permission, allVisible)).length
  return { total, enabled, all: total > 0 && enabled === total, some: enabled > 0 && enabled < total }
}

function PermissionToggle({
  checked,
  disabled,
  onChange,
}: {
  checked: boolean
  disabled?: boolean
  onChange: () => void
}) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={checked}
      disabled={disabled}
      onClick={onChange}
      className={cn(
        'grid h-5 w-5 shrink-0 place-items-center rounded-md border transition',
        checked ? 'border-lhms bg-lhms text-white' : 'border-line bg-white text-transparent',
        disabled && 'cursor-not-allowed opacity-40',
        !disabled && !checked && 'hover:border-lhms/50',
      )}
    >
      <Check size={12} strokeWidth={3} />
    </button>
  )
}

function SectionCheckbox({
  all,
  some,
  disabled,
  onChange,
}: {
  all: boolean
  some: boolean
  disabled?: boolean
  onChange: () => void
}) {
  return (
    <button
      type="button"
      role="checkbox"
      aria-checked={all ? true : some ? 'mixed' : false}
      disabled={disabled}
      onClick={onChange}
      className={cn(
        'grid h-5 w-5 shrink-0 place-items-center rounded-md border transition',
        all || some ? 'border-lhms bg-lhms text-white' : 'border-line bg-white text-transparent',
        disabled && 'cursor-not-allowed opacity-40',
      )}
    >
      {all ? <Check size={12} strokeWidth={3} /> : some ? <Minus size={12} strokeWidth={3} /> : null}
    </button>
  )
}

export function RolesSettingsPage() {
  const { t, i18n } = useTranslation()
  const { modules } = useSystemModules()
  const { user, hasPermission, refresh } = useAuth()
  const localeFr = i18n.language.startsWith('fr')
  const [roles, setRoles] = useState<RoleRow[]>([])
  const [permissionGroups, setPermissionGroups] = useState<Record<string, Permission[]>>({})
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [permissionIds, setPermissionIds] = useState<number[]>([])
  const [openModules, setOpenModules] = useState<string[]>(['system', 'kams'])
  const [openSections, setOpenSections] = useState<string[]>(['platform', 'administration', 'kams'])
  const [query, setQuery] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)

  const selected = roles.find((role) => role.id === selectedId) ?? null
  const canGrantAll = Boolean(user?.is_super_admin || user?.is_group_admin)
  const canSeePermission = (slug: string) => canGrantAll || hasPermission(slug)

  const load = () => {
    const stop = trackLoadSpinner(setLoading)
    return Promise.all([
      api.get('/roles'),
      api.get('/permissions'),
    ]).then(([rolesRes, permsRes]) => {
      const nextRoles = (rolesRes.data.data ?? []) as RoleRow[]
      setRoles(nextRoles)
      setPermissionGroups(permsRes.data.data ?? {})
      const first = nextRoles[0]
      setSelectedId((current) => current ?? first?.id ?? null)
      setOpenModules((current) => current.includes('system') ? current : ['system', ...current])
    }).finally(stop)
  }

  useEffect(() => { void load() }, [])

  const moduleSections = useMemo(() => {
    const known = [...modules].sort((a, b) => a.sort_order - b.sort_order)
    return known.map((module) => ({
      module,
      roles: roles.filter((role) => !isPlatformRole(role) && role.module_slug === module.slug),
    }))
  }, [modules, roles])

  const platformRoles = useMemo(() => roles.filter(isPlatformRole), [roles])

  const relevantPermissionGroups = useMemo(() => {
    const moduleSlug = selected?.module_slug
    if (!selected || selected.full_access || !moduleSlug) return {}

    const hasAreas = Object.keys(permissionGroups).some((group) => group.startsWith(`${moduleSlug}.`))
    const entries = Object.entries(permissionGroups).filter(([group]) => (
      group === 'administration'
      || group === moduleSlug
      || (hasAreas ? group.startsWith(`${moduleSlug}.`) : false)
      || ['notifications'].includes(group)
    ))

    return Object.fromEntries(entries)
  }, [permissionGroups, selected])

  const permissionTree = useMemo(() => {
    if (!selected || selected.full_access) return []
    return buildPermissionTree(relevantPermissionGroups, selected.module_slug, canSeePermission)
  }, [relevantPermissionGroups, selected, canGrantAll, user?.permissions])

  const sectionsOpenedForRole = useRef<number | null>(null)
  useEffect(() => {
    if (!selectedId || permissionTree.length === 0) return
    if (sectionsOpenedForRole.current === selectedId) return
    sectionsOpenedForRole.current = selectedId
    const keys: string[] = []
    for (const root of permissionTree) {
      keys.push(root.key)
      for (const child of root.children) {
        if (child.kind === 'section') keys.push(child.key)
      }
    }
    setOpenSections(keys)
  }, [selectedId, permissionTree])

  const allVisiblePermissions = useMemo(
    () => collectPermissions(permissionTree),
    [permissionTree],
  )

  const visibleTree = useMemo(() => {
    const searched = filterTreeByQuery(
      permissionTree,
      query,
      (permission) => permissionLabel(t, permission),
      (node) => t(node.labelKey, { defaultValue: node.labelDefault }),
    )
    return filterTreeByGates(searched, permissionIds, allVisiblePermissions)
  }, [permissionTree, query, t, permissionIds, allVisiblePermissions])

  const dirty = useMemo(() => {
    if (!selected) return false
    const visibleIds = new Set(allVisiblePermissions.map((permission) => permission.id))
    const currentVisible = permissionIds.filter((id) => visibleIds.has(id)).sort().join(',')
    const savedVisible = selected.permissions
      .map((permission) => permission.id)
      .filter((id) => visibleIds.has(id))
      .sort()
      .join(',')
    return currentVisible !== savedVisible
  }, [selected, permissionIds, allVisiblePermissions])

  useEffect(() => {
    const role = roles.find((entry) => entry.id === selectedId)
    if (!role || dirty) return
    setPermissionIds(role.permissions.map((permission) => permission.id))
    const group = isPlatformRole(role) ? 'system' : role.module_slug
    if (group) {
      setOpenModules((current) => current.includes(group) ? current : [...current, group])
    }
  }, [selectedId, roles, dirty])

  const grantedCount = useMemo(
    () => allVisiblePermissions.filter((permission) => isPermissionEnabled(permissionIds, permission, allVisiblePermissions)).length,
    [allVisiblePermissions, permissionIds],
  )

  const togglePermission = (permission: Permission) => {
    setPermissionIds((current) => togglePermissionIds(current, permission, allVisiblePermissions))
  }

  const toggleSection = (section: PermissionSection) => {
    const stats = sectionStats(section, permissionIds, allVisiblePermissions)
    setPermissionIds((current) => setSectionPermissionIds(current, section.permissions, !stats.all, allVisiblePermissions))
  }

  const toggleModule = (slug: string) => {
    setOpenModules((current) => current.includes(slug) ? current.filter((item) => item !== slug) : [...current, slug])
  }

  const toggleOpenSection = (key: string) => {
    setOpenSections((current) => current.includes(key) ? current.filter((item) => item !== key) : [...current, key])
  }

  const expandAllSections = () => {
    const keys: string[] = []
    const walk = (nodes: PermissionNode[]) => {
      for (const node of nodes) {
        if (node.kind === 'section') {
          keys.push(node.key)
          walk(node.children)
        }
      }
    }
    walk(permissionTree)
    setOpenSections(keys)
  }

  const collapseAllSections = () => {
    setOpenSections(permissionTree.map((node) => node.key))
  }

  const selectRole = (role: RoleRow) => {
    setSelectedId(role.id)
    setPermissionIds(role.permissions.map((permission) => permission.id))
    setQuery('')
  }

  useLiveData(load, { enabled: !dirty && !saving })

  const save = async () => {
    if (!selected || !dirty) return
    setSaving(true)
    try {
      const visibleIds = new Set(allVisiblePermissions.map((permission) => permission.id))
      const preserved = selected.permissions
        .map((permission) => permission.id)
        .filter((id) => !visibleIds.has(id))
      const nextIds = [...new Set([
        ...preserved,
        ...permissionIds.filter((id) => visibleIds.has(id)),
      ])]

      const res = await api.put(`/roles/${selected.id}`, {
        permission_ids: selected.full_access
          ? selected.permissions.map((permission) => permission.id)
          : nextIds,
      })
      const updated = res.data.data as RoleRow
      setRoles((current) => current.map((role) => (role.id === updated.id ? { ...role, ...updated } : role)))
      // Apply new grants immediately for the current session (and hide revoked UI actions).
      await refresh().catch(() => {})
    } finally {
      setSaving(false)
    }
  }

  const renderNode = (node: PermissionNode, depth = 0): ReactNode => {
    if (node.kind === 'leaf') {
      const checked = isPermissionEnabled(permissionIds, node.permission, allVisiblePermissions)
      return (
        <label
          key={node.key}
          className={cn(
            'flex min-h-10 items-center gap-3 rounded-xl px-3 py-1.5 text-sm transition hover:bg-lhms/5',
            node.indent && 'ml-4',
          )}
        >
          <PermissionToggle
            checked={checked}
            onChange={() => togglePermission(node.permission)}
          />
          <span className="min-w-0 flex-1 leading-snug">{permissionLabel(t, node.permission)}</span>
        </label>
      )
    }

    const open = openSections.includes(node.key) || Boolean(query.trim())
    const stats = sectionStats(node, permissionIds, allVisiblePermissions)
    const isRoot = depth === 0
    const gate = node.gateSlug
      ? allVisiblePermissions.find((permission) => permission.slug === node.gateSlug) ?? null
      : null
    const gateOn = !gate || permissionIds.includes(gate.id)

    return (
      <div
        key={node.key}
        className={cn(
          isRoot
            ? 'overflow-hidden rounded-2xl border border-line bg-white'
            : 'overflow-hidden rounded-xl border border-line/80 bg-white',
        )}
      >
        <div
          className={cn(
            'flex items-center gap-2 border-b border-line/60 px-3',
            isRoot ? 'bg-lhms/[0.08] py-3' : 'bg-surface py-2.5',
          )}
        >
          <SectionCheckbox
            all={stats.all}
            some={stats.some}
            onChange={() => {
              if (gate && !gateOn) {
                setPermissionIds((current) => {
                  const withGate = togglePermissionIds(current, gate, allVisiblePermissions)
                  return setSectionPermissionIds(withGate, node.permissions, true, allVisiblePermissions)
                })
                return
              }
              toggleSection(node)
            }}
          />
          <button
            type="button"
            onClick={() => toggleOpenSection(node.key)}
            className="flex min-w-0 flex-1 items-center gap-2 text-left"
          >
            {isRoot ? (
              <span className="grid h-8 w-8 place-items-center rounded-xl bg-lhms text-white">
                <ShieldCheck size={15} />
              </span>
            ) : null}
            <span className={cn('min-w-0 flex-1 truncate', isRoot ? 'text-sm font-bold' : 'text-sm font-semibold')}>
              {t(node.labelKey, { defaultValue: node.labelDefault })}
            </span>
            <span className={cn(
              'rounded-full px-2 py-0.5 text-[11px] font-semibold tabular-nums',
              stats.enabled > 0 ? 'bg-lhms-soft text-lhms' : 'bg-white text-muted',
            )}>
              {stats.enabled}/{stats.total}
            </span>
            <ChevronDown size={16} className={cn('shrink-0 text-muted transition', open ? 'rotate-0' : '-rotate-90')} />
          </button>
        </div>
        {open ? (
          <div className={cn('space-y-2', isRoot ? 'p-3' : 'px-2 pb-2 pt-2')}>
            {node.children.map((child) => renderNode(child, depth + 1))}
          </div>
        ) : null}
      </div>
    )
  }

  if (loading) {
    return <LoadingScreen />
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
          <Card className="overflow-hidden p-0">
            <div className="border-b border-line bg-gradient-to-br from-lhms/[0.07] via-white to-white px-4 py-5 sm:px-6">
              <div className="text-xs font-semibold uppercase tracking-wide text-muted">
                {isPlatformRole(selected)
                  ? t('settings.rolesAllModules')
                  : moduleTitle(modules.find((entry) => entry.slug === selected.module_slug), selected.module_slug || '', localeFr, t)}
              </div>
              <div className="mt-1 flex flex-wrap items-end justify-between gap-3">
                <div>
                  <h2 className="text-xl font-bold tracking-tight">{roleLabel(t, selected)}</h2>
                  <p className="mt-1 max-w-2xl text-sm text-muted">
                    {selected.full_access
                      ? t(selected.slug === 'president' ? 'settings.rolesPresidentHint' : 'settings.rolesFullAccessHint')
                      : selected.office_scoped
                        ? t('settings.rolesOfficeScopedHint')
                        : t('settings.rolesModuleHint')}
                  </p>
                </div>
                {!selected.full_access ? (
                  <div className="rounded-2xl border border-lhms/15 bg-white px-3 py-2 text-right shadow-sm">
                    <div className="text-[11px] font-semibold uppercase tracking-wide text-muted">{t('settings.rolesPermissions')}</div>
                    <div className="text-lg font-extrabold tabular-nums text-lhms">
                      {grantedCount}
                      <span className="text-sm font-semibold text-muted">/{allVisiblePermissions.length}</span>
                    </div>
                  </div>
                ) : null}
              </div>
            </div>
          </Card>

          <Card className="p-4 sm:p-6">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h3 className="text-sm font-bold">{t('settings.rolesPermissions')}</h3>
                <p className="mt-0.5 text-xs text-muted">{t('settings.rolesPermissionsHint')}</p>
              </div>
              {!selected.full_access && allVisiblePermissions.length > 0 ? (
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="ghost" className="min-h-9 px-3 text-xs" onClick={expandAllSections}>
                    {t('settings.rolesExpandAll')}
                  </Button>
                  <Button type="button" variant="ghost" className="min-h-9 px-3 text-xs" onClick={collapseAllSections}>
                    {t('settings.rolesCollapseAll')}
                  </Button>
                </div>
              ) : null}
            </div>

            {selected.full_access ? (
              <div className="mt-4 rounded-2xl border border-dashed border-lhms/25 bg-lhms/[0.03] px-4 py-8 text-center">
                <div className="mx-auto grid h-12 w-12 place-items-center rounded-2xl bg-lhms text-white">
                  <Shield size={20} />
                </div>
                <p className="mt-3 text-sm font-medium text-ink">{t('settings.rolesPermissionsLocked')}</p>
              </div>
            ) : (
              <>
                <div className="relative mt-4">
                  <Search size={16} className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
                  <input
                    type="search"
                    value={query}
                    onChange={(event) => setQuery(event.target.value)}
                    placeholder={t('settings.rolesSearchPermissions')}
                    className="min-h-11 w-full rounded-xl border border-line bg-white py-2 pl-10 pr-3 text-sm outline-none ring-lhms/20 focus:border-lhms focus:ring-4"
                  />
                </div>

                {visibleTree.length === 0 ? (
                  <p className="mt-6 text-center text-sm text-muted">{t('settings.rolesNoVisiblePermissions')}</p>
                ) : (
                  <div className="mt-4 space-y-3">
                    {visibleTree.map((node) => renderNode(node))}
                  </div>
                )}
              </>
            )}
          </Card>

          <div className="sticky bottom-20 z-10 flex justify-end lg:bottom-4">
            <Button type="button" disabled={!dirty || saving} onClick={() => void save()} className="shadow-lg shadow-lhms/20">
              {saving ? t('common.loading') : dirty ? t('common.save') : t('common.saved')}
            </Button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
