import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Button, Field } from '../components/ui'
import { normalizeLocale } from '../i18n/locales'
import api from '../lib/api'

export function ResetPasswordPage() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [email, setEmail] = useState(params.get('email') || '')
  const [password, setPassword] = useState('')
  const [passwordConfirmation, setPasswordConfirmation] = useState('')
  const [errorKey, setErrorKey] = useState('')
  const [successKey, setSuccessKey] = useState('')
  const [loading, setLoading] = useState(false)
  const token = params.get('token') || ''

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

  useEffect(() => {
    const fromQuery = params.get('email')
    if (fromQuery) setEmail(fromQuery)
  }, [params])

  return (
    <div className="grid min-h-dvh lg:grid-cols-2">
      <section className="relative hidden overflow-hidden bg-lhms text-white lg:flex lg:flex-col lg:justify-between lg:p-14">
        <div className="absolute -right-24 -top-24 h-80 w-80 rounded-full bg-lime/30 blur-3xl" />
        <div className="absolute bottom-10 left-10 h-64 w-64 rounded-full bg-white/5 blur-2xl" />
        <div>
          <div className="text-xs font-semibold tracking-[0.32em] text-white/60">LALI HOLDINGS</div>
          <h1 className="mt-6 max-w-md text-5xl font-extrabold leading-tight">LHMS</h1>
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
            setErrorKey('')
            setSuccessKey('')
            if (!token) {
              setErrorKey('auth.resetInvalid')
              return
            }
            if (password !== passwordConfirmation) {
              setErrorKey('auth.passwordMismatch')
              return
            }
            setLoading(true)
            try {
              await api.post('/auth/reset-password', {
                token,
                email,
                password,
                password_confirmation: passwordConfirmation,
              })
              setSuccessKey('auth.resetSuccess')
              setTimeout(() => navigate('/login'), 1500)
            } catch (err: unknown) {
              const status = (err as { response?: { status?: number } }).response?.status
              setErrorKey(status === 403 ? 'auth.forgotInactive' : 'auth.resetError')
            } finally {
              setLoading(false)
            }
          }}
        >
          <div>
            <div className="mb-6 text-xs font-semibold tracking-[0.28em] text-lhms">LHMS</div>
            <h2 className="text-3xl font-extrabold">{t('auth.resetTitle')}</h2>
            <p className="mt-2 text-sm text-muted">{t('auth.resetHint')}</p>
          </div>
          <Field
            label={t('auth.email')}
            type="email"
            autoComplete="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
          <Field
            label={t('auth.newPassword')}
            type="password"
            autoComplete="new-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
          <Field
            label={t('auth.confirmPassword')}
            type="password"
            autoComplete="new-password"
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            required
          />
          {errorKey ? <div className="rounded-xl bg-danger-soft px-3 py-2 text-sm text-danger">{t(errorKey)}</div> : null}
          {successKey ? <div className="rounded-xl bg-lhms-soft px-3 py-2 text-sm text-lhms">{t(successKey)}</div> : null}
          <Button type="submit" className="w-full" disabled={loading || !token}>
            {loading ? t('common.loading') : t('auth.resetSubmit')}
          </Button>
          <div className="text-sm">
            <Link to="/login" className="font-medium text-lhms hover:underline">
              {t('auth.backToLogin')}
            </Link>
          </div>
        </form>
      </section>
    </div>
  )
}
