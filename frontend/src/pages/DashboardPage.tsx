import {
  Building2,
  Crown,
  LayoutDashboard,
  ScrollText,
  Search,
  Settings,
  Shield,
  Truck,
  Tv,
  Users,
  Wheat,
} from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { Modal } from '../components/ui'
import { useAuth } from '../context/AuthContext'
import { useSystemModules } from '../context/ModulesContext'
import { ADMIN_PERMS, canAccessModule, DASHBOARD_PERMS, SETTINGS_PERMS } from '../lib/access'
import { cn } from '../lib/cn'
import { greetingKey } from '../lib/format'
import { moduleRegistry } from '../modules/registry'
import type { SystemModule } from '../types'

const moduleLooks = {
  kams: { icon: Crown, tint: 'bg-[linear-gradient(145deg,#f5c542,#c98912)]' },
  'heavy-equipment': { icon: Truck, tint: 'bg-[linear-gradient(145deg,#fb923c,#c2410c)]' },
  'real-estate': { icon: Building2, tint: 'bg-[linear-gradient(145deg,#60a5fa,#1d4ed8)]' },
  'rice-mills': { icon: Wheat, tint: 'bg-[linear-gradient(145deg,#a3e635,#4d7c0f)]' },
  'lali-tv': { icon: Tv, tint: 'bg-[linear-gradient(145deg,#f472b6,#9d174d)]' },
}

type DockApp = {
  to: string
  key: string
  icon: typeof Settings
  perm?: string
  anyPerm?: string[]
  tint: string
}

const adminApps: DockApp[] = [
  { to: '/administration', key: 'administration', icon: Shield, anyPerm: ADMIN_PERMS, tint: 'bg-[linear-gradient(145deg,#64748b,#1e293b)]' },
  { to: '/administration/dashboard', key: 'dashboard', icon: LayoutDashboard, anyPerm: DASHBOARD_PERMS, tint: 'bg-[linear-gradient(145deg,#4ade80,#094924)]' },
  { to: '/administration/users', key: 'users', icon: Users, perm: 'users.view', tint: 'bg-[linear-gradient(145deg,#818cf8,#3730a3)]' },
  { to: '/administration/audit', key: 'audit', icon: ScrollText, perm: 'audit.view', tint: 'bg-[linear-gradient(145deg,#94a3b8,#334155)]' },
  { to: '/administration/settings', key: 'settings', icon: Settings, anyPerm: SETTINGS_PERMS, tint: 'bg-[linear-gradient(145deg,#cbd5e1,#475569)]' },
]

function AppIcon({
  label,
  icon: Icon,
  tint,
  badge,
  onClick,
}: {
  label: string
  icon: typeof Crown
  tint: string
  badge?: string
  onClick: () => void
}) {
  return (
    <button type="button" onClick={onClick} className="group flex w-[92px] flex-col items-center gap-2.5 sm:w-[104px]">
      <span className={cn(
        'relative grid h-[68px] w-[68px] place-items-center rounded-[22px] text-white shadow-[0_12px_24px_rgba(0,0,0,0.28)] transition duration-200 group-hover:-translate-y-1 group-hover:scale-110 group-hover:shadow-[0_18px_32px_rgba(0,0,0,0.35)] sm:h-[76px] sm:w-[76px] sm:rounded-[24px]',
        tint,
      )}>
        <Icon size={32} strokeWidth={1.7} />
        {badge ? (
          <span className="absolute -bottom-1.5 max-w-[84px] truncate rounded-full bg-black/55 px-1.5 py-0.5 text-[9px] font-semibold uppercase tracking-wide text-white/90">
            {badge}
          </span>
        ) : null}
      </span>
      <span className="line-clamp-2 text-center text-[12px] font-medium leading-tight text-white/90 drop-shadow-sm sm:text-[13px]">
        {label}
      </span>
    </button>
  )
}

export function DashboardPage() {
  const { t, i18n } = useTranslation()
  const { user, hasPermission, hasAnyPermission } = useAuth()
  const { modules } = useSystemModules()
  const navigate = useNavigate()
  const [query, setQuery] = useState('')
  const [denied, setDenied] = useState('')

  const filteredModules = useMemo(() => {
    const q = query.trim().toLowerCase()
    return modules.filter((module) => {
      const title = (i18n.language.startsWith('fr') ? module.name_fr : module.name).toLowerCase()
      return !q || title.includes(q) || module.slug.includes(q)
    })
  }, [modules, query, i18n.language])

  const canSeeApp = (app: DockApp) => (
    app.anyPerm?.length ? hasAnyPermission(app.anyPerm) : Boolean(!app.perm || hasPermission(app.perm))
  )

  const admin = adminApps.filter(canSeeApp)
  const canAdmin = admin.length > 0
  const q = query.trim().toLowerCase()
  const searchedAdmin = q
    ? admin.filter((app) => {
      const label = t(`nav.${app.key}`).toLowerCase()
      return label.includes(q) || app.key.includes(q)
    })
    : []

  const openModule = (module: SystemModule) => {
    if (!canAccessModule(user, module.slug)) {
      setDenied(t('modules.noAccess'))
      return
    }
    const ready = module.slug === 'kams' && module.status === 'active'
    if (!ready) {
      setDenied(t('modules.in_development'))
      return
    }
    const first = moduleRegistry.kams.items.find((item) => {
      if (item.anyPerm?.length) return item.anyPerm.some((perm) => hasPermission(perm))
      return !item.perm || hasPermission(item.perm)
    })
    navigate(first?.to || '/modules/kams')
  }

  return (
    <div className="relative flex min-h-[calc(100dvh-64px)] flex-col overflow-hidden">
      <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(78,154,44,0.35),transparent_42%),radial-gradient(circle_at_80%_10%,rgba(9,73,36,0.55),transparent_38%),linear-gradient(180deg,#0b2416_0%,#062010_55%,#04150c_100%)]" />
      <div className="pointer-events-none absolute -left-24 top-24 h-72 w-72 rounded-full bg-lime/20 blur-3xl" />
      <div className="pointer-events-none absolute -right-16 bottom-32 h-80 w-80 rounded-full bg-white/5 blur-3xl" />

      <div className="relative z-10 mx-auto flex w-full max-w-5xl flex-1 flex-col px-5 py-8">
        <div className="flex flex-1 flex-col items-center justify-center">
          <div className="w-full text-center text-white">
            <p className="text-xs font-semibold tracking-[0.32em] text-white/55">LALI HOLDINGS</p>
            <h1 className="mt-3 text-3xl font-extrabold sm:text-4xl">
              {t(greetingKey())}, {user?.first_name?.trim()}
            </h1>
            <p className="mt-2 text-sm text-white/70">{t('dashboard.launcher')}</p>
          </div>

          <label className="mx-auto mt-7 flex w-full max-w-md items-center gap-2 rounded-full border border-white/15 bg-white/10 px-4 py-2.5 text-white backdrop-blur-xl">
            <Search size={16} className="text-white/60" />
            <input
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={t('dashboard.searchApps')}
              className="w-full bg-transparent text-sm outline-none placeholder:text-white/45"
            />
          </label>

          <div className="mt-10 flex w-full flex-wrap items-start justify-center gap-x-4 gap-y-8 sm:gap-x-8 sm:gap-y-10">
            {searchedAdmin.map((app) => (
              <AppIcon
                key={app.to}
                label={t(`nav.${app.key}`)}
                icon={app.icon}
                tint={app.tint}
                onClick={() => navigate(app.to)}
              />
            ))}
            {filteredModules.map((module) => {
              const look = moduleLooks[module.slug as keyof typeof moduleLooks] ?? { icon: Crown, tint: 'bg-[linear-gradient(145deg,#86efac,#166534)]' }
              const title = i18n.language.startsWith('fr') ? module.name_fr : module.name
              const ready = module.slug === 'kams' && module.status === 'active'
              return (
                <AppIcon
                  key={module.id}
                  label={title}
                  icon={look.icon}
                  tint={look.tint}
                  badge={ready ? undefined : t('modules.in_development')}
                  onClick={() => openModule(module)}
                />
              )
            })}
          </div>
        </div>

        {canAdmin ? (
          <div className="flex justify-center pb-2">
            <Link
              to="/administration"
              title={t('nav.administration')}
              className="group inline-flex items-center gap-2.5 rounded-full border border-white/20 bg-white/5 px-5 py-2.5 text-white/90 backdrop-blur-sm transition duration-200 hover:border-white/35 hover:bg-white/10 hover:text-white"
            >
              <span className="grid h-8 w-8 place-items-center rounded-full border border-white/25 bg-white/10 text-white/90 transition group-hover:border-white/40 group-hover:bg-white/15">
                <Shield size={16} strokeWidth={1.8} />
              </span>
              <span className="text-sm font-medium tracking-wide">{t('nav.administration')}</span>
            </Link>
          </div>
        ) : null}
      </div>

      <Modal open={Boolean(denied)} title={t('nav.modules')} onClose={() => setDenied('')}>
        <p className="text-sm text-muted">{denied}</p>
      </Modal>
    </div>
  )
}
