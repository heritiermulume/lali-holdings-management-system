import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import { normalizeLocale } from '../i18n/locales'
import { Button, Field } from '../components/ui'
import api from '../lib/api'

const laliLogo = '/lali_holdings_logo.png'

export function LoginPage() {
  const { t, i18n } = useTranslation()
  const { login } = useAuth()
  const navigate = useNavigate()
  const [mode, setMode] = useState<'login' | 'forgot'>('login')
  const [email, setEmail] = useState(() => localStorage.getItem('lhms_remember_login') || '')
  const [password, setPassword] = useState('')
  const [rememberMe, setRememberMe] = useState(() => Boolean(localStorage.getItem('lhms_remember_login')))
  const [errorKey, setErrorKey] = useState('')
  const [successKey, setSuccessKey] = useState('')
  const [loading, setLoading] = useState(false)

  useEffect(() => {
    let cancelled = false
    api.get('/public/bootstrap').then((res) => {
      if (cancelled || localStorage.getItem('lhms_locale_picked')) return
      const locale = normalizeLocale(res.data.data?.default_locale)
      localStorage.setItem('lhms_locale', locale)
      void i18n.changeLanguage(locale)
    }).catch(() => undefined)
    return () => {
      cancelled = true
    }
  }, [i18n])

  const switchLanguage = (next: string) => {
    const locale = normalizeLocale(next)
    localStorage.setItem('lhms_locale_picked', '1')
    localStorage.setItem('lhms_locale', locale)
    void i18n.changeLanguage(locale)
  }

  return (
    <div className="fixed inset-0 flex flex-col overflow-y-auto lg:grid lg:grid-cols-2 lg:grid-rows-1">
      <section className="relative hidden overflow-hidden bg-lhms text-white lg:flex lg:flex-col lg:justify-between lg:p-14">
        <div className="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-lime/30 blur-3xl" />
        <div className="absolute bottom-10 left-10 h-64 w-64 rounded-full bg-white/5 blur-2xl" />
        <div className="relative z-10 flex flex-1 flex-col items-center justify-center text-center">
          <img src={laliLogo} alt="Lali Holdings" className="h-72 w-auto object-contain drop-shadow-lg" />
          <h1 className="mt-8 w-full max-w-lg text-center text-4xl font-extrabold leading-tight">{t('app.full')}</h1>
        </div>
        <ul className="relative z-10 space-y-3 text-center text-sm text-white/80">
          <li>{t('auth.copyright')}</li>
          <li>
            <Link to="/terms" className="hover:text-white hover:underline">
              {t('auth.terms')}
            </Link>
          </li>
          <li>
            <Link to="/privacy" className="hover:text-white hover:underline">
              {t('auth.privacy')}
            </Link>
          </li>
        </ul>
      </section>

      <section className="flex min-h-0 flex-1 flex-col bg-[radial-gradient(circle_at_top,_#e8f3ea_0%,_#f4f7f5_45%,_#ffffff_100%)] px-5 py-5 lg:items-center lg:justify-center lg:bg-none lg:py-10">
        <div className="flex flex-1 items-start justify-center lg:flex-none lg:items-center">
          {mode === 'login' ? (
            <form
              className="w-full max-w-md space-y-4 lg:space-y-5"
              onSubmit={async (event) => {
                event.preventDefault()
                setErrorKey('')
                setSuccessKey('')
                setLoading(true)
                try {
                  await login(email, password)
                  if (rememberMe) {
                    localStorage.setItem('lhms_remember_login', email)
                  } else {
                    localStorage.removeItem('lhms_remember_login')
                  }
                  navigate('/')
                } catch {
                  setErrorKey('auth.error')
                } finally {
                  setLoading(false)
                }
              }}
            >
              <div className="text-center">
                <img src={laliLogo} alt="Lali Holdings" className="mx-auto mb-3 h-32 w-auto object-contain lg:hidden" />
                <div className="mb-2 text-xs font-semibold tracking-wide text-lhms lg:hidden">{t('app.full')}</div>
                <h2 className="text-3xl font-extrabold">{t('auth.welcome')}</h2>
              </div>
              <div className="space-y-5 rounded-3xl border border-white/80 bg-white/90 p-5 shadow-[0_18px_50px_rgba(4,55,17,0.10)] ring-1 ring-lhms/5 backdrop-blur-sm lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none lg:ring-0 lg:backdrop-blur-none">
                <Field label={t('auth.login')} type="text" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required />
                <div className="space-y-1.5">
                  <Field label={t('auth.password')} type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
                  <div className="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
                    <button
                      type="button"
                      className="text-sm font-medium text-lhms hover:underline"
                      onClick={() => {
                        setMode('forgot')
                        setErrorKey('')
                        setSuccessKey('')
                      }}
                    >
                      {t('auth.forgotPassword')}
                    </button>
                    <label className="inline-flex cursor-pointer items-center gap-2 text-sm text-muted">
                      <input
                        type="checkbox"
                        className="size-4 rounded border-line text-lhms accent-lhms"
                        checked={rememberMe}
                        onChange={(e) => setRememberMe(e.target.checked)}
                      />
                      <span>{t('auth.rememberMe')}</span>
                    </label>
                  </div>
                </div>
                {errorKey ? <div className="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">{t(errorKey)}</div> : null}
                <Button type="submit" className="w-full" disabled={loading}>
                  {loading ? t('common.loading') : t('auth.submit')}
                </Button>
              </div>
              <div className="flex items-center justify-end text-sm text-muted">
                <select
                  aria-label={t('settings.fields.default_locale')}
                  value={normalizeLocale(i18n.language)}
                  onChange={(event) => switchLanguage(event.target.value)}
                  className="rounded-lg border border-line bg-white px-2 py-1"
                >
                  <option value="fr">FR</option>
                  <option value="en">EN</option>
                  <option value="sw">SW</option>
                </select>
              </div>
            </form>
          ) : (
            <form
              className="w-full max-w-md space-y-5"
              onSubmit={async (event) => {
                event.preventDefault()
                setErrorKey('')
                setSuccessKey('')
                setLoading(true)
                try {
                  await api.post('/auth/forgot-password', { email })
                  setSuccessKey('auth.forgotSent')
                } catch (err) {
                  const status = (err as { response?: { status?: number } }).response?.status
                  if (status === 404) setErrorKey('auth.forgotUnknown')
                  else if (status === 403) setErrorKey('auth.forgotInactive')
                  else setErrorKey('auth.forgotError')
                } finally {
                  setLoading(false)
                }
              }}
            >
              <div className="text-center">
                <img src={laliLogo} alt="Lali Holdings" className="mx-auto mb-3 h-32 w-auto object-contain lg:hidden" />
                <div className="mb-2 text-xs font-semibold tracking-wide text-lhms lg:hidden">{t('app.full')}</div>
                <h2 className="text-3xl font-extrabold">{t('auth.forgotTitle')}</h2>
                <p className="mt-2 text-sm text-muted">{t('auth.forgotHint')}</p>
              </div>
              <div className="space-y-5 rounded-3xl border border-white/80 bg-white/90 p-5 shadow-[0_18px_50px_rgba(4,55,17,0.10)] ring-1 ring-lhms/5 backdrop-blur-sm lg:rounded-none lg:border-0 lg:bg-transparent lg:p-0 lg:shadow-none lg:ring-0 lg:backdrop-blur-none">
                <Field label={t('auth.email')} type="email" autoComplete="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
                {errorKey ? <div className="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">{t(errorKey)}</div> : null}
                {successKey ? <div className="rounded-xl bg-lhms-soft px-3 py-2 text-sm text-lhms">{t(successKey)}</div> : null}
                <Button type="submit" className="w-full" disabled={loading}>
                  {loading ? t('common.loading') : t('auth.forgotSubmit')}
                </Button>
              </div>
              <div className="flex items-center justify-between text-sm">
                <button
                  type="button"
                  className="font-medium text-lhms hover:underline"
                  onClick={() => {
                    setMode('login')
                    setErrorKey('')
                    setSuccessKey('')
                  }}
                >
                  {t('auth.backToLogin')}
                </button>
                <select
                  aria-label={t('settings.fields.default_locale')}
                  value={normalizeLocale(i18n.language)}
                  onChange={(event) => switchLanguage(event.target.value)}
                  className="rounded-lg border border-line bg-white px-2 py-1 text-muted"
                >
                  <option value="fr">FR</option>
                  <option value="en">EN</option>
                  <option value="sw">SW</option>
                </select>
              </div>
            </form>
          )}
        </div>
        <ul className="mt-auto space-y-1.5 pt-6 text-center text-xs text-muted lg:hidden">
          <li>{t('auth.copyright')}</li>
          <li>
            <Link to="/terms" className="hover:text-lhms hover:underline">
              {t('auth.terms')}
            </Link>
          </li>
          <li>
            <Link to="/privacy" className="hover:text-lhms hover:underline">
              {t('auth.privacy')}
            </Link>
          </li>
        </ul>
      </section>
    </div>
  )
}
