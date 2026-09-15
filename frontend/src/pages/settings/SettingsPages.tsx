import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate, useParams } from 'react-router-dom'
import { Button, Card, Empty, Field, Select } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { cn } from '../../lib/cn'
import { moduleRegistry } from '../../modules/registry'
import { moduleSettingFields, settingDefaults, settingGroups, type SettingField, type SettingGroupId } from './catalog'

type SettingRow = { group: string; key: string; value: string | null; type?: string }

function boolValue(value: string) {
  return value === '1' || value === 'true'
}

function snapshotValues(fields: SettingField[], source: Record<string, string>) {
  const next: Record<string, string> = {}
  for (const field of fields) {
    const raw = source[field.key] ?? ''
    next[field.key] = field.type === 'boolean' ? (boolValue(raw) ? '1' : '0') : raw
  }
  return next
}

function formDirty(fields: SettingField[], saved: Record<string, string>, current: Record<string, string>) {
  return fields.some((field) => (saved[field.key] ?? '') !== (current[field.key] ?? ''))
}

export function SettingsForm({
  group,
  fields,
}: {
  group: string
  fields: SettingField[]
}) {
  const { t } = useTranslation()
  const [values, setValues] = useState<Record<string, string>>({})
  const [baseline, setBaseline] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    let cancelled = false
    setLoading(true)
    api.get('/settings').then((res) => {
      if (cancelled) return
      const grouped = (res.data.data ?? {}) as Record<string, SettingRow[]>
      const rows = grouped[group] ?? []
      const next = snapshotValues(fields, {
        ...(settingDefaults[group] ?? {}),
        ...Object.fromEntries(rows.map((row) => [row.key, row.value ?? ''])),
      })
      setValues(next)
      setBaseline(next)
    }).catch(() => {
      if (!cancelled) {
        const next = snapshotValues(fields, settingDefaults[group] ?? {})
        setValues(next)
        setBaseline(next)
      }
    }).finally(() => {
      if (!cancelled) setLoading(false)
    })
    return () => { cancelled = true }
  }, [group])

  const setField = (key: string, value: string) => {
    setValues((current) => ({ ...current, [key]: value }))
  }

  const dirty = formDirty(fields, baseline, values)

  const submit = async () => {
    if (!dirty) return
    setSaving(true)
    try {
      await api.put('/settings', {
        settings: fields.map((field) => ({
          group,
          key: field.key,
          value: values[field.key] ?? '',
          type: field.type === 'boolean' ? 'boolean' : field.type === 'number' ? 'number' : 'string',
        })),
      })
      setBaseline({ ...values })
    } finally {
      setSaving(false)
    }
  }

  if (loading) {
    return <p className="text-sm text-muted">{t('common.loading')}</p>
  }

  return (
    <Card className="p-4 sm:p-6">
      <div className="grid gap-4 sm:grid-cols-2">
        {fields.map((field) => {
          const label = t(`settings.fields.${field.key}`)
          const helpKey = `settings.fields.${field.key}Help`
          const help = t(helpKey)
          const helpText = help === helpKey ? null : help
          if (field.type === 'boolean') {
            const on = boolValue(values[field.key] ?? '0')
            return (
              <label key={field.key} className="flex items-start justify-between gap-4 rounded-2xl border border-line bg-surface/60 px-4 py-3 sm:col-span-2">
                <span>
                  <span className="block text-sm font-semibold text-ink">{label}</span>
                  {helpText ? <span className="mt-1 block text-xs text-muted">{helpText}</span> : null}
                </span>
                <button
                  type="button"
                  role="switch"
                  aria-checked={on}
                  onClick={() => setField(field.key, on ? '0' : '1')}
                  className={cn('relative h-7 w-12 shrink-0 rounded-full transition', on ? 'bg-lhms' : 'bg-line')}
                >
                  <span
                    className={cn('absolute top-0.5 h-6 w-6 rounded-full bg-white shadow transition', on ? 'left-[1.375rem]' : 'left-0.5')}
                  />
                </button>
              </label>
            )
          }
          if (field.type === 'select' && field.options) {
            return (
              <div key={field.key} className="space-y-1">
                <Select label={label} value={values[field.key] ?? ''} onChange={(e) => setField(field.key, e.target.value)}>
                  {field.options.map((option) => (
                    <option key={option.value} value={option.value}>{t(option.labelKey)}</option>
                  ))}
                </Select>
                {helpText ? <p className="text-xs text-muted">{helpText}</p> : null}
              </div>
            )
          }
          return (
            <div key={field.key} className="space-y-1">
              <Field
                label={label}
                type={field.type === 'number' ? 'number' : field.key.includes('email') ? 'email' : 'text'}
                value={values[field.key] ?? ''}
                onChange={(e) => setField(field.key, e.target.value)}
              />
              {helpText ? <p className="text-xs text-muted">{helpText}</p> : null}
            </div>
          )
        })}
      </div>
      <div className="mt-6 flex flex-wrap items-center gap-3">
        <Button type="button" disabled={!dirty || saving} onClick={() => void submit()}>
          {saving ? t('common.loading') : dirty ? t('common.save') : t('common.saved')}
        </Button>
      </div>
    </Card>
  )
}

export function SettingsHome() {
  const { t } = useTranslation()
  const { hasPermission } = useAuth()

  if (hasPermission('settings.manage')) {
    return <GeneralSettingsPage />
  }

  const next = moduleRegistry.settings.items.find((item) => (
    item.to !== '/settings' && item.perm && hasPermission(item.perm)
  ))

  if (next) return <Navigate to={next.to} replace />
  return <Empty>{t('common.noAccess')}</Empty>
}

export function GeneralSettingsPage() {
  return <SettingsForm group="general" fields={settingGroups.general} />
}

export function GroupSettingsPage({ group }: { group: SettingGroupId }) {
  return <SettingsForm group={group} fields={settingGroups[group]} />
}

export function ModuleSettingsPage() {
  const { slug = '' } = useParams()
  const { t } = useTranslation()
  const fields = moduleSettingFields[slug] ?? []

  if (fields.length === 0) {
    return (
      <Card className="p-6">
        <p className="text-sm text-muted">{t('settings.moduleEmpty')}</p>
      </Card>
    )
  }

  return <SettingsForm group={slug} fields={fields} />
}
