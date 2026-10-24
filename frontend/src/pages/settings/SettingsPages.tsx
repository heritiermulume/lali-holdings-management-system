import { ArrowLeft } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, Navigate, Outlet, useParams } from 'react-router-dom'
import { SearchCombobox, type SearchComboboxItem } from '../../components/SearchCombobox'
import { Button, Card, Empty, Field, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { SettingsMobileTabs } from '../../components/layout/SettingsMobileTabs'
import { useAuth } from '../../context/AuthContext'
import { useSystemModules } from '../../context/ModulesContext'
import { useLiveData } from '../../hooks/useLiveData'
import api from '../../lib/api'
import { trackLoadSpinner } from '../../lib/dataSync'
import { cn } from '../../lib/cn'
import { roleLabel } from '../../lib/format'
import { moduleRegistry, settingsNavItems } from '../../modules/registry'
import { moduleSettingFields, settingGroups, type SettingField, type SettingGroupId } from './catalog'

type RoleOption = { id: number; name: string; slug?: string; module_slug?: string | null }

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

function splitMailSections(fields: SettingField[]) {
  const prefix: SettingField[] = []
  const mail: SettingField[] = []
  const suffix: SettingField[] = []
  let phase: 'prefix' | 'mail' | 'suffix' = 'prefix'

  for (const field of fields) {
    const isMail = field.key.startsWith('mail_')
    if (phase === 'prefix') {
      if (isMail) {
        phase = 'mail'
        mail.push(field)
      } else {
        prefix.push(field)
      }
      continue
    }
    if (phase === 'mail') {
      if (isMail) {
        mail.push(field)
      } else {
        phase = 'suffix'
        suffix.push(field)
      }
      continue
    }
    suffix.push(field)
  }

  return { prefix, mail, suffix }
}

function SettingFieldsGrid({
  fields,
  values,
  onChange,
}: {
  fields: SettingField[]
  values: Record<string, string>
  onChange: (key: string, value: string) => void
}) {
  const { t } = useTranslation()
  const needsKamsRoles = fields.some((field) => field.type === 'combobox' && field.source === 'kams_roles')
  const [kamsRoles, setKamsRoles] = useState<RoleOption[]>([])

  useEffect(() => {
    if (!needsKamsRoles) return
    let cancelled = false
    api.get('/roles').then((res) => {
      if (cancelled) return
      const rows = (res.data.data ?? []) as RoleOption[]
      setKamsRoles(
        rows
          .filter((role) => role.module_slug === 'kams')
          .sort((a, b) => a.name.localeCompare(b.name)),
      )
    }).catch(() => {
      if (!cancelled) setKamsRoles([])
    })
    return () => { cancelled = true }
  }, [needsKamsRoles])

  const kamsRoleItems = useMemo<SearchComboboxItem[]>(
    () => kamsRoles.map((role) => ({
      id: role.name,
      label: roleLabel(t, role),
      hint: role.slug || undefined,
    })),
    [kamsRoles, t],
  )

  if (fields.length === 0) return null

  return (
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
                onClick={() => onChange(field.key, on ? '0' : '1')}
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
              <Select label={label} value={values[field.key] ?? ''} onChange={(e) => onChange(field.key, e.target.value)}>
                {field.options.map((option) => (
                  <option key={option.value} value={option.value}>{t(option.labelKey)}</option>
                ))}
              </Select>
              {helpText ? <p className="text-xs text-muted">{helpText}</p> : null}
            </div>
          )
        }
        if (field.type === 'combobox' && field.source === 'kams_roles') {
          const current = values[field.key] ?? ''
          const selected = kamsRoleItems.find((item) => item.id === current)
            ?? (current
              ? { id: current, label: t(`kams.rank.${current}`, { defaultValue: current }) }
              : null)
          return (
            <div key={field.key} className="space-y-1">
              <SearchCombobox
                label={label}
                value={selected}
                items={kamsRoleItems}
                placeholder={t('common.search')}
                emptyLabel={t('common.empty')}
                onSelect={(item) => onChange(field.key, item?.id ?? '')}
              />
              {helpText ? <p className="text-xs text-muted">{helpText}</p> : null}
            </div>
          )
        }
        const inputType = field.type === 'number'
          ? 'number'
          : field.type === 'password'
            ? 'password'
            : field.key.includes('email') || field.key.endsWith('_address')
              ? 'email'
              : 'text'
        return (
          <div key={field.key} className="space-y-1">
            <Field
              label={label}
              type={inputType}
              autoComplete={field.type === 'password' ? 'new-password' : undefined}
              value={values[field.key] ?? ''}
              onChange={(e) => onChange(field.key, e.target.value)}
            />
            {helpText ? <p className="text-xs text-muted">{helpText}</p> : null}
          </div>
        )
      })}
    </div>
  )
}

export function SettingsForm({
  group,
  fields,
}: {
  group: string
  fields: SettingField[]
}) {
  const { t } = useTranslation()
  const { refresh } = useAuth()
  const [values, setValues] = useState<Record<string, string>>({})
  const [baseline, setBaseline] = useState<Record<string, string>>({})
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [testingMail, setTestingMail] = useState(false)
  const [mailTestMessage, setMailTestMessage] = useState<string | null>(null)
  const [mailTestError, setMailTestError] = useState<string | null>(null)
  const { prefix, mail, suffix } = splitMailSections(fields)
  const showMailSection = group === 'general' && mail.length > 0

  const load = () => {
    const stop = trackLoadSpinner(setLoading)
    return api.get('/settings').then((res) => {
      const grouped = (res.data.data ?? {}) as Record<string, SettingRow[]>
      const rows = grouped[group] ?? []
      const next = snapshotValues(fields, {
        ...Object.fromEntries(rows.map((row) => [row.key, row.value ?? ''])),
      })
      setValues(next)
      setBaseline(next)
    }).catch(() => {
      const next = snapshotValues(fields, {})
      setValues(next)
      setBaseline(next)
    }).finally(stop)
  }

  useEffect(() => {
    void load()
  }, [group])

  const setField = (key: string, value: string) => {
    setValues((current) => ({ ...current, [key]: value }))
  }

  const dirty = formDirty(fields, baseline, values)

  useLiveData(load, { enabled: !dirty })

  const submit = async () => {
    if (!dirty) return
    setSaving(true)
    setMailTestMessage(null)
    setMailTestError(null)
    try {
      const res = await api.put('/settings', {
        settings: fields.map((field) => ({
          group,
          key: field.key,
          value: values[field.key] ?? '',
          type: field.type === 'boolean' ? 'boolean' : field.type === 'number' ? 'number' : 'string',
        })),
      })
      const grouped = (res.data.data ?? {}) as Record<string, SettingRow[]>
      const rows = grouped[group] ?? []
      const next = snapshotValues(fields, {
        ...Object.fromEntries(rows.map((row) => [row.key, row.value ?? ''])),
      })
      setValues(next)
      setBaseline(next)
      await refresh()
    } finally {
      setSaving(false)
    }
  }

  const sendTestMail = async () => {
    if (dirty) {
      setMailTestError(t('settings.mailTestSaveFirst'))
      return
    }
    setTestingMail(true)
    setMailTestMessage(null)
    setMailTestError(null)
    try {
      const res = await api.post('/settings/mail-test', {})
      setMailTestMessage(res.data.message ?? t('settings.mailTestSent'))
    } catch (error: unknown) {
      const message = (error as { response?: { data?: { message?: string } } })?.response?.data?.message
      setMailTestError(message || t('settings.mailTestFailed'))
    } finally {
      setTestingMail(false)
    }
  }

  if (loading && Object.keys(values).length === 0) {
    return <LoadingScreen />
  }

  return (
    <Card className="p-4 sm:p-6">
      <div className="space-y-6">
        <SettingFieldsGrid fields={prefix} values={values} onChange={setField} />

        {showMailSection ? (
          <section className="rounded-2xl border border-line bg-surface/40 p-4 sm:p-5">
            <div className="mb-4">
              <h3 className="text-sm font-semibold text-ink">{t('settings.mailSection')}</h3>
              <p className="mt-1 text-xs text-muted">{t('settings.mailSectionHelp')}</p>
            </div>
            <SettingFieldsGrid fields={mail} values={values} onChange={setField} />
            <div className="mt-4 flex flex-wrap items-center gap-3">
              <Button type="button" variant="secondary" disabled={saving || testingMail} onClick={() => void sendTestMail()}>
                {testingMail ? t('common.loading') : t('settings.mailTest')}
              </Button>
            </div>
            {mailTestMessage ? <p className="mt-3 text-sm text-lhms">{mailTestMessage}</p> : null}
            {mailTestError ? <p className="mt-3 text-sm text-danger">{mailTestError}</p> : null}
          </section>
        ) : (
          <SettingFieldsGrid fields={mail} values={values} onChange={setField} />
        )}

        <SettingFieldsGrid fields={suffix} values={values} onChange={setField} />
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
    !item.end && item.perm && hasPermission(item.perm)
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

function ModuleSettingsHeading({ slug }: { slug: string }) {
  const { t, i18n } = useTranslation()
  const { modules } = useSystemModules()
  const module = modules.find((entry) => entry.slug === slug)
  const name = module
    ? (i18n.language.startsWith('fr') ? module.name_fr : module.name)
    : slug

  return (
    <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1">
      <Link
        to="/administration/settings/companies"
        className="inline-flex shrink-0 items-center gap-1.5 text-sm font-semibold text-lhms hover:underline"
      >
        <ArrowLeft size={16} />
        {t('settings.backToCompanies')}
      </Link>
      <h2 className="text-xl font-bold text-ink">{t('settings.parameter')} - {name}</h2>
    </div>
  )
}

export function ModuleSettingsPage() {
  const { slug = '' } = useParams()
  const { t } = useTranslation()
  const fields = moduleSettingFields[slug] ?? []

  if (fields.length === 0) {
    return (
      <div>
        <ModuleSettingsHeading slug={slug} />
        <Card className="p-6">
          <p className="text-sm text-muted">{t('settings.moduleEmpty')}</p>
        </Card>
      </div>
    )
  }

  return (
    <div>
      <ModuleSettingsHeading slug={slug} />
      <SettingsForm group={slug} fields={fields} />
    </div>
  )
}

export function SettingsWorkspace() {
  const { hasPermission } = useAuth()
  const items = settingsNavItems().filter((item) => (
    item.anyPerm?.length ? item.anyPerm.some((perm) => hasPermission(perm)) : Boolean(!item.perm || hasPermission(item.perm))
  ))

  return (
    <div>
      <SettingsMobileTabs items={items} />
      <Outlet />
    </div>
  )
}
