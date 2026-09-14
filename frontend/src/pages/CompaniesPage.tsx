import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal } from '../components/ui'
import api from '../lib/api'

type Company = {
  id: number
  name: string
  code: string
  email?: string
  country?: { name: string }
  city?: { name: string }
  offices_count?: number
  is_active: boolean
}

export function CompaniesPage() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<Company[]>([])
  const [countries, setCountries] = useState<Array<{ id: number; name: string }>>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ name: '', code: '', country_id: '', email: '' })

  const load = () => {
    api.get('/companies').then((res) => setRows(res.data.data))
    api.get('/countries').then((res) => setCountries(res.data.data))
  }

  useEffect(() => { load() }, [])

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button onClick={() => setOpen(true)}>{t('org.add')}</Button>
      </div>
      <div className="grid gap-4 md:grid-cols-2">
        {rows.map((company) => (
          <Card key={company.id} className="p-5">
            <div className="flex items-start justify-between">
              <div>
                <div className="text-xs font-semibold tracking-widest text-muted">{company.code}</div>
                <h2 className="mt-1 text-xl font-bold">{company.name}</h2>
                <p className="mt-1 text-sm text-muted">{company.city?.name || company.country?.name}</p>
              </div>
              <Badge>{company.is_active ? t('org.active') : '—'}</Badge>
            </div>
            <div className="mt-4 text-sm text-muted">{company.offices_count || 0} {t('org.offices')}</div>
          </Card>
        ))}
      </div>
      <Modal open={open} title={t('org.add')} onClose={() => setOpen(false)}>
        <div className="space-y-3">
          <Field label={t('org.name')} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          <Field label={t('org.code')} value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} />
          <Field label="Email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <label className="block text-sm font-medium">
            {t('org.countries')}
            <select className="mt-1 min-h-11 w-full rounded-xl border border-line px-3" value={form.country_id} onChange={(e) => setForm({ ...form, country_id: e.target.value })}>
              <option value="">—</option>
              {countries.map((country) => <option key={country.id} value={country.id}>{country.name}</option>)}
            </select>
          </label>
          <Button className="w-full" onClick={async () => {
            await api.post('/companies', { ...form, country_id: Number(form.country_id) })
            setOpen(false)
            load()
          }}>{t('common.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
