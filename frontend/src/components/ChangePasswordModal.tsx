import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import api from '../lib/api'
import { Button, Field, Modal } from './ui'

export function ChangePasswordModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({
    current_password: '',
    password: '',
    password_confirmation: '',
  })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    if (!open) return
    setForm({ current_password: '', password: '', password_confirmation: '' })
    setSaved(false)
    setError('')
  }, [open])

  const save = async () => {
    if (form.password !== form.password_confirmation) {
      setError(t('auth.passwordMismatch'))
      return
    }
    setSaving(true)
    setSaved(false)
    setError('')
    try {
      await api.put('/auth/password', form)
      setSaved(true)
      setForm({ current_password: '', password: '', password_confirmation: '' })
    } catch (err) {
      const response = (err as {
        response?: { data?: { message?: string; errors?: Record<string, string[]> } }
      }).response?.data
      const fieldErrors = response?.errors ? Object.values(response.errors).flat().filter(Boolean) : []
      setError(fieldErrors[0] || response?.message || t('auth.passwordChangeError'))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open={open} title={t('auth.changePassword')} onClose={onClose}>
      <div className="space-y-3">
        <Field
          label={t('auth.currentPassword')}
          type="password"
          autoComplete="current-password"
          value={form.current_password}
          onChange={(e) => setForm({ ...form, current_password: e.target.value })}
          required
        />
        <Field
          label={t('auth.newPassword')}
          type="password"
          autoComplete="new-password"
          value={form.password}
          onChange={(e) => setForm({ ...form, password: e.target.value })}
          required
        />
        <Field
          label={t('auth.confirmPassword')}
          type="password"
          autoComplete="new-password"
          value={form.password_confirmation}
          onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })}
          required
        />
        <div className="flex flex-row gap-2 pt-2">
          <Button disabled={saving} onClick={() => void save()}>
            {saving ? t('common.loading') : t('common.save')}
          </Button>
          <Button variant="ghost" onClick={onClose}>{t('common.close')}</Button>
        </div>
        {error ? <p className="text-sm text-danger">{error}</p> : null}
        {saved ? <p className="text-sm text-lime">{t('auth.passwordUpdated')}</p> : null}
      </div>
    </Modal>
  )
}
