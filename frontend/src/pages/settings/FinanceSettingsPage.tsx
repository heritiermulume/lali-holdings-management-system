import { Info } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { useAuth } from '../../context/AuthContext'
import api from '../../lib/api'
import { settingGroups } from './catalog'
import { SettingsForm } from './SettingsPages'

type CountryTax = {
  id: number
  name: string
  name_fr: string
  iso_code: string
  currency_code: string
  tax_name: string
  tax_rate: number
}

type CatalogItem = { code: string; name: string; name_fr: string; symbol: string; enabled: boolean }

type RevenueRule = {
  id: number
  code: string
  role_slug: string
  label: string
  percent: number
  divisor?: number | null
  scope: string
  is_active: boolean
}

function countryCurrencyDirty(saved: CountryTax, draft: CountryTax) {
  return saved.currency_code !== draft.currency_code
}

function countryTaxDirty(saved: CountryTax, draft: CountryTax) {
  return (saved.tax_name ?? '') !== (draft.tax_name ?? '')
    || Number(saved.tax_rate) !== Number(draft.tax_rate)
}

export function FinanceSettingsPage() {
  const { t, i18n } = useTranslation()
  const { refresh } = useAuth()
  const localeFr = i18n.language.startsWith('fr')
  const [countries, setCountries] = useState<CountryTax[]>([])
  const [drafts, setDrafts] = useState<Record<number, CountryTax>>({})
  const [catalog, setCatalog] = useState<CatalogItem[]>([])
  const [savingCountry, setSavingCountry] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [rules, setRules] = useState<RevenueRule[]>([])
  const [ruleDrafts, setRuleDrafts] = useState<Record<number, string>>({})
  const [savingRuleId, setSavingRuleId] = useState<number | null>(null)
  const [kamsError, setKamsError] = useState('')
  const [infoRule, setInfoRule] = useState<RevenueRule | null>(null)
  const [loading, setLoading] = useState(true)

  const load = async () => {
    const [finance, list] = await Promise.all([
      api.get('/finance'),
      api.get('/finance/catalog'),
    ])
    const data = finance.data.data
    const countryRows = (data.countries ?? []) as CountryTax[]
    setCountries(countryRows)
    setDrafts(Object.fromEntries(countryRows.map((row) => [row.id, row])))
    setCatalog(list.data.data ?? [])
  }

  const loadKamsRevenue = async () => {
    setKamsError('')
    try {
      const ruleRes = await api.get('/modules/kams/revenue/rules')
      setRules(ruleRes.data.data ?? [])
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } }).response?.data?.message
      setKamsError(message || t('settings.kamsRevenue.empty'))
      setRules([])
    }
  }

  useEffect(() => {
    setLoading(true)
    Promise.all([
      load().catch(() => undefined),
      loadKamsRevenue().catch(() => undefined),
    ]).finally(() => setLoading(false))
  }, [])

  const saveRule = async (rule: RevenueRule) => {
    const raw = ruleDrafts[rule.id] ?? String(rule.percent)
    if (Number(raw) === Number(rule.percent)) return
    setSavingRuleId(rule.id)
    setKamsError('')
    try {
      await api.put(`/modules/kams/revenue/rules/${rule.id}`, { percent: Number(raw) })
      setRuleDrafts((current) => {
        const next = { ...current }
        delete next[rule.id]
        return next
      })
      await loadKamsRevenue()
    } catch (err: unknown) {
      const message = (err as { response?: { data?: { message?: string } } }).response?.data?.message
      setKamsError(message || t('org.saveError'))
    } finally {
      setSavingRuleId(null)
    }
  }

  const saveCountry = async (id: number) => {
    const draft = drafts[id]
    if (!draft) return
    setSavingCountry(id)
    setError('')
    try {
      await api.put(`/countries/${id}`, {
        currency_code: draft.currency_code,
        tax_name: draft.tax_name,
        tax_rate: Number(draft.tax_rate),
      })
      await load()
      await refresh()
    } catch {
      setError(t('org.saveError'))
    } finally {
      setSavingCountry(null)
    }
  }

  const label = (item: { code?: string; name: string; name_fr: string }) =>
    localeFr ? item.name_fr : item.name

  if (loading) return <LoadingScreen />

  return (
    <div className="space-y-6">
      <SettingsForm group="finance" fields={settingGroups.finance} />

      <Card className="p-4 sm:p-6">
        <div className="mb-4">
          <h2 className="text-base font-bold">{t('settings.fx.title')}</h2>
          <p className="mt-1 text-sm text-muted">{t('settings.fx.help')}</p>
        </div>

        {countries.length === 0 ? (
          <p className="text-sm text-muted">{t('settings.tax.empty')}</p>
        ) : (
          <div className="space-y-3">
            {countries.map((country) => {
              const draft = drafts[country.id] ?? country
              const dirty = countryCurrencyDirty(country, draft)
              const saving = savingCountry === country.id
              return (
                <div key={country.id} className="rounded-2xl border border-line p-4">
                  <div className="font-semibold">{label(country)} <span className="text-xs font-medium text-muted">{country.iso_code}</span></div>
                  <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-end">
                    <div className="min-w-0 flex-1">
                      <Select
                        label={t('settings.fx.currency')}
                        value={draft.currency_code}
                        onChange={(e) => setDrafts((current) => ({
                          ...current,
                          [country.id]: { ...draft, currency_code: e.target.value },
                        }))}
                      >
                        {catalog.map((item) => (
                          <option key={item.code} value={item.code}>{item.code} — {label(item)}</option>
                        ))}
                      </Select>
                    </div>
                    <Button disabled={!dirty || saving} onClick={() => void saveCountry(country.id)}>
                      {saving ? t('common.loading') : dirty ? t('common.save') : t('common.saved')}
                    </Button>
                  </div>
                </div>
              )
            })}
          </div>
        )}
        {error ? <p className="mt-3 text-sm text-danger">{error}</p> : null}
      </Card>

      <Card className="p-4 sm:p-6">
        <h2 className="text-base font-bold">{t('settings.tax.title')}</h2>
        <p className="mt-1 text-sm text-muted">{t('settings.tax.help')}</p>
        {countries.length === 0 ? (
          <p className="mt-4 text-sm text-muted">{t('settings.tax.empty')}</p>
        ) : (
          <div className="mt-4 space-y-4">
            {countries.map((country) => {
              const draft = drafts[country.id] ?? country
              const dirty = countryTaxDirty(country, draft)
              const saving = savingCountry === country.id
              return (
                <div key={country.id} className="rounded-2xl border border-line p-4">
                  <div className="font-semibold">{label(country)} <span className="text-xs font-medium text-muted">{country.iso_code}</span></div>
                  <div className="mt-3 grid gap-3 sm:grid-cols-2">
                    <Field
                      label={t('settings.tax.name')}
                      value={draft.tax_name ?? ''}
                      onChange={(e) => setDrafts((current) => ({
                        ...current,
                        [country.id]: { ...draft, tax_name: e.target.value },
                      }))}
                    />
                    <Field
                      label={t('settings.tax.rate')}
                      type="number"
                      value={String(draft.tax_rate ?? '')}
                      onChange={(e) => setDrafts((current) => ({
                        ...current,
                        [country.id]: { ...draft, tax_rate: Number(e.target.value) },
                      }))}
                    />
                  </div>
                  <Button className="mt-3" disabled={!dirty || saving} onClick={() => void saveCountry(country.id)}>
                    {saving ? t('common.loading') : dirty ? t('common.save') : t('common.saved')}
                  </Button>
                </div>
              )
            })}
          </div>
        )}
      </Card>

      <Card className="p-4 sm:p-6">
        <h2 className="text-base font-bold">{t('settings.kamsRevenue.title')}</h2>
        <p className="mt-1 text-sm text-muted">{t('settings.kamsRevenue.help')}</p>
        {kamsError ? <p className="mt-3 text-sm text-red-600">{kamsError}</p> : null}
        <div className="mt-4 space-y-3">
          {rules.length === 0 && !kamsError ? <Empty>{t('settings.kamsRevenue.empty')}</Empty> : null}
          {rules.map((rule) => {
            const draftValue = ruleDrafts[rule.id] ?? String(rule.percent)
            const dirty = Number(draftValue) !== Number(rule.percent)
            const saving = savingRuleId === rule.id
            return (
              <div key={rule.id} className="flex flex-col gap-2 rounded-xl border border-line bg-surface/40 p-3 sm:flex-row sm:items-center">
                <div className="min-w-0 flex-1">
                  <div className="flex items-start gap-1.5">
                    <div className="w-fit max-w-full font-semibold">{t(`kams.rule.${rule.code}`, { defaultValue: rule.label })}</div>
                    <button
                      type="button"
                      className="mt-0.5 shrink-0 rounded-lg p-0.5 text-muted hover:bg-white hover:text-lhms"
                      aria-label={t('settings.kamsRevenue.ruleInfo')}
                      onClick={() => setInfoRule(rule)}
                    >
                      <Info size={16} />
                    </button>
                  </div>
                  <div className="text-xs text-muted">{t(`kams.ruleScope.${rule.scope}`, { defaultValue: rule.scope })} · {rule.role_slug}</div>
                </div>
                <div className="flex items-center gap-2">
                  <Field
                    type="number"
                    label={t('kams.percent')}
                    value={draftValue}
                    onChange={(e) => setRuleDrafts((current) => ({ ...current, [rule.id]: e.target.value }))}
                  />
                  {rule.divisor ? <Badge>{t('kams.divisor')}: {rule.divisor}</Badge> : null}
                  <Button disabled={!dirty || saving} onClick={() => void saveRule(rule)}>
                    {saving ? t('common.loading') : dirty ? t('common.save') : t('common.saved')}
                  </Button>
                </div>
              </div>
            )
          })}
        </div>
      </Card>

      <Modal
        open={Boolean(infoRule)}
        title={infoRule ? t(`kams.rule.${infoRule.code}`, { defaultValue: infoRule.label }) : ''}
        onClose={() => setInfoRule(null)}
      >
        {infoRule ? (
          <div className="space-y-4">
            <div>
              <div className="text-xs font-semibold uppercase tracking-wide text-muted">{t('settings.kamsRevenue.formula')}</div>
              <p className="mt-2 rounded-xl bg-surface px-4 py-3 font-mono text-sm font-semibold text-lhms">
                {t(`kams.ruleFormula.${infoRule.code}`, {
                  percent: ruleDrafts[infoRule.id] ?? String(infoRule.percent),
                  divisor: infoRule.divisor ?? 4,
                  defaultValue: t('settings.kamsRevenue.formulaFallback', {
                    percent: ruleDrafts[infoRule.id] ?? String(infoRule.percent),
                  }),
                })}
              </p>
            </div>
            <div>
              <div className="text-xs font-semibold uppercase tracking-wide text-muted">{t('settings.kamsRevenue.description')}</div>
              <p className="mt-2 text-sm text-muted">
                {t(`kams.ruleHelp.${infoRule.code}`, { defaultValue: t('settings.kamsRevenue.helpFallback') })}
              </p>
            </div>
            <Button className="w-full" variant="secondary" onClick={() => setInfoRule(null)}>
              {t('common.close')}
            </Button>
          </div>
        ) : null}
      </Modal>
    </div>
  )
}
