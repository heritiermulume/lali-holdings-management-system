import { Camera, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useAuth } from '../context/AuthContext'
import api from '../lib/api'
import { roleLabel } from '../lib/format'
import { Button, Field, Modal } from './ui'
import { UserAvatar } from './UserAvatar'

export function ProfileModal({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const { user, refresh } = useAuth()
  const photoInput = useRef<HTMLInputElement>(null)
  const [form, setForm] = useState({ first_name: '', last_name: '', phone: '', timezone: '' })
  const [saving, setSaving] = useState(false)
  const [saved, setSaved] = useState(false)
  const [photo, setPhoto] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(null)
  const [photoRemoved, setPhotoRemoved] = useState(false)
  const [photoError, setPhotoError] = useState('')

  const clearPreview = () => {
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return null
    })
  }

  useEffect(() => {
    if (!open || !user) return
    setForm({
      first_name: user.first_name || '',
      last_name: user.last_name || '',
      phone: user.phone || '',
      timezone: user.timezone || '',
    })
    setPhoto(null)
    setPhotoRemoved(false)
    setSaved(false)
    setPhotoError('')
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return user.avatar_url || null
    })
  }, [open, user])

  const pickPhoto = (event: ChangeEvent<HTMLInputElement>) => {
    const file = event.target.files?.[0]
    event.target.value = ''
    if (!file) return
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
      setPhotoError(t('users.photoError'))
      return
    }
    setPhotoError('')
    setPhoto(file)
    setPhotoRemoved(false)
    setPhotoPreview((current) => {
      if (current?.startsWith('blob:')) URL.revokeObjectURL(current)
      return URL.createObjectURL(file)
    })
  }

  const clearPhoto = () => {
    setPhoto(null)
    setPhotoRemoved(true)
    setPhotoError('')
    clearPreview()
  }

  const save = async () => {
    setSaving(true)
    setSaved(false)
    try {
      const payload = new FormData()
      payload.append('_method', 'PUT')
      payload.append('first_name', form.first_name)
      payload.append('last_name', form.last_name)
      payload.append('phone', form.phone)
      payload.append('timezone', form.timezone)
      if (photo) payload.append('avatar', photo)
      if (photoRemoved && !photo) payload.append('remove_avatar', '1')
      await api.post('/auth/profile', payload)
      await refresh()
      setSaved(true)
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open={open} title={t('settings.profile')} onClose={onClose}>
      <div className="space-y-3">
        <div className="flex flex-col items-center gap-2 pb-2">
          <div className="relative h-24 w-24">
            <button
              type="button"
              onClick={() => photoInput.current?.click()}
              aria-label={t('users.photo')}
              className="block h-24 w-24 overflow-hidden rounded-full p-0 ring-4 ring-lhms-soft"
            >
              <span className="grid h-24 w-24 overflow-hidden place-items-center rounded-full bg-lhms text-2xl font-bold text-white">
                <UserAvatar
                  user={user ? { ...user, avatar_url: photoPreview } : null}
                  textClassName="text-2xl font-bold text-white"
                />
              </span>
            </button>
            {photoPreview ? (
              <button
                type="button"
                aria-label={t('users.removePhoto')}
                className="absolute bottom-0 left-0 grid h-8 w-8 -translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
                onClick={clearPhoto}
              >
                <Trash2 size={14} />
              </button>
            ) : null}
            <button
              type="button"
              aria-label={t('users.photo')}
              className="absolute bottom-0 right-0 grid h-8 w-8 translate-x-1/4 translate-y-1/4 place-items-center rounded-full bg-ink p-0 text-white shadow-md"
              onClick={() => photoInput.current?.click()}
            >
              <Camera size={14} />
            </button>
          </div>
          <input
            ref={photoInput}
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="hidden"
            onChange={pickPhoto}
          />
          <p className="text-center text-xs text-muted">{t('users.photoHint')}</p>
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
        <Field label={t('users.role')} value={roleLabel(t, user?.roles?.[0]) || '—'} readOnly />
        <Field label={t('users.company')} value={user?.primary_company?.name || '—'} readOnly />
        <Field label={t('users.office')} value={user?.primary_office?.name || '—'} readOnly />
        <Field
          label={t('users.timezone')}
          value={form.timezone}
          onChange={(e) => setForm({ ...form, timezone: e.target.value })}
        />
        <div className="flex flex-col gap-2 pt-2 sm:flex-row">
          <Button disabled={saving} onClick={() => void save()}>
            {saving ? t('common.loading') : t('common.save')}
          </Button>
          <Button variant="ghost" onClick={onClose}>{t('common.close')}</Button>
        </div>
        {saved ? <p className="text-sm text-lime">{t('common.saved')}</p> : null}
      </div>
    </Modal>
  )
}
