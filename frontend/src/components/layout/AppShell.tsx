import {
  Bell,
  Crown,
  House,
  LayoutDashboard,
  LogOut,
  Menu,
  MoreHorizontal,
  Shield,
  UserRound,
  X,
} from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { NavLink, Outlet, useLocation } from 'react-router-dom'
import { ProfileModal } from '../ProfileModal'
import { NotificationsModal } from '../NotificationsModal'
import { UserAvatar } from '../UserAvatar'
import { useAuth } from '../../context/AuthContext'
import { useSystemModules } from '../../context/ModulesContext'
import { canAccessModule, ADMIN_PERMS } from '../../lib/access'
import { businessModuleIcon, dashboardBusinessSlug, moduleFromPath, moduleRegistry, navItemLabel } from '../../modules/registry'
import { cn } from '../../lib/cn'
import { roleLabel } from '../../lib/format'
import api from '../../lib/api'

const links = [
  { to: '/', key: 'home', icon: House },
  { to: '/administration', key: 'administration', icon: Shield, anyPerm: ADMIN_PERMS },
  { to: '/modules', key: 'modules', icon: Crown, anyPerm: ADMIN_PERMS },
]

function navClass(isActive: boolean) {
  return cn(
    'flex items-center gap-3 rounded-xl px-3 py-3 text-sm font-medium',
    isActive ? 'bg-white text-lhms shadow-lg' : 'text-white/75 hover:bg-white/10 hover:text-white',
  )
}

export function AppShell() {
  const { t, i18n } = useTranslation()
  const { user, logout, hasPermission, hasAnyPermission, setLocale } = useAuth()
  const [open, setOpen] = useState(false)
  const [moreOpen, setMoreOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const [profileOpen, setProfileOpen] = useState(false)
  const [notificationsOpen, setNotificationsOpen] = useState(false)
  const [unread, setUnread] = useState(0)
  const sidebarUserRef = useRef<HTMLDivElement>(null)
  const headerUserRef = useRef<HTMLDivElement>(null)
  const location = useLocation()
  const currentModule = moduleFromPath(location.pathname)
  const ModuleIcon = currentModule?.icon
  const { modules } = useSystemModules()
  const isDashboardWorkspace = location.pathname.startsWith('/administration/dashboard')
  const businessSlug = dashboardBusinessSlug(location.pathname)
  const plusModule = businessSlug ? (moduleRegistry[businessSlug] ?? null) : currentModule

  useEffect(() => {
    setOpen(false)
    setMoreOpen(false)
    setUserMenuOpen(false)
  }, [location.pathname])

  useEffect(() => {
    if (!userMenuOpen) return
    const onPointerDown = (event: PointerEvent) => {
      const target = event.target as Node
      if (sidebarUserRef.current?.contains(target) || headerUserRef.current?.contains(target)) return
      setUserMenuOpen(false)
    }
    document.addEventListener('pointerdown', onPointerDown)
    return () => document.removeEventListener('pointerdown', onPointerDown)
  }, [userMenuOpen])

  useEffect(() => {
    if (!hasPermission('notifications.view')) {
      setUnread(0)
      return
    }
    api.get('/notifications', { params: { unread: 1, per_page: 1 } }).then((res) => {
      setUnread(res.data.meta?.unread_count ?? 0)
    }).catch(() => undefined)
  }, [location.pathname, hasPermission])

  const canSeeItem = (item: { perm?: string; anyPerm?: string[] }) => {
    if (item.anyPerm?.length) return item.anyPerm.some((perm) => hasPermission(perm))
    return !item.perm || hasPermission(item.perm)
  }
  const isHome = location.pathname === '/'
  const isAdministration = currentModule?.slug === 'administration'
  const sourceItems = currentModule?.items ?? []
  const visible = links.filter(canSeeItem)
  const moduleItems = sourceItems.filter(canSeeItem)
  const plusItems = (plusModule?.items ?? []).filter(canSeeItem)
  const needsMore = Boolean(currentModule && currentModule.slug !== 'administration' && (moduleItems.length > 3 || isDashboardWorkspace))
  const bottomItems = currentModule
    ? [
        { to: '/', key: 'nav.home', icon: House, end: true },
        ...moduleItems.slice(0, needsMore ? 2 : 4).map((item) => ({
          to: item.to,
          key: item.labelKey,
          icon: item.icon,
          end: item.end,
        })),
        ...(needsMore ? [{ to: '__more__', key: 'nav.more', icon: MoreHorizontal, end: false }] : []),
      ]
    : [
        { to: '/', key: 'nav.home', icon: House, end: true },
        ...(hasAnyPermission(ADMIN_PERMS) ? [{ to: '/administration', key: 'nav.administration', icon: Shield, end: false }] : []),
      ]
  const visibleBottomPaths = new Set(bottomItems.filter((item) => item.to !== '__more__').map((item) => item.to))
  const extraModuleItems = plusItems.filter((item) => !visibleBottomPaths.has(item.to))
  const extraDashboardModules = plusModule?.slug === 'dashboard'
    ? modules.filter((item) => !visibleBottomPaths.has(`/administration/dashboard/${item.slug}`) && canAccessModule(user, item.slug))
    : []

  return (
    <div className={cn('min-h-dvh', isHome ? 'bg-[#071a10]' : 'bg-surface lg:grid lg:grid-cols-[272px_1fr]')}>
      {!isHome && open ? (
        <button
          type="button"
          className="fixed inset-0 z-30 bg-black/40 lg:hidden"
          aria-label={t('common.close')}
          onClick={() => setOpen(false)}
        />
      ) : null}
      {!isHome ? (
      <aside className={cn(
        'fixed inset-y-0 left-0 z-40 flex w-[272px] flex-col bg-lhms text-white transition-transform lg:sticky lg:top-0 lg:h-dvh lg:self-start lg:translate-x-0',
        open ? 'translate-x-0' : '-translate-x-full',
      )}>
        <div className="flex h-full min-h-0 flex-col">
          <div className="flex shrink-0 items-center justify-between px-5 py-6">
            <div className="flex min-w-0 items-center gap-3">
              <img
                src="/lali_holdings_logo.png"
                alt="Lali Holdings"
                className="h-18 w-auto shrink-0 object-contain drop-shadow-sm"
              />
              <div className="min-w-0">
                <div className="text-xs font-semibold tracking-[0.28em] text-white/60">LALI HOLDINGS</div>
                <div className="mt-1 truncate text-2xl font-extrabold">{currentModule ? t(currentModule.nameKey) : 'LHMS'}</div>
              </div>
            </div>
            <button className="lg:hidden" onClick={() => setOpen(false)}><X /></button>
          </div>
          <nav className="min-h-0 flex-1 space-y-1 overflow-y-auto px-3">
            {currentModule ? (
              <>
                <NavLink to="/" end className={({ isActive }) => navClass(isActive)}>
                  <House size={18} />
                  {t('nav.home')}
                </NavLink>
                <div className="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-[0.18em] text-white/40">
                  {t(currentModule.titleKey)}
                </div>
                {moduleItems.map((item) => {
                  const Icon = item.icon
                  return (
                    <NavLink
                      key={item.to}
                      to={item.to}
                      end={item.end}
                      className={({ isActive }) => navClass(isActive)}
                    >
                      <Icon size={18} />
                      {navItemLabel(item, t)}
                    </NavLink>
                  )
                })}
              </>
            ) : (
              visible.map((link) => {
                const Icon = link.icon
                return (
                  <NavLink
                    key={link.to}
                    to={link.to}
                    end={link.to === '/' || link.to === '/modules'}
                    className={({ isActive }) => navClass(isActive)}
                  >
                    <Icon size={18} />
                    {t(`nav.${link.key}`)}
                  </NavLink>
                )
              })
            )}
          </nav>
          <div ref={sidebarUserRef} className="relative shrink-0 p-4">
            <button
              type="button"
              aria-expanded={userMenuOpen}
              aria-haspopup="menu"
              aria-label={user?.name || t('settings.profile')}
              onClick={() => setUserMenuOpen((visible) => !visible)}
              className="flex w-full items-center gap-3 rounded-2xl bg-white/10 p-3 text-left hover:bg-white/15"
            >
              <span className="grid h-10 w-10 shrink-0 overflow-hidden place-items-center rounded-full bg-white/15 text-sm font-bold text-white ring-1 ring-white/20">
                <UserAvatar user={user} />
              </span>
              <span className="min-w-0 flex-1">
                <span className="block truncate text-sm font-semibold">{user?.name}</span>
                <span className="block truncate text-xs text-white/70">{roleLabel(t, user?.roles?.[0], user?.gender)}</span>
              </span>
            </button>
            {userMenuOpen ? (
              <div
                role="menu"
                className="absolute inset-x-4 bottom-full z-50 mb-2 overflow-hidden rounded-2xl border border-white/10 bg-white py-1 text-ink shadow-[0_16px_40px_rgba(4,55,17,0.18)]"
              >
                <button
                  type="button"
                  role="menuitem"
                  className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm font-semibold hover:bg-surface"
                  onClick={() => {
                    setUserMenuOpen(false)
                    setProfileOpen(true)
                  }}
                >
                  <UserRound size={16} />
                  {t('settings.profile')}
                </button>
                <button
                  type="button"
                  role="menuitem"
                  className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm font-semibold text-danger hover:bg-danger-soft"
                  onClick={() => {
                    setUserMenuOpen(false)
                    void logout()
                  }}
                >
                  <LogOut size={16} />
                  {t('common.logout')}
                </button>
              </div>
            ) : null}
          </div>
        </div>
      </aside>
      ) : null}

      <div className={cn('flex min-w-0 flex-col', isHome ? 'min-h-dvh' : 'pb-20 lg:pb-0')}>
        <header className={cn(
          'sticky top-0 z-30 flex items-center justify-between gap-3 px-4 py-1.5 lg:px-8',
          isHome ? 'border-b border-white/10 bg-black/20 text-white backdrop-blur-xl' : 'border-b border-line bg-white/90 backdrop-blur',
        )}>
          <div className="flex min-w-0 flex-1 items-center gap-3">
            {!isHome && !isAdministration && currentModule?.slug !== 'kams' ? (
              <button
                type="button"
                className="rounded-xl p-2 lg:hidden"
                onClick={() => setOpen(true)}
              >
                <Menu />
              </button>
            ) : null}
            {isHome ? (
              <div className="flex items-center gap-2.5">
                <img src="/lali_holdings_logo.png" alt="Lali Holdings" className="h-9 w-auto object-contain drop-shadow-sm sm:h-10" />
                <span className="font-semibold tracking-[0.18em] text-white/80">LHMS</span>
              </div>
            ) : currentModule && ModuleIcon ? (
              <div className="flex min-w-0 items-center gap-2 text-lhms">
                <img
                  src="/lali_holdings_logo.png"
                  alt="Lali Holdings"
                  className="h-8 w-auto shrink-0 object-contain lg:hidden"
                />
                <span className="h-6 w-px shrink-0 bg-line lg:hidden" aria-hidden />
                <ModuleIcon size={20} className="shrink-0" aria-hidden />
                <div className="truncate text-sm font-semibold">
                  {isAdministration ? <span className="lg:hidden">{t('app.name')} - </span> : null}
                  {t(currentModule.titleKey)}
                </div>
              </div>
            ) : (
              <div className="hidden text-sm text-muted sm:block">{t('app.full')}</div>
            )}
          </div>
          <div className="flex shrink-0 items-center gap-2">
            <select
              value={i18n.language.startsWith('fr') ? 'fr' : 'en'}
              onChange={(e) => setLocale(e.target.value)}
              className={cn(
                'min-h-10 rounded-xl px-2 text-sm',
                isHome
                  ? 'border border-white/25 bg-transparent text-white [&>option]:bg-lhms [&>option]:text-white'
                  : 'border border-line bg-white',
              )}
            >
              <option value="fr">FR</option>
              <option value="en">EN</option>
            </select>
            {hasPermission('notifications.view') ? (
            <button
              type="button"
              aria-label={t('nav.notifications')}
              onClick={() => setNotificationsOpen(true)}
              className={cn('relative rounded-xl p-2', isHome ? 'hover:bg-white/10' : 'hover:bg-surface')}
            >
              <Bell size={18} />
              {unread > 0 ? (
                <span className="absolute -right-1 -top-1 inline-flex min-w-5 items-center justify-center rounded-full bg-danger px-1.5 py-0.5 text-[10px] font-bold leading-none text-white">
                  {unread > 99 ? '99+' : unread}
                </span>
              ) : null}
            </button>
            ) : null}
            <div ref={headerUserRef} className={cn('relative', !isHome && 'lg:hidden')}>
              <button
                type="button"
                aria-expanded={userMenuOpen}
                aria-haspopup="menu"
                aria-label={user?.name || t('settings.profile')}
                onClick={() => setUserMenuOpen((visible) => !visible)}
                className="grid h-10 w-10 overflow-hidden place-items-center rounded-full bg-lhms text-sm font-bold text-white ring-2 ring-white/20 hover:ring-white/50"
              >
                <UserAvatar user={user} />
              </button>
              {userMenuOpen ? (
                <>
                  <button
                    type="button"
                    className="fixed inset-0 z-40 cursor-default"
                    aria-label={t('common.close')}
                    onClick={() => setUserMenuOpen(false)}
                  />
                  <div
                    role="menu"
                    className="absolute right-0 z-50 mt-2 w-52 overflow-hidden rounded-2xl border border-line bg-white py-1 shadow-[0_16px_40px_rgba(4,55,17,0.18)]"
                  >
                    <button
                      type="button"
                      role="menuitem"
                      className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm font-semibold text-ink hover:bg-surface"
                      onClick={() => {
                        setUserMenuOpen(false)
                        setProfileOpen(true)
                      }}
                    >
                      <UserRound size={16} />
                      {t('settings.profile')}
                    </button>
                    <button
                      type="button"
                      role="menuitem"
                      className="flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm font-semibold text-danger hover:bg-danger-soft"
                      onClick={() => {
                        setUserMenuOpen(false)
                        void logout()
                      }}
                    >
                      <LogOut size={16} />
                      {t('common.logout')}
                    </button>
                  </div>
                </>
              ) : null}
            </div>
          </div>
        </header>

        <main className={cn('flex-1', isHome ? 'px-0 py-0' : 'px-4 py-5 pb-[calc(5.5rem+env(safe-area-inset-bottom))] lg:px-8 lg:py-8')}>
          <Outlet />
        </main>
      </div>

      {!isHome ? (
      <nav className="fixed inset-x-0 bottom-0 z-40 flex bg-lhms px-1.5 pt-2 pb-[max(0.5rem,env(safe-area-inset-bottom))] lg:hidden">
        {bottomItems.map((item) => {
          const Icon = item.icon
          const active = item.to === '__more__'
            ? moreOpen
            : item.end || item.to === '/'
              ? location.pathname === item.to
              : location.pathname.startsWith(item.to)
          const label = item.key.includes('.') ? t(item.key) : t(`nav.${item.key}`)
          const className = cn(
            'flex min-w-0 flex-1 flex-col items-center gap-1 rounded-2xl px-1 py-1.5 text-[10px] font-semibold leading-tight',
            active ? 'bg-white text-lhms shadow-md' : 'text-white/70',
          )
          const content = (
            <>
              <span className="relative shrink-0">
                <Icon size={20} />
              </span>
              <span className="w-full truncate text-center">{label}</span>
            </>
          )
          if (item.to === '__more__') {
            return (
              <button key={item.to} type="button" onClick={() => setMoreOpen(true)} className={className}>
                {content}
              </button>
            )
          }
          return (
            <NavLink key={item.to} to={item.to} end={item.end} className={className}>
              {content}
            </NavLink>
          )
        })}
      </nav>
      ) : null}

      <ProfileModal open={profileOpen} onClose={() => setProfileOpen(false)} />
      <NotificationsModal
        open={notificationsOpen}
        onClose={() => setNotificationsOpen(false)}
        onUnreadChange={setUnread}
      />

      {moreOpen && (plusModule || businessSlug) ? (
        <div className="fixed inset-0 z-50 lg:hidden">
          <button type="button" className="absolute inset-0 bg-black/40" aria-label={t('common.close')} onClick={() => setMoreOpen(false)} />
          <div className="absolute inset-x-0 bottom-0 rounded-t-3xl bg-white px-4 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-3 shadow-[0_-12px_40px_rgba(4,55,17,0.18)]">
            <div className="mx-auto mb-3 h-1.5 w-12 rounded-full bg-line" />
            <div className="mb-3 flex items-center justify-between gap-3">
              <div className="truncate text-base font-bold text-lhms">{t('nav.moreOptions')}</div>
              <button type="button" className="rounded-xl p-2 text-muted" onClick={() => setMoreOpen(false)}><X size={18} /></button>
            </div>
            <nav className="max-h-[60vh] space-y-1 overflow-y-auto">
              {plusModule ? extraModuleItems.map((item) => {
                const Icon = item.icon
                return (
                  <NavLink
                    key={item.to}
                    to={item.to}
                    end={item.end}
                    className={({ isActive }) => cn(
                      'flex items-center gap-3 rounded-2xl px-3 py-3 text-sm font-semibold',
                      isActive ? 'bg-lhms text-white' : 'bg-surface text-ink',
                    )}
                  >
                    <Icon size={18} />
                    {navItemLabel(item, t)}
                  </NavLink>
                )
              }) : (
                <NavLink
                  to={`/administration/dashboard/${businessSlug}`}
                  className="flex items-center gap-3 rounded-2xl bg-lhms px-3 py-3 text-sm font-semibold text-white"
                >
                  <LayoutDashboard size={18} />
                  {t('groupDash.overview')}
                </NavLink>
              )}
              {extraDashboardModules.map((item) => {
                const Icon = businessModuleIcon(item.slug)
                const label = i18n.language.startsWith('fr') ? item.name_fr : item.name
                return (
                  <NavLink
                    key={item.slug}
                    to={`/administration/dashboard/${item.slug}`}
                    className={({ isActive }) => cn(
                      'flex items-center gap-3 rounded-2xl px-3 py-3 text-sm font-semibold',
                      isActive ? 'bg-lhms text-white' : 'bg-surface text-ink',
                    )}
                  >
                    <Icon size={18} className="shrink-0" />
                    <span className="line-clamp-2">{label}</span>
                  </NavLink>
                )
              })}
            </nav>
          </div>
        </div>
      ) : null}
    </div>
  )
}
