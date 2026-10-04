import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import api from '../lib/api'
import { applyDisplayPrefs } from '../lib/format'
import type { User } from '../types'

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

async function groupDefaultLocale() {
  try {
    const { data } = await api.get('/public/bootstrap')
    return data.data?.default_locale === 'en' ? 'en' : 'fr'
  } catch {
    return 'fr'
  }
}

async function applyLocale(i18n: { changeLanguage: (lng: string) => Promise<unknown> }, locale: string) {
  const next = locale.startsWith('en') ? 'en' : 'fr'
  localStorage.setItem('lhms_locale', next)
  await i18n.changeLanguage(next)
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const { i18n } = useTranslation()
  const [user, setUser] = useState<User | null>(null)
  const [loading, setLoading] = useState(true)

  const refresh = async () => {
    if (!localStorage.getItem('lhms_token')) {
      setUser(null)
      setLoading(false)
      return
    }
    const { data } = await api.get('/auth/me')
    setUser(data.data)
    applyDisplayPrefs({ ...data.data?.preferences, timezone: data.data?.timezone || data.data?.preferences?.timezone })
    if (data.data?.locale) {
      await applyLocale(i18n, data.data.locale)
    }
  }

  useEffect(() => {
    refresh().catch(() => {
      localStorage.removeItem('lhms_token')
      setUser(null)
    }).finally(() => setLoading(false))
  }, [])

  const value = useMemo<AuthContextValue>(() => ({
    user,
    loading,
    login: async (email, password) => {
      const { data } = await api.post('/auth/login', { login: email, email, password })
      localStorage.setItem('lhms_token', data.data.token)
      setUser(data.data.user)
      applyDisplayPrefs({ ...data.data.user?.preferences, timezone: data.data.user?.timezone || data.data.user?.preferences?.timezone })
      await applyLocale(i18n, data.data.user?.locale || 'fr')
    },
    logout: async () => {
      try {
        await api.post('/auth/logout')
      } finally {
        localStorage.removeItem('lhms_token')
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
          setUser((current) => current ? { ...current, locale } : current)
        }
      })()
    },
  }), [user, loading, i18n])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider')
  }
  return context
}
