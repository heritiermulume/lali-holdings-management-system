import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Select } from '../components/ui'
import api from '../lib/api'

type UserRow = {
  id: number
  name: string
  email: string
  is_active: boolean
  roles: Array<{ name: string }>
  primary_company?: { name: string } | null
}

export function UsersPage() {
  const { t } = useTranslation()
  const [rows, setRows] = useState<UserRow[]>([])
  const [roles, setRoles] = useState<Array<{ id: number; name: string }>>([])
  const [companies, setCompanies] = useState<Array<{ id: number; name: string }>>([])
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ first_name: '', last_name: '', email: '', role_id: '', primary_company_id: '' })

  const load = () => {
    api.get('/users').then((res) => setRows(res.data.data))
    api.get('/roles').then((res) => setRoles(res.data.data))
    api.get('/companies').then((res) => setCompanies(res.data.data))
  }

  useEffect(() => { load() }, [])

  return (
    <div>
      <div className="mb-4 flex justify-end">
        <Button onClick={() => setOpen(true)}>{t('users.create')}</Button>
      </div>
      <Card className="overflow-hidden">
        <div className="hidden md:block">
          <table className="w-full text-sm">
            <thead className="bg-surface text-left text-muted">
              <tr>
                <th className="px-5 py-3">{t('users.firstName')}</th>
                <th className="px-5 py-3">Email</th>
                <th className="px-5 py-3">{t('users.role')}</th>
                <th className="px-5 py-3">{t('users.company')}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((row) => (
                <tr key={row.id} className="border-t border-line">
                  <td className="px-5 py-3 font-semibold">{row.name}</td>
                  <td className="px-5 py-3">{row.email}</td>
                  <td className="px-5 py-3"><Badge>{row.roles?.[0]?.name || '—'}</Badge></td>
                  <td className="px-5 py-3">{row.primary_company?.name || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="space-y-3 p-4 md:hidden">
          {rows.map((row) => (
            <div key={row.id} className="rounded-xl border border-line p-4">
              <div className="font-semibold">{row.name}</div>
              <div className="text-sm text-muted">{row.email}</div>
              <div className="mt-2"><Badge>{row.roles?.[0]?.name || '—'}</Badge></div>
            </div>
          ))}
        </div>
      </Card>
      <Modal open={open} title={t('users.create')} onClose={() => setOpen(false)}>
        <div className="space-y-3">
          <Field label={t('users.firstName')} value={form.first_name} onChange={(e) => setForm({ ...form, first_name: e.target.value })} />
          <Field label={t('users.lastName')} value={form.last_name} onChange={(e) => setForm({ ...form, last_name: e.target.value })} />
          <Field label="Email" type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          <Select label={t('users.role')} value={form.role_id} onChange={(e) => setForm({ ...form, role_id: e.target.value })}>
            <option value="">—</option>
            {roles.map((role) => <option key={role.id} value={role.id}>{role.name}</option>)}
          </Select>
          <Select label={t('users.company')} value={form.primary_company_id} onChange={(e) => setForm({ ...form, primary_company_id: e.target.value })}>
            <option value="">—</option>
            {companies.map((company) => <option key={company.id} value={company.id}>{company.name}</option>)}
          </Select>
          <Button className="w-full" onClick={async () => {
            await api.post('/users', {
              ...form,
              role_ids: form.role_id ? [Number(form.role_id)] : [],
              primary_company_id: form.primary_company_id ? Number(form.primary_company_id) : null,
              scopes: form.primary_company_id ? [{ scope_type: 'company', scope_id: Number(form.primary_company_id) }] : [],
            })
            setOpen(false)
            load()
          }}>{t('common.save')}</Button>
        </div>
      </Modal>
    </div>
  )
}
