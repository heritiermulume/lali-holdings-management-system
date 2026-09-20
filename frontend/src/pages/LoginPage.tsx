import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { Button, Field } from '../components/ui'
import api from '../lib/api'

export function LoginPage() {
  const { t, i18n } = useTranslation()
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('samuel.w@example.com')
  const [password, setPassword] = useState('Password123!')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    api.get('/public/bootstrap').then((res) => {
      const locale = res.data.data?.default_locale === 'en' ? 'en' : 'fr'
      localStorage.setItem('lhms_locale', locale)
      void i18n.changeLanguage(locale)
    }).catch(() => undefined)
  }, [i18n])

  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      <section className="relative hidden overflow-hidden bg-lhms text-white lg:flex lg:flex-col lg:justify-between lg:p-14">
        <div className="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-lime/30 blur-3xl" />
        <div className="absolute bottom-10 left-10 h-64 w-64 rounded-full bg-white/5 blur-2xl" />
        <div>
          <div className="text-xs font-semibold tracking-[0.32em] text-white/60">LALI HOLDINGS</div>
          <h1 className="mt-6 max-w-md text-5xl font-extrabold leading-tight">LHMS</h1>
          <p className="mt-4 max-w-md text-lg text-white/75">{t('app.tagline')}</p>
        </div>
        <ul className="space-y-3 text-sm text-white/80">
          <li>{t('auth.featureMulti')}</li>
          <li>{t('auth.featureSecurity')}</li>
          <li>{t('auth.featureScale')}</li>
        </ul>
      </section>

      <section className="flex items-center justify-center px-5 py-10">
        <form
          className="w-full max-w-md space-y-5"
          onSubmit={async (event) => {
            event.preventDefault()
            setError('')
            setLoading(true)
            try {
              await login(email, password)
              navigate('/')
            } catch {
              setError(t('auth.error'))
            } finally {
              setLoading(false)
            }
          }}
        >
          <div>
            <div className="mb-6 text-xs font-semibold tracking-[0.28em] text-lhms">LHMS</div>
            <h2 className="text-3xl font-extrabold">{t('auth.welcome')}</h2>
            <p className="mt-2 text-sm text-muted">{t('auth.signin')}</p>
          </div>
          <Field label={t('auth.login')} type="text" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
          <Field label={t('auth.password')} type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          {error ? <div className="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">{error}</div> : null}
          <Button type="submit" className="w-full" disabled={loading}>
            {loading ? t('common.loading') : t('auth.submit')}
          </Button>
          <div className="flex items-center justify-between text-sm text-muted">
            <span>{t('auth.hint')}</span>
            <button
              type="button"
              onClick={() => {
                const next = i18n.language.startsWith('fr') ? 'en' : 'fr'
                localStorage.setItem('lhms_locale', next)
                void i18n.changeLanguage(next)
              }}
            >
              {i18n.language.startsWith('fr') ? 'EN' : 'FR'}
            </button>
          </div>
        </form>
      </section>
    </div>
  )
}
