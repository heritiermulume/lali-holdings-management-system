import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { normalizeLocale } from '../i18n/locales'
import api from '../lib/api'
import { setPermissionsStaleHandler } from '../lib/authSync'
import { applyDisplayPrefs } from '../lib/format'
import type { User } from '../types'

const PERMISSIONS_POLL_MS = 60_000
const SOFT_REFRESH_COOLDOWN_MS = 15_000

type AuthContextValue = {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refresh: () => Promise<void>
  hasPermission: (slug: string) => boolean
  hasAnyPermission: (slugs: string[]) => boolean
  setLocale: (locale: string) => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

/** Stable snapshot of session fields that affect UI — skip setState when unchanged. */
function sessionFingerprint(user: User | null): string {
  if (!user) return ''
  return JSON.stringify({
    id: user.id,
    name: user.name,
    email: user.email,
    phone: user.phone ?? null,
    avatar_url: user.avatar_url ?? null,
    locale: user.locale,
    timezone: user.timezone,
    is_active: user.is_active,
    is_certified: user.is_certified ?? false,
    is_super_admin: user.is_super_admin,
    is_group_admin: user.is_group_admin,
    must_change_password: user.must_change_password ?? false,
    permissions: user.permissions,
    roles: user.roles?.map((role) => [role.id, role.slug, role.level]),
    accessible_company_ids: user.accessible_company_ids,
    accessible_office_ids: user.accessible_office_ids,
    accessible_module_slugs: user.accessible_module_slugs,
    primary_company: user.primary_company,
    primary_office: user.primary_office,
    accessible_companies: user.accessible_companies,
    assignments: user.assignments,
    preferences: user.preferences,
    features: user.features,
  })
}

async function loadPublicBootstrap() {
  try {
    const { data } = await api.get('/public/bootstrap')
    applyDisplayPrefs(data.data)
    return data.data
  } catch {
    return null
  }
}

async function groupDefaultLocale() {
  const bootstrap = await loadPublicBootstrap()
  return normalizeLocale(bootstrap?.default_locale)
}

async function applyLocale(
  i18n: { language?: string; changeLanguage: (lng: string) => Promise<unknown> },
  locale: string,
) {
  const next = normalizeLocale(locale)
  if (localStorage.getItem('lhms_locale') === next && (i18n.language || '').startsWith(next)) {
    return
  }
  localStorage.setItem('lhms_locale', next)
  await i18n.changeLanguage(next)
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const { i18n } = useTranslation()
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)
  const refreshInFlight = useRef<Promise<void> | null>(null)
  const userFingerprintRef = useRef('')

  const refresh = useCallback(async () => {
    if (!localStorage.getItem('lhms_token')) {
      userFingerprintRef.current = ''
      setUser(null)
      setLoading(false)
      return
    }

    if (refreshInFlight.current) {
      await refreshInFlight.current
      return
    }

    const run = (async () => {
      const { data } = await api.get('/auth/me')
      const next = data.data as User
      if (next && next.is_active === false) {
        localStorage.removeItem('lhms_token')
        userFingerprintRef.current = ''
        setUser(null)
        return
      }

      const fingerprint = sessionFingerprint(next)
      if (fingerprint !== userFingerprintRef.current) {
        userFingerprintRef.current = fingerprint
        setUser(next)
        applyDisplayPrefs({ ...next?.preferences, timezone: next?.timezone || next?.preferences?.timezone })
      }

      if (next?.locale) {
        await applyLocale(i18n, next.locale)
      }
    })()

    refreshInFlight.current = run.finally(() => {
      refreshInFlight.current = null
    })

    await refreshInFlight.current
  }, [i18n])

  useEffect(() => {
    void loadPublicBootstrap()
    refresh().catch(() => {
      localStorage.removeItem('lhms_token')
      userFingerprintRef.current = ''
      setUser(null)
    }).finally(() => setLoading(false))
    // Initial bootstrap only — later refreshes are driven by poll / visibility / 403.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Keep permissions in sync: light poll + refresh when the tab becomes visible again.
  // Avoid window "focus" — it fires constantly (modals, DevTools, OS switches) and felt like page reloads.
  useEffect(() => {
    if (!user?.id) return

    let lastSoftRefreshAt = 0
    const softRefresh = () => {
      if (!localStorage.getItem('lhms_token')) return
      const now = Date.now()
      if (now - lastSoftRefreshAt < SOFT_REFRESH_COOLDOWN_MS) return
      lastSoftRefreshAt = now
      void refresh().catch(() => {
        // Transient failures keep the session; 401 interceptor clears the token.
      })
    }

    const onVisibility = () => {
      if (document.visibilityState === 'visible') softRefresh()
    }

    const intervalId = window.setInterval(softRefresh, PERMISSIONS_POLL_MS)
    document.addEventListener('visibilitychange', onVisibility)

    return () => {
      window.clearInterval(intervalId)
      document.removeEventListener('visibilitychange', onVisibility)
    }
  }, [user?.id, refresh])

  useEffect(() => {
    setPermissionsStaleHandler(() => {
      if (!localStorage.getItem('lhms_token')) return
      void refresh().catch(() => {})
    })
    return () => setPermissionsStaleHandler(null)
  }, [refresh])

  const value = useMemo<AuthContextValue>(() => ({
    user,
    loading,
    login: async (email, password) => {
      const { data } = await api.post('/auth/login', { login: email, email, password })
      localStorage.setItem('lhms_token', data.data.token)
      const nextUser = data.data.user as User
      userFingerprintRef.current = sessionFingerprint(nextUser)
      setUser(nextUser)
      applyDisplayPrefs({ ...nextUser?.preferences, timezone: nextUser?.timezone || nextUser?.preferences?.timezone })
      await applyLocale(i18n, nextUser?.locale || 'fr')
    },
    logout: async () => {
      try {
        await api.post('/auth/logout')
      } finally {
        localStorage.removeItem('lhms_token')
        userFingerprintRef.current = ''
        setUser(null)
        await applyLocale(i18n, await groupDefaultLocale())
      }
    },
    refresh,
    hasPermission: (slug) => Boolean(user?.is_super_admin || user?.is_group_admin || user?.permissions?.includes(slug)),
    hasAnyPermission: (slugs) => Boolean(
      user?.is_super_admin
      || user?.is_group_admin
      || slugs.some((slug) => user?.permissions?.includes(slug)),
    ),
    setLocale: (locale) => {
      void (async () => {
        await applyLocale(i18n, locale)
        if (user) {
          await api.put('/auth/profile', { locale })
          setUser((current) => {
            if (!current) return current
            const next = { ...current, locale }
            userFingerprintRef.current = sessionFingerprint(next)
            return next
          })
        }
      })()
    },
  }), [user, loading, i18n, refresh])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider')
  }
  return context
}
