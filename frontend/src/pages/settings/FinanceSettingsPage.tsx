import { Ban, Info, Star, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Select } from '../../components/ui'
import { LoadingScreen } from '../../components/LoadingScreen'
import { money } from '../../lib/format'
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

type FxCurrency = {
  id: number
  code: string
  name: string
  name_fr: string
  symbol: string
  is_default: boolean
  is_active: boolean
  mid: number
  buy: number
  sell: number
  fetched_at?: string | null
  countries?: { id: number; name: string; name_fr: string; iso_code: string }[]
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

function countryDraftDirty(saved: CountryTax, draft: CountryTax) {
  return saved.currency_code !== draft.currency_code
    || (saved.tax_name ?? '') !== (draft.tax_name ?? '')
    || Number(saved.tax_rate) !== Number(draft.tax_rate)
}

function formatRate(value: number) {
  if (!Number.isFinite(value)) return '—'
  if (value >= 100) return value.toLocaleString(undefined, { maximumFractionDigits: 2 })
  if (value >= 1) return value.toLocaleString(undefined, { maximumFractionDigits: 4 })
  return value.toLocaleString(undefined, { maximumFractionDigits: 6 })
}

export function FinanceSettingsPage() {
  const { t, i18n } = useTranslation()
  const localeFr = i18n.language.startsWith('fr')
  const [countries, setCountries] = useState<CountryTax[]>([])
  const [drafts, setDrafts] = useState<Record<number, CountryTax>>({})
  const [currencies, setCurrencies] = useState<FxCurrency[]>([])
  const [catalog, setCatalog] = useState<CatalogItem[]>([])
  const [defaultCode, setDefaultCode] = useState('USD')
  const [amount, setAmount] = useState('100')
  const [from, setFrom] = useState('USD')
  const [to, setTo] = useState('CDF')
  const [converted, setConverted] = useState<{ mid: number; buy: number; sell: number; to: string } | null>(null)
  const [busy, setBusy] = useState(false)
  const [savingCountry, setSavingCountry] = useState<number | null>(null)
  const [synced, setSynced] = useState('')
  const [error, setError] = useState('')
  const [toggling, setToggling] = useState<FxCurrency | null>(null)
  const [deleting, setDeleting] = useState<FxCurrency | null>(null)
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
    setCurrencies(data.currencies ?? [])
    setDefaultCode(data.default ?? 'USD')
    setCatalog(list.data.data ?? [])
    const stamp = (data.currencies ?? []).find((item: FxCurrency) => item.fetched_at)?.fetched_at
    if (stamp) setSynced(stamp)
    const active = (data.currencies ?? []).filter((item: FxCurrency) => item.is_active)
    if (active.length) {
      setFrom((current) => active.some((item: FxCurrency) => item.code === current) ? current : data.default)
      setTo((current) => active.some((item: FxCurrency) => item.code === current) ? current : (active.find((item: FxCurrency) => item.code !== data.default)?.code ?? data.default))
    }
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

  const run = async (action: () => Promise<void>) => {
    setBusy(true)
    setError('')
    try {
      await action()
      await load()
    } catch (err: unknown) {
      const status = (err as { response?: { status?: number } }).response?.status
      const message = (err as { response?: { data?: { message?: string } } }).response?.data?.message
      setError(status === 409 ? t('settings.fx.currencyInUse') : (message || t('org.actionError')))
    } finally {
      setBusy(false)
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
    } catch {
      setError(t('org.saveError'))
    } finally {
      setSavingCountry(null)
    }
  }

  const convert = async () => {
    setBusy(true)
    setError('')
    try {
      const res = await api.get('/finance/convert', { params: { amount: Number(amount) || 0, from, to } })
      setConverted({
        mid: res.data.data.mid,
        buy: res.data.data.buy,
        sell: res.data.data.sell,
        to,
      })
    } catch {
      setError(t('org.actionError'))
    } finally {
      setBusy(false)
    }
  }

  const label = (item: { code?: string; name: string; name_fr: string }) =>
    localeFr ? item.name_fr : item.name

  const linkedCurrencies = currencies.filter((row) => (row.countries?.length ?? 0) > 0 || row.is_default)

  if (loading) return <LoadingScreen />

  return (
    <div className="space-y-6">
      <SettingsForm group="finance" fields={settingGroups.finance} />

      <Card className="p-4 sm:p-6">
        <h2 className="text-base font-bold">{t('settings.tax.title')}</h2>
        <p className="mt-1 text-sm text-muted">{t('settings.tax.help')}</p>
        {countries.length === 0 ? (
          <p className="mt-4 text-sm text-muted">{t('settings.tax.empty')}</p>
        ) : (
          <div className="mt-4 space-y-4">
            {countries.map((country) => {
              const draft = drafts[country.id] ?? country
              const dirty = countryDraftDirty(country, draft)
              const saving = savingCountry === country.id
              return (
                <div key={country.id} className="rounded-2xl border border-line p-4">
                  <div className="font-semibold">{label(country)} <span className="text-xs font-medium text-muted">{country.iso_code}</span></div>
                  <div className="mt-3 grid gap-3 sm:grid-cols-3">
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
        <div className="mb-4">
          <h2 className="text-base font-bold">{t('settings.fx.title')}</h2>
          <p className="mt-1 text-sm text-muted">{t('settings.fx.help')}</p>
          {synced ? (
            <p className="mt-1 text-xs text-muted">
              {t('settings.fx.updated')}: {new Date(synced).toLocaleString()}
            </p>
          ) : null}
        </div>

        <div className="hidden overflow-x-auto md:block">
          <table className="w-full text-sm">
            <thead className="text-left text-muted">
              <tr>
                <th className="px-2 py-2">{t('settings.fx.currency')}</th>
                <th className="px-2 py-2">{t('org.countries')}</th>
                <th className="px-2 py-2">{t('settings.fx.mid', { code: defaultCode })}</th>
                <th className="px-2 py-2">{t('settings.fx.buy')}</th>
                <th className="px-2 py-2">{t('settings.fx.sell')}</th>
                <th className="px-2 py-2">{t('common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {linkedCurrencies.map((row) => (
                <tr key={row.id} className={`border-t border-line ${row.is_active ? '' : 'opacity-60'}`}>
                  <td className="px-2 py-3">
                    <div className="font-semibold">
                      {row.code} {row.is_default ? <Badge>{t('settings.fx.default')}</Badge> : null}
                    </div>
                    <div className="text-xs text-muted">{label(row)} · {row.symbol}</div>
                  </td>
                  <td className="px-2 py-3 text-muted">
                    {(row.countries ?? []).map((item) => label(item)).join(', ') || '—'}
                  </td>
                  <td className="px-2 py-3">{row.is_default ? '1' : formatRate(row.mid)}</td>
                  <td className="px-2 py-3">{row.is_default ? '—' : formatRate(row.buy)}</td>
                  <td className="px-2 py-3">{row.is_default ? '—' : formatRate(row.sell)}</td>
                  <td className="px-2 py-3">
                    <div className="flex flex-wrap gap-1">
                      {!row.is_default ? (
                        <button
                          type="button"
                          className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold hover:bg-surface"
                          onClick={() => void run(async () => { await api.put(`/finance/currencies/${row.id}`, { is_default: true }) })}
                        >
                          <Star size={14} /> {t('settings.fx.setDefault')}
                        </button>
                      ) : null}
                      <button
                        type="button"
                        className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold hover:bg-surface"
                        onClick={() => setToggling(row)}
                      >
                        <Ban size={14} /> {row.is_active ? t('org.deactivateOffice') : t('org.activateOffice')}
                      </button>
                      {!row.is_default && !(row.countries?.length) ? (
                        <button
                          type="button"
                          className="inline-flex min-h-10 items-center gap-1 rounded-xl px-2 text-sm font-semibold text-danger hover:bg-surface"
                          onClick={() => setDeleting(row)}
                        >
                          <Trash2 size={14} /> {t('common.delete')}
                        </button>
                      ) : null}
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div className="space-y-3 md:hidden">
          {linkedCurrencies.map((row) => (
            <div key={row.id} className={`rounded-2xl border border-line p-4 ${row.is_active ? '' : 'opacity-60'}`}>
              <div className="font-semibold">{row.code} {row.is_default ? <Badge>{t('settings.fx.default')}</Badge> : null}</div>
              <div className="text-sm text-muted">{label(row)}</div>
              <div className="mt-1 text-sm text-muted">{(row.countries ?? []).map((item) => label(item)).join(', ')}</div>
            </div>
          ))}
        </div>
        {error ? <p className="mt-3 text-sm text-danger">{error}</p> : null}
      </Card>

      <Card className="p-4 sm:p-6">
        <h2 className="text-base font-bold">{t('settings.fx.convert')}</h2>
        <p className="mt-1 text-sm text-muted">{t('settings.fx.convertHelp')}</p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          <Field label={t('settings.fx.amount')} type="number" value={amount} onChange={(e) => setAmount(e.target.value)} />
          <Select label={t('settings.fx.from')} value={from} onChange={(e) => setFrom(e.target.value)}>
            {currencies.filter((item) => item.is_active).map((item) => (
              <option key={item.code} value={item.code}>{item.code} — {label(item)}</option>
            ))}
          </Select>
          <Select label={t('settings.fx.to')} value={to} onChange={(e) => setTo(e.target.value)}>
            {currencies.filter((item) => item.is_active).map((item) => (
              <option key={item.code} value={item.code}>{item.code} — {label(item)}</option>
            ))}
          </Select>
        </div>
        <Button className="mt-4" disabled={busy} onClick={() => void convert()}>
          {busy ? t('common.loading') : t('settings.fx.convert')}
        </Button>
        {converted ? (
          <div className="mt-4 grid gap-3 sm:grid-cols-3">
            <div className="rounded-2xl bg-surface p-4">
              <div className="text-xs text-muted">{t('settings.fx.midResult')}</div>
              <div className="mt-1 text-lg font-bold">{money(converted.mid, converted.to)}</div>
            </div>
            <div className="rounded-2xl bg-surface p-4">
              <div className="text-xs text-muted">{t('settings.fx.buy')}</div>
              <div className="mt-1 text-lg font-bold">{money(converted.buy, converted.to)}</div>
            </div>
            <div className="rounded-2xl bg-surface p-4">
              <div className="text-xs text-muted">{t('settings.fx.sell')}</div>
              <div className="mt-1 text-lg font-bold">{money(converted.sell, converted.to)}</div>
            </div>
          </div>
        ) : null}
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

      <Modal open={Boolean(toggling)} title={toggling?.is_active ? t('org.deactivateOffice') : t('org.activateOffice')} onClose={() => setToggling(null)}>
        <p className="text-sm text-muted">
          {t(toggling?.is_active ? 'org.deactivateConfirm' : 'org.activateConfirm', { name: toggling?.code ?? '' })}
        </p>
        <div className="mt-5 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setToggling(null)}>{t('common.cancel')}</Button>
          <Button className="flex-1" disabled={busy} onClick={() => {
            if (!toggling) return
            void run(async () => {
              await api.put(`/finance/currencies/${toggling.id}`, { is_active: !toggling.is_active })
              setToggling(null)
            })
          }}>
            {toggling?.is_active ? t('org.deactivateOffice') : t('org.activateOffice')}
          </Button>
        </div>
      </Modal>

      <Modal open={Boolean(deleting)} title={t('common.delete')} onClose={() => setDeleting(null)}>
        <p className="text-sm text-muted">{t('org.deleteConfirm', { name: deleting?.code ?? '' })}</p>
        <div className="mt-5 flex gap-2">
          <Button variant="secondary" className="flex-1" onClick={() => setDeleting(null)}>{t('common.cancel')}</Button>
          <Button variant="danger" className="flex-1" disabled={busy} onClick={() => {
            if (!deleting) return
            void run(async () => {
              await api.delete(`/finance/currencies/${deleting.id}`)
              setDeleting(null)
            })
          }}>{t('common.delete')}</Button>
        </div>
      </Modal>
    </div>
  )
}
