import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import api from '../lib/api'
import type { User } from '../types'

type AuthContextValue = {
  user: User | null
  loading: boolean
  login: (email: string, password: string) => Promise<void>
  logout: () => Promise<void>
  refresh: () => Promise<void>
  hasPermission: (slug: string) => boolean
  setLocale: (locale: string) => void
}

const AuthContext = createContext<AuthContextValue | null>(null)

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
    if (data.data?.locale) {
      localStorage.setItem('lhms_locale', data.data.locale)
      await i18n.changeLanguage(data.data.locale)
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
      const { data } = await api.post('/auth/login', { email, password })
      localStorage.setItem('lhms_token', data.data.token)
      setUser(data.data.user)
      if (data.data.user?.locale) {
        localStorage.setItem('lhms_locale', data.data.user.locale)
        await i18n.changeLanguage(data.data.user.locale)
      }
    },
    logout: async () => {
      try {
        await api.post('/auth/logout')
      } finally {
        localStorage.removeItem('lhms_token')
        setUser(null)
      }
    },
    refresh,
    hasPermission: (slug) => Boolean(user?.is_super_admin || user?.permissions?.includes(slug)),
    setLocale: (locale) => {
      localStorage.setItem('lhms_locale', locale)
      void i18n.changeLanguage(locale)
      if (user) {
        void api.put('/auth/profile', { locale })
      }
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
