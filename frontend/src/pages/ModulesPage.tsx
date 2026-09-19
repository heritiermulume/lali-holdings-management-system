import { ArrowUpRight, Building2, Crown, Truck, Tv, Wheat } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Card, PageHeader } from '../components/ui'
import { LoadingScreen } from '../components/LoadingScreen'
import api from '../lib/api'
import type { SystemModule } from '../types'

const icons = {
  kams: Crown,
  'heavy-equipment': Truck,
  'real-estate': Building2,
  'rice-mills': Wheat,
  'lali-tv': Tv,
}

export function ModulesPage() {
  const { t, i18n } = useTranslation()
  const [modules, setModules] = useState<SystemModule[]>([])
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    api.get('/modules').then((res) => setModules(res.data.data ?? [])).finally(() => setLoading(false))
  }, [])

  return (
    <div>
      <PageHeader title={t('modules.title')} subtitle={t('modules.subtitle')} />
      {loading ? <LoadingScreen /> : (
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {modules.map((module) => {
          const Icon = icons[module.slug as keyof typeof icons] || Crown
          const title = i18n.language.startsWith('fr') ? module.name_fr : module.name
          const ready = module.slug === 'kams' && module.status === 'active'
          return (
            <Card key={module.id} className="p-6">
              <div className="flex items-start justify-between">
                <span className="grid h-12 w-12 place-items-center rounded-2xl bg-lhms text-white"><Icon size={22} /></span>
                <Badge tone={module.status === 'active' ? 'green' : module.status === 'inactive' ? 'red' : 'amber'}>
                  {t(`modules.${module.status}`)}
                </Badge>
              </div>
              <h2 className="mt-5 text-xl font-bold">{title}</h2>
              <p className="mt-2 text-sm text-muted">{i18n.language.startsWith('fr') ? module.description_fr : module.description}</p>
              <div className="mt-6">
                {ready ? (
                  <Link to="/modules/kams" className="inline-flex min-h-11 items-center gap-2 rounded-xl bg-lhms px-4 text-sm font-semibold text-white">
                    {t('modules.open')} <ArrowUpRight size={16} />
                  </Link>
                ) : (
                  <div className="text-sm font-medium text-muted">{t(`modules.${module.status}`)}</div>
                )}
              </div>
            </Card>
          )
        })}
      </div>
      )}
    </div>
  )
}
