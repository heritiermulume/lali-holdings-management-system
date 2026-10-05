import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'

export type PresenceCountry = {
  id: number
  name: string
  name_fr: string
  iso_code: string
  latitude: number
  longitude: number
  companies_count: number
  offices_count: number
}

export function PresenceMap({ countries }: { countries: PresenceCountry[] }) {
  const { t, i18n } = useTranslation()
  const container = useRef<HTMLDivElement>(null)
  const mapRef = useRef<L.Map | null>(null)

  useEffect(() => {
    if (!container.current || mapRef.current) return

    const map = L.map(container.current, {
      scrollWheelZoom: false,
      worldCopyJump: true,
    }).setView([-2.5, 23], 3)

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap',
      maxZoom: 12,
    }).addTo(map)

    mapRef.current = map
    requestAnimationFrame(() => map.invalidateSize())

    return () => {
      map.remove()
      mapRef.current = null
    }
  }, [])

  useEffect(() => {
    const map = mapRef.current
    if (!map) return

    const layer = L.layerGroup().addTo(map)
    const bounds: L.LatLngExpression[] = []
    const localeFr = i18n.language.startsWith('fr')

    for (const country of countries) {
      if (country.latitude == null || country.longitude == null) continue
      const point: L.LatLngExpression = [country.latitude, country.longitude]
      bounds.push(point)
      const name = localeFr ? country.name_fr : country.name
      L.circleMarker(point, {
        radius: 9,
        color: '#043711',
        weight: 2,
        fillColor: '#16a34a',
        fillOpacity: 0.85,
      })
        .bindPopup(
          `<strong>${name}</strong><br/>${country.iso_code}<br/>${country.companies_count} ${t('org.companies')} · ${country.offices_count} ${t('org.offices')}`,
        )
        .addTo(layer)
    }

    if (bounds.length === 1) {
      map.setView(bounds[0], 5)
    } else if (bounds.length > 1) {
      map.fitBounds(L.latLngBounds(bounds), { padding: [32, 32], maxZoom: 5 })
    }

    return () => {
      layer.remove()
    }
  }, [countries, i18n.language, t])

  if (countries.length === 0) {
    return <p className="text-sm text-muted">{t('dashboard.mapEmpty')}</p>
  }

  return <div ref={container} className="relative z-0 isolate h-56 w-full overflow-hidden rounded-2xl sm:h-72 xl:h-80" />
}
