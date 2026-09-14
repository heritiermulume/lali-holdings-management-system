import { Camera } from 'lucide-react'
import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../context/AuthContext'
import api from '../lib/api'
import { Button, Field, Modal } from './ui'
import { UserAvatar } from './UserAvatar'

export function ProfileModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const { user, refresh } = useAuth()
  const photoInput = useRef<HTMLInputElement>(null)
  const [form, setForm] = useState({ first_name: '', last_name: '', phone: '', timezone: '' })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [uploading, setUploading] = useState(false)
  const [photoError, setPhotoError] = useState('')

  useEffect(() => {
    if (!open || !user) return
    setForm({
      first_name: user.first_name || '',
      last_name: user.last_name || '',
      phone: user.phone || '',
      timezone: user.timezone || '',
    })
    setSaved(false)
    setPhotoError('')
  }, [open, user])

  const uploadPhoto = async (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return

    const data = new FormData()
    data.append('avatar', file)
    setUploading(true)
    setPhotoError('')
    try {
      await api.post('/auth/avatar', data)
      await refresh()
    } catch {
      setPhotoError(t('users.photoError'))
    } finally {
      setUploading(false)
    }
  }

  return (
    <Modal open={open} title={t('settings.profile')} onClose={onClose}>
      <div className="space-y-3">
        <div className="flex flex-col items-center gap-2 pb-2">
          <button
            type="button"
            disabled={uploading}
            onClick={() => photoInput.current?.click()}
            aria-label={t('users.photo')}
            className="relative disabled:opacity-60"
          >
            <span className="grid h-24 w-24 overflow-hidden place-items-center rounded-full bg-lhms text-2xl font-bold text-white ring-4 ring-lhms-soft">
              <UserAvatar user={user} textClassName="text-2xl font-bold text-white" />
            </span>
            <span className="absolute -bottom-0.5 -right-0.5 grid h-8 w-8 place-items-center rounded-full bg-ink text-white shadow-md">
              <Camera size={14} />
            </span>
          </button>
          <input
            ref={photoInput}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={(event) => void uploadPhoto(event)}
          />
          <p className="text-center text-xs text-muted">
            {uploading ? t('common.loading') : t('users.photoHint')}
          </p>
          {photoError ? <p className="text-center text-xs text-danger">{photoError}</p> : null}
        </div>
        <Field
          label={t('users.firstName')}
          value={form.first_name}
          onChange={(e) => setForm({ ...form, first_name: e.target.value })}
        />
        <Field
          label={t('users.lastName')}
          value={form.last_name}
          onChange={(e) => setForm({ ...form, last_name: e.target.value })}
        />
        <Field label={t('users.email')} type="email" value={user?.email || ''} readOnly />
        <Field
          label={t('users.phone')}
          value={form.phone}
          onChange={(e) => setForm({ ...form, phone: e.target.value })}
        />
        <Field label={t('users.role')} value={user?.roles?.[0]?.name || '—'} readOnly />
        <Field label={t('users.company')} value={user?.primary_company?.name || '—'} readOnly />
        <Field label={t('users.office')} value={user?.primary_office?.name || '—'} readOnly />
        <Field
          label={t('users.timezone')}
          value={form.timezone}
          onChange={(e) => setForm({ ...form, timezone: e.target.value })}
        />
        <div className="flex flex-col gap-2 pt-2 sm:flex-row">
          <Button
            disabled={saving}
            onClick={async () => {
              setSaving(true)
              try {
                await api.put('/auth/profile', form)
                await refresh()
                setSaved(true)
              } finally {
                setSaving(false)
              }
            }}
          >
            {t('common.save')}
          </Button>
          <Button variant="ghost" onClick={onClose}>{t('common.close')}</Button>
        </div>
        {saved ? <p className="text-sm text-lime">{t('common.saved')}</p> : null}
      </div>
    </Modal>
  )
}
