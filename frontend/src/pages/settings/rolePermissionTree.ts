export type Permission = { id: number; slug: string; name: string; group: string }

export type PermissionLeaf = {
  kind: 'leaf'
  key: string
  permission: Permission
  indent?: boolean
}

export type PermissionSection = {
  kind: 'section'
  key: string
  labelKey: string
  labelDefault: string
  gateSlug?: string
  children: PermissionNode[]
  permissions: Permission[]
}

export type PermissionNode = PermissionLeaf | PermissionSection

const ACTION_ORDER = [
  'view', 'create', 'deposit', 'withdraw', 'expense', 'update', 'delete',
  'import', 'export', 'print', 'preview', 'activate', 'approve',
  'receive', 'transfer', 'supply', 'remove', 'adjust', 'price', 'cancel', 'manage',
]

const ADMIN_VIEW_SLUG = 'administration.view'
const DASHBOARD_VIEW_SLUG = 'kams.dashboard.view'
const PLATFORM_GROUPS = ['notifications'] as const

const KAMS_PAGE_ORDER = ['dashboard', 'members', 'stock', 'finance', 'clients', 'patrimoine'] as const

export function permissionAction(slug: string) {
  return slug.split('.').at(-1) || ''
}

export function permissionPrefix(slug: string) {
  return slug.split('.').slice(0, -1).join('.')
}

export function isViewPermission(permission: Permission) {
  return permissionAction(permission.slug) === 'view'
}

function sortByAction(permissions: Permission[]) {
  return [...permissions].sort((a, b) => {
    const indexA = ACTION_ORDER.indexOf(permissionAction(a.slug))
    const indexB = ACTION_ORDER.indexOf(permissionAction(b.slug))
    return (indexA === -1 ? 99 : indexA) - (indexB === -1 ? 99 : indexB)
  })
}

function byPrefix(permissions: Permission[], prefix: string) {
  return sortByAction(permissions.filter((item) => permissionPrefix(item.slug) === prefix))
}

function byExact(permissions: Permission[], slug: string) {
  return permissions.filter((item) => item.slug === slug)
}

function byGroup(permissions: Permission[], group: string) {
  return sortByAction(permissions.filter((item) => item.group === group))
}

function byGroupPrefix(permissions: Permission[], prefix: string) {
  return sortByAction(permissions.filter((item) => item.group === prefix || item.group.startsWith(`${prefix}.`)))
}

function leaf(permission: Permission, indent = false): PermissionLeaf {
  return { kind: 'leaf', key: permission.slug, permission, indent }
}

function leaves(permissions: Permission[], indent = false): PermissionLeaf[] {
  return permissions.map((permission) => leaf(permission, indent))
}

function section(
  key: string,
  labelKey: string,
  labelDefault: string,
  children: PermissionNode[],
  options?: { gateSlug?: string },
): PermissionSection | null {
  const nested = children.filter(Boolean) as PermissionNode[]
  if (nested.length === 0) return null
  return {
    kind: 'section',
    key,
    labelKey,
    labelDefault,
    gateSlug: options?.gateSlug,
    children: nested,
    permissions: collectPermissions(nested),
  }
}

export function collectPermissions(nodes: PermissionNode[]): Permission[] {
  const result: Permission[] = []
  for (const node of nodes) {
    if (node.kind === 'leaf') result.push(node.permission)
    else result.push(...node.permissions)
  }
  return result
}

function actionLeaves(permissions: Permission[], prefix: string, indentView = false): PermissionLeaf[] {
  const items = byPrefix(permissions, prefix)
  return items.map((permission) => leaf(permission, indentView && !isViewPermission(permission)))
}

function actionLeavesOnly(
  permissions: Permission[],
  prefix: string,
  actions: string[],
  indentView = false,
): PermissionLeaf[] {
  const allowed = new Set(actions)
  const items = byPrefix(permissions, prefix).filter((item) => allowed.has(permissionAction(item.slug)))
  return items.map((permission) => leaf(permission, indentView && !isViewPermission(permission)))
}

function pageSection(
  key: string,
  labelKey: string,
  labelDefault: string,
  viewLeaves: PermissionLeaf[],
  optionLeaves: PermissionNode[] = [],
): PermissionSection | null {
  return section(key, labelKey, labelDefault, [
    ...viewLeaves,
    ...optionLeaves,
  ])
}

function buildAdministrationTree(permissions: Permission[]): PermissionSection | null {
  const admin = byGroup(permissions, 'administration')
  if (admin.length === 0) return null

  const gate = byExact(admin, ADMIN_VIEW_SLUG)
  const users = actionLeaves(admin, 'users', true)
  const settingsChildren = [
    section('settings.general', 'settings.general', 'General', leaves(byExact(admin, 'settings.manage'))),
    section('settings.organization', 'nav.organization', 'Organization', actionLeaves(admin, 'organization', true)),
    section('settings.companies', 'nav.companies', 'Companies', actionLeaves(admin, 'companies', true)),
    section('settings.roles', 'nav.roles', 'Roles', actionLeaves(admin, 'roles', true)),
  ].filter(Boolean) as PermissionNode[]

  const children = [
    ...leaves(gate),
    section('admin.users', 'settings.permissionGroups.administrationPages.users', 'Users', users),
    section('admin.settings', 'settings.permissionGroups.administrationPages.settings', 'Settings', settingsChildren),
  ].filter(Boolean) as PermissionNode[]

  const covered = new Set(collectPermissions(children).map((permission) => permission.id))
  const leftovers = admin.filter((permission) => !covered.has(permission.id))
  if (leftovers.length > 0) {
    const other = section('admin.other', 'settings.rolesOtherPermissions', 'Other', leaves(sortByAction(leftovers)))
    if (other) children.push(other)
  }

  return section('administration', 'settings.permissionGroups.administration', 'Administration', children, {
    gateSlug: ADMIN_VIEW_SLUG,
  })
}

function buildKamsTree(permissions: Permission[]): PermissionSection | null {
  const kams = byGroupPrefix(permissions, 'kams')
  if (kams.length === 0) return null

  const dashboardGate = byExact(kams, DASHBOARD_VIEW_SLUG)
  const dashboardOptions = [
    ...byExact(kams, 'kams.dashboard.products.view'),
    ...byExact(kams, 'kams.dashboard.orders.view'),
    ...byExact(kams, 'kams.dashboard.finance.view'),
    ...byExact(kams, 'kams.dashboard.recruitment.view'),
  ]

  const pages: Array<PermissionSection | null> = [
    section(
      'kams.dashboard',
      'settings.permissionGroups.kams.dashboard',
      'Dashboard',
      [
        ...leaves(dashboardGate),
        ...leaves(dashboardOptions, true),
      ],
      { gateSlug: DASHBOARD_VIEW_SLUG },
    ),
    section(
      'kams.members',
      'settings.permissionGroups.kams.members',
      'Members',
      [
        pageSection(
          'kams.members.statistics',
          'kams.statistics',
          'Statistics',
          leaves(byExact(kams, 'kams.members.statistics.view')),
        ),
        section(
          'kams.members.recruitment',
          'settings.permissionGroups.kams.recruitment',
          'Recruitments',
          [
            pageSection(
              'kams.members.recruitment.applications',
              'kams.applications',
              'Applications',
              leaves(byExact(kams, 'kams.recruitment.applications.view')),
              [
                ...actionLeavesOnly(kams, 'kams.recruitment', ['create', 'update', 'delete'], true),
                ...leaves(byExact(kams, 'kams.recruitment.interview.update'), true),
                ...leaves(byExact(kams, 'kams.recruitment.observation.update'), true),
              ],
            ),
            pageSection(
              'kams.members.recruitment.reports',
              'kams.recruitmentReports',
              'Reports',
              leaves(byExact(kams, 'kams.recruitment.reports.view')),
              actionLeavesOnly(kams, 'kams.recruitment.reports', ['print', 'preview'], true),
            ),
          ].filter(Boolean) as PermissionNode[],
        ),
        pageSection(
          'kams.members.agents',
          'kams.agents',
          'Agents',
          leaves(byExact(kams, 'kams.members.agents.view')),
          actionLeavesOnly(kams, 'kams.members', ['create', 'update', 'delete', 'import', 'export', 'print', 'preview', 'activate'], true),
        ),
      ].filter(Boolean) as PermissionNode[],
    ),
    section(
      'kams.stock',
      'kams.stock',
      'Stock',
      [
        pageSection(
          'kams.stock.statistics',
          'kams.statistics',
          'Statistics',
          leaves(byExact(kams, 'kams.stock.statistics.view')),
        ),
        pageSection(
          'kams.stock.orders',
          'kams.orders',
          'Orders',
          leaves(byExact(kams, 'kams.stock.orders.view')),
          actionLeavesOnly(kams, 'kams.stock.orders', ['create', 'update', 'delete', 'receive', 'cancel'], true),
        ),
        pageSection(
          'kams.stock.warehouses',
          'kams.warehouses',
          'Warehouses',
          leaves(byExact(kams, 'kams.stock.warehouses.view')),
          [
            ...actionLeavesOnly(kams, 'kams.stock.warehouses', ['create', 'update', 'delete', 'receive', 'transfer'], true),
            ...leaves(byExact(kams, 'kams.products.view'), true),
            ...actionLeavesOnly(kams, 'kams.products', ['create', 'update', 'delete'], true),
            ...leaves(byExact(kams, 'kams.stock.update'), true),
          ],
        ),
        pageSection(
          'kams.stock.depots',
          'kams.depots',
          'Depots',
          leaves(byExact(kams, 'kams.stock.depots.view')),
          [
            ...actionLeavesOnly(kams, 'kams.stock.depots', ['create', 'update', 'delete', 'receive', 'supply', 'remove', 'adjust', 'price'], true),
            ...leaves(byExact(kams, 'kams.products.view'), true),
            ...actionLeavesOnly(kams, 'kams.products', ['create', 'update', 'delete'], true),
            ...leaves(byExact(kams, 'kams.stock.update'), true),
          ],
        ),
        section(
          'kams.stock.operations',
          'kams.commercialOperations',
          'Operations',
          [
            pageSection(
              'kams.stock.assignments',
              'kams.assignments',
              'Assignments',
              leaves(byExact(kams, 'kams.stock.assignments.view')),
              [
                ...leaves(byExact(kams, 'kams.stock.update'), true),
                ...actionLeavesOnly(kams, 'kams.stock.assignments', ['delete'], true),
              ],
            ),
            pageSection(
              'kams.stock.reports',
              'kams.assignmentReports',
              'Reports',
              leaves(byExact(kams, 'kams.stock.reports.view')),
              actionLeavesOnly(kams, 'kams.stock.reports', ['print', 'preview', 'delete'], true),
            ),
            pageSection(
              'kams.stock.journal',
              'kams.stockJournal',
              'Journal',
              leaves(byExact(kams, 'kams.stock.journal.view')),
              actionLeavesOnly(kams, 'kams.stock.journal', ['delete'], true),
            ),
          ].filter(Boolean) as PermissionNode[],
        ),
      ].filter(Boolean) as PermissionNode[],
    ),
    section(
      'kams.finance',
      'settings.permissionGroups.kams.finance',
      'Finance',
      [
        pageSection(
          'kams.finance.dashboard',
          'settings.permissionGroups.kams.financePages.dashboard',
          'Dashboard',
          leaves(byExact(kams, 'kams.finance.dashboard.view')),
          leaves(byExact(kams, 'kams.finance.dashboard.update'), true),
        ),
        pageSection(
          'kams.finance.account',
          'kams.myAccount',
          'My account',
          leaves(byExact(kams, 'kams.finance.account.view')),
          actionLeavesOnly(
            kams,
            'kams.finance.account',
            ['deposit', 'withdraw', 'update', 'print', 'preview'],
            true,
          ),
        ),
        pageSection(
          'kams.finance.accounts',
          'kams.agentAccounts',
          'Agent accounts',
          leaves(byExact(kams, 'kams.finance.accounts.view')),
        ),
        pageSection(
          'kams.finance.company',
          'kams.companyAccount',
          'Company accounts',
          leaves(byExact(kams, 'kams.finance.company.view')),
          actionLeavesOnly(
            kams,
            'kams.finance.company',
            ['deposit', 'withdraw', 'expense', 'approve', 'print', 'preview'],
            true,
          ),
        ),
      ].filter(Boolean) as PermissionNode[],
    ),
    section(
      'kams.clients',
      'kams.clientsAndSuppliers',
      'Customers & suppliers',
      [
        pageSection(
          'kams.clients.clients',
          'kams.clients',
          'Customers',
          leaves(byExact(kams, 'kams.clients.view')),
          actionLeavesOnly(kams, 'kams.clients', ['create', 'update', 'delete'], true),
        ),
        pageSection(
          'kams.clients.suppliers',
          'kams.suppliers',
          'Suppliers',
          leaves(byExact(kams, 'kams.suppliers.view')),
          actionLeavesOnly(kams, 'kams.clients', ['create', 'update', 'delete'], true),
        ),
      ].filter(Boolean) as PermissionNode[],
    ),
    section(
      'kams.patrimoine',
      'settings.permissionGroups.kams.patrimoine',
      'Assets',
      [
        ...leaves(byExact(kams, 'kams.patrimoine.view')),
        ...actionLeavesOnly(kams, 'kams.patrimoine', ['create', 'update', 'delete'], true),
      ],
    ),
  ]

  const ordered = pages
    .filter(Boolean)
    .sort((a, b) => {
      const keyA = (a as PermissionSection).key.replace('kams.', '') as typeof KAMS_PAGE_ORDER[number]
      const keyB = (b as PermissionSection).key.replace('kams.', '') as typeof KAMS_PAGE_ORDER[number]
      return KAMS_PAGE_ORDER.indexOf(keyA) - KAMS_PAGE_ORDER.indexOf(keyB)
    }) as PermissionNode[]

  const covered = new Set(collectPermissions(ordered).map((permission) => permission.id))
  const leftovers = kams.filter((permission) => !covered.has(permission.id))
  if (leftovers.length > 0) {
    const other = section('kams.other', 'settings.rolesOtherPermissions', 'Other', leaves(sortByAction(leftovers)))
    if (other) ordered.push(other)
  }

  return section('kams', 'nav.kams', 'KAMS', ordered)
}

function buildGenericModuleTree(moduleSlug: string, permissions: Permission[], labelDefault: string): PermissionSection | null {
  const items = byGroupPrefix(permissions, moduleSlug)
  if (items.length === 0) return null

  const groups = new Map<string, Permission[]>()
  for (const permission of items) {
    const current = groups.get(permission.group) ?? []
    current.push(permission)
    groups.set(permission.group, current)
  }

  const children = [...groups.entries()]
    .sort(([a], [b]) => a.localeCompare(b))
    .map(([group, groupPermissions]) => {
      const labelKey = group.includes('.')
        ? `settings.permissionGroups.${group}`
        : `settings.permissionGroups.${group}`
      return section(group, labelKey, group, actionLeaves(sortByAction(groupPermissions), permissionPrefix(groupPermissions[0]?.slug || group), true))
    })
    .filter(Boolean) as PermissionNode[]

  return section(moduleSlug, `nav.${moduleSlug}`, labelDefault, children)
}

function buildPlatformTree(permissions: Permission[]): PermissionSection | null {
  const children: PermissionNode[] = []

  const administration = buildAdministrationTree(permissions)
  if (administration) children.push(administration)

  for (const group of PLATFORM_GROUPS) {
    const items = byGroup(permissions, group)
    if (items.length === 0) continue
    const node = section(`platform.${group}`, `settings.permissionGroups.${group}`, group, actionLeaves(items, group, true))
    if (node) children.push(node)
  }

  if (children.length === 0) return null

  return section('platform', 'settings.rolesPlatformAccess', 'Platform', children)
}

export function buildPermissionTree(
  permissionGroups: Record<string, Permission[]>,
  moduleSlug: string | null | undefined,
  canSee: (slug: string) => boolean,
): PermissionSection[] {
  const all = Object.values(permissionGroups).flat().filter((permission) => canSee(permission.slug))
  if (all.length === 0) return []

  const tree: PermissionSection[] = []

  const platform = buildPlatformTree(all)
  if (platform) tree.push(platform)

  if (moduleSlug === 'kams') {
    const kams = buildKamsTree(all)
    if (kams) tree.push(kams)
  } else if (moduleSlug) {
    const moduleTree = buildGenericModuleTree(moduleSlug, all, moduleSlug)
    if (moduleTree) tree.push(moduleTree)
  }

  return tree
}

export function viewFor(permissions: Permission[], permission: Permission) {
  return permissions.find((item) => item.slug === `${permissionPrefix(permission.slug)}.view`) ?? null
}

export function siblingsOf(permissions: Permission[], permission: Permission) {
  const prefix = permissionPrefix(permission.slug)
  return permissions.filter((item) => permissionPrefix(item.slug) === prefix)
}

function areaViewSlugs(permission: Permission): string[] {
  const slug = permission.slug
  if (slug.startsWith('kams.recruitment.') && !isViewPermission(permission)) {
    if (slug.startsWith('kams.recruitment.reports.')) {
      return ['kams.recruitment.reports.view']
    }
    return ['kams.recruitment.applications.view']
  }
  if (
    slug.startsWith('kams.members.')
    && !isViewPermission(permission)
    && !slug.startsWith('kams.members.statistics.')
  ) {
    return ['kams.members.agents.view']
  }
  if (slug === 'kams.stock.update') {
    return [
      'kams.stock.assignments.view',
      'kams.stock.warehouses.view',
      'kams.stock.depots.view',
    ]
  }
  if (slug.startsWith('kams.finance.dashboard.') && !isViewPermission(permission)) {
    return ['kams.finance.dashboard.view']
  }
  if (slug.startsWith('kams.finance.account.') && !isViewPermission(permission)) {
    return ['kams.finance.account.view']
  }
  if (slug.startsWith('kams.finance.company.') && !isViewPermission(permission)) {
    return ['kams.finance.company.view']
  }
  if (slug.startsWith('kams.stock.assignments.') && !isViewPermission(permission)) {
    return ['kams.stock.assignments.view']
  }
  if (slug.startsWith('kams.stock.reports.') && !isViewPermission(permission)) {
    return ['kams.stock.reports.view']
  }
  if (slug.startsWith('kams.stock.journal.') && !isViewPermission(permission)) {
    return ['kams.stock.journal.view']
  }
  if (slug === 'kams.products.view') {
    return ['kams.stock.warehouses.view', 'kams.stock.depots.view']
  }
  if (slug.startsWith('kams.products.') && !isViewPermission(permission)) {
    return ['kams.products.view', 'kams.stock.warehouses.view', 'kams.stock.depots.view']
  }
  if (slug.startsWith('kams.clients.') && !isViewPermission(permission)) {
    return ['kams.clients.view', 'kams.suppliers.view']
  }
  if (slug.startsWith('kams.patrimoine.') && !isViewPermission(permission)) {
    return ['kams.patrimoine.view']
  }
  const view = `${permissionPrefix(slug)}.view`
  return [view]
}

function hasRequiredAreaView(permissionIds: number[], permission: Permission, allVisible: Permission[]) {
  if (isViewPermission(permission)) return true
  const views = areaViewSlugs(permission)
    .map((slug) => allVisible.find((item) => item.slug === slug))
    .filter(Boolean) as Permission[]
  if (views.length === 0) {
    const fallback = viewFor(allVisible, permission)
    return !fallback || permissionIds.includes(fallback.id)
  }
  return views.some((view) => permissionIds.includes(view.id))
}

export function isPermissionEnabled(
  permissionIds: number[],
  permission: Permission,
  allVisible: Permission[],
) {
  if (!permissionIds.includes(permission.id)) return false

  const adminView = allVisible.find((item) => item.slug === ADMIN_VIEW_SLUG)
  if (adminView && permission.group === 'administration' && permission.slug !== ADMIN_VIEW_SLUG) {
    if (!permissionIds.includes(adminView.id)) return false
  }

  const dashboardView = allVisible.find((item) => item.slug === DASHBOARD_VIEW_SLUG)
  if (dashboardView && permission.slug.startsWith('kams.dashboard.') && permission.slug !== DASHBOARD_VIEW_SLUG) {
    if (!permissionIds.includes(dashboardView.id)) return false
  }

  if (!hasRequiredAreaView(permissionIds, permission, allVisible)) return false

  return true
}

export function isPermissionGatedOff(
  permissionIds: number[],
  permission: Permission,
  allVisible: Permission[],
) {
  const adminView = allVisible.find((item) => item.slug === ADMIN_VIEW_SLUG)
  if (adminView && permission.group === 'administration' && permission.slug !== ADMIN_VIEW_SLUG) {
    if (!permissionIds.includes(adminView.id)) return true
  }

  const dashboardView = allVisible.find((item) => item.slug === DASHBOARD_VIEW_SLUG)
  if (dashboardView && permission.slug.startsWith('kams.dashboard.') && permission.slug !== DASHBOARD_VIEW_SLUG) {
    if (!permissionIds.includes(dashboardView.id)) return true
  }

  return !hasRequiredAreaView(permissionIds, permission, allVisible)
}

export function togglePermissionIds(
  current: number[],
  permission: Permission,
  allVisible: Permission[],
): number[] {
  const enabled = current.includes(permission.id)
  const adminView = allVisible.find((item) => item.slug === ADMIN_VIEW_SLUG)
  const dashboardView = allVisible.find((item) => item.slug === DASHBOARD_VIEW_SLUG)
  const adminIds = adminView ? allVisible.filter((item) => item.group === 'administration').map((item) => item.id) : []
  const dashboardIds = dashboardView
    ? allVisible.filter((item) => item.slug.startsWith('kams.dashboard.')).map((item) => item.id)
    : []
  const siblingIds = siblingsOf(allVisible, permission).map((item) => item.id)
  const requiredViews = areaViewSlugs(permission)
    .map((slug) => allVisible.find((item) => item.slug === slug))
    .filter(Boolean) as Permission[]
  const view = requiredViews[0] ?? viewFor(allVisible, permission)

  if (permission.slug === ADMIN_VIEW_SLUG) {
    if (enabled) return current.filter((id) => !adminIds.includes(id))
    return [...new Set([...current, permission.id])]
  }

  if (permission.slug === DASHBOARD_VIEW_SLUG) {
    if (enabled) return current.filter((id) => !dashboardIds.includes(id))
    return [...new Set([...current, permission.id])]
  }

  if (adminView && permission.group === 'administration' && !current.includes(adminView.id)) {
    return current
  }

  if (dashboardView && permission.slug.startsWith('kams.dashboard.') && !current.includes(dashboardView.id)) {
    return current
  }

  if (isViewPermission(permission)) {
    if (enabled) return current.filter((id) => !siblingIds.includes(id))
    const next = new Set(current)
    next.add(permission.id)
    if (adminView && permission.group === 'administration') next.add(adminView.id)
    if (dashboardView && permission.slug.startsWith('kams.dashboard.')) next.add(dashboardView.id)
    return [...next]
  }

  if (!hasRequiredAreaView(current, permission, allVisible)) {
    return current
  }

  if (enabled) return current.filter((id) => id !== permission.id)
  const next = new Set(current)
  next.add(permission.id)
  if (view) next.add(view.id)
  return [...next]
}

export function setSectionPermissionIds(
  current: number[],
  sectionPermissions: Permission[],
  enable: boolean,
  allVisible: Permission[],
): number[] {
  let next = [...current]
  const ordered = enable
    ? [...sectionPermissions].sort((a, b) => Number(isViewPermission(b)) - Number(isViewPermission(a)))
    : [...sectionPermissions].sort((a, b) => Number(isViewPermission(a)) - Number(isViewPermission(b)))

  for (const permission of ordered) {
    const isOn = next.includes(permission.id)
    if (enable && !isOn) next = togglePermissionIds(next, permission, allVisible)
    if (!enable && isOn) next = togglePermissionIds(next, permission, allVisible)
  }

  return next
}

export function filterTreeByQuery(nodes: PermissionSection[], query: string, labelOf: (permission: Permission) => string, sectionLabelOf: (node: PermissionSection) => string): PermissionSection[] {
  const normalized = query.trim().toLowerCase()
  if (!normalized) return nodes

  const matchNode = (node: PermissionNode): PermissionNode | null => {
    if (node.kind === 'leaf') {
      const haystack = `${labelOf(node.permission)} ${node.permission.slug}`.toLowerCase()
      return haystack.includes(normalized) ? node : null
    }

    const childMatches = node.children.map(matchNode).filter(Boolean) as PermissionNode[]
    const selfMatch = sectionLabelOf(node).toLowerCase().includes(normalized)
    if (!selfMatch && childMatches.length === 0) return null
    return {
      ...node,
      children: selfMatch ? node.children : childMatches,
      permissions: selfMatch ? node.permissions : collectPermissions(childMatches),
    }
  }

  return nodes.map(matchNode).filter(Boolean) as PermissionSection[]
}

/** Hide options/pages that are not available until their required view is granted. */
export function filterTreeByGates(
  nodes: PermissionSection[],
  permissionIds: number[],
  allVisible: Permission[],
): PermissionSection[] {
  const filterNode = (node: PermissionNode): PermissionNode | null => {
    if (node.kind === 'leaf') {
      return isPermissionGatedOff(permissionIds, node.permission, allVisible) ? null : node
    }

    const children = node.children.map(filterNode).filter(Boolean) as PermissionNode[]
    if (children.length === 0) return null

    return {
      ...node,
      children,
      permissions: collectPermissions(children),
    }
  }

  return nodes.map(filterNode).filter(Boolean) as PermissionSection[]
}

export { ADMIN_VIEW_SLUG, DASHBOARD_VIEW_SLUG }
