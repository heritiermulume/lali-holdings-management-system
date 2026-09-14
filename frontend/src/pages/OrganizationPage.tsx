import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal } from '../components/ui'
import api from '../lib/api'

type Row = Record<string, unknown> & { id: number; name: string; is_active?: boolean }

const tabs = ['countries', 'provinces', 'cities', 'offices', 'departments'] as const

export function OrganizationPage() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<(typeof tabs)[number]>('countries')
  const [rows, setRows] = useState<Row[]>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState<Record<string, string>>({})
  const [countries, setCountries] = useState<Row[]>([])
  const [companies, setCompanies] = useState<Row[]>([])

  const load = () => {
    api.get(`/${tab}`).then((res) => setRows(res.data.data))
    api.get('/countries').then((res) => setCountries(res.data.data))
    api.get('/companies').then((res) => setCompanies(res.data.data))
  }

  useEffect(() => {
    load()
  }, [tab])

  const submit = async () => {
    const payload: Record<string, unknown> = { ...form, is_active: true }
    if (form.country_id) payload.country_id = Number(form.country_id)
    if (form.company_id) payload.company_id = Number(form.company_id)
    await api.post(`/${tab}`, payload)
    setOpen(false)
    setForm({})
    load()
  }

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button onClick={() => setOpen(true)}>{t('org.add')}</Button>
      </div>
      <div className="mb-4 flex gap-2 overflow-x-auto">
        {tabs.map((item) => (
          <button
            key={item}
            onClick={() => setTab(item)}
            className={`min-h-10 rounded-full px-4 text-sm font-semibold ${tab === item ? 'bg-lhms text-white' : 'bg-white text-muted'}`}
          >
            {t(`org.${item}`)}
          </button>
        ))}
      </div>
      <Card className="overflow-hidden">
        <div className="hidden md:block">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-muted">
              <tr>
                <th className="px-5 py-3">{t('org.name')}</th>
                <th className="px-5 py-3">{t('org.code')}</th>
                <th className="px-5 py-3">{t('org.status')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id} className="border-t border-line">
                  <td className="px-5 py-3 font-semibold">{row.name}</td>
                  <td className="px-5 py-3">{String(row.code || row.iso_code || '—')}</td>
                  <td className="px-5 py-3"><Badge>{row.is_active === false ? '—' : t('org.active')}</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="space-y-3 p-4 md:hidden">
          {rows.map((row) => (
            <div key={row.id} className="rounded-xl border border-line p-4">
              <div className="font-semibold">{row.name}</div>
              <div className="mt-1 text-sm text-muted">{String(row.code || row.iso_code || '')}</div>
            </div>
          ))}
        </div>
      </Card>

      <Modal open={open} title={`${t('org.add')} ${t(`org.${tab}`)}`} onClose={() => setOpen(false)}>
        <div className="space-y-3">
          <Field label={t('org.name')} value={form.name || ''} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          {tab === 'countries' || tab === 'provinces' || tab === 'cities' ? (
            <Field label={t('org.nameFr')} value={form.name_fr || ''} onChange={(e) => setForm({ ...form, name_fr: e.target.value })} />
          ) : null}
          {tab === 'countries' ? (
            <Field label="ISO" value={form.iso_code || ''} onChange={(e) => setForm({ ...form, iso_code: e.target.value })} />
          ) : (
            <Field label={t('org.code')} value={form.code || ''} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          )}
          {tab !== 'countries' && tab !== 'offices' && tab !== 'departments' ? (
            <label className="block text-sm font-medium">
              {t('org.countries')}
              <select className="mt-1 min-h-11 w-full rounded-xl border border-line px-3" value={form.country_id || ''} onChange={(e) => setForm({ ...form, country_id: e.target.value })}>
                <option value="">—</option>
                {countries.map((country) => <option key={country.id} value={country.id}>{country.name}</option>)}
              </select>
            </label>
          ) : null}
          {tab === 'offices' || tab === 'departments' ? (
            <label className="block text-sm font-medium">
              {t('org.companies')}
              <select className="mt-1 min-h-11 w-full rounded-xl border border-line px-3" value={form.company_id || ''} onChange={(e) => setForm({ ...form, company_id: e.target.value })}>
                <option value="">—</option>
                {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
              </select>
            </label>
          ) : null}
          {tab === 'offices' ? (
            <label className="block text-sm font-medium">
              Type
              <select className="mt-1 min-h-11 w-full rounded-xl border border-line px-3" value={form.type || 'office'} onChange={(e) => setForm({ ...form, type: e.target.value })}>
                <option value="headquarters">HQ</option>
                <option value="office">Office</option>
                <option value="agency">Agency</option>
                <option value="branch">Branch</option>
              </select>
            </label>
          ) : null}
          <Button className="w-full" onClick={() => void submit()}>{t('common.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
