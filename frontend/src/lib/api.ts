import axios, { type InternalAxiosRequestConfig } from 'axios'
import { beginBusy, endBusy, type BusyKind } from './busy'

type BusyConfig = InternalAxiosRequestConfig & {
  skipBusy?: boolean
  busyKind?: BusyKind
  __busy?: boolean
}

const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

function busyKindFor(config: BusyConfig): BusyKind | null {
  if (config.skipBusy) return null
  if (config.busyKind) return config.busyKind

  const url = String(config.url || '')
  const method = String(config.method || 'get').toLowerCase()
  if (/\/auth\/me(?:\?|$)|\/notifications|\/public\/bootstrap|\/lookups/.test(url)) return null
  if (/import/i.test(url)) return 'import'

  if (method !== 'get' && method !== 'head' && method !== 'options') return 'data'

  const perPage = Number(config.params?.per_page)
  if (Number.isFinite(perPage) && perPage >= 200) return 'data'

  if (/(?:^|\/)dashboard(?:\/|$)|\/stock\/hierarchy|\/stock\/journal|\/module-performance|\/finance\/account|\/revenue\/|\/modules\/kams\/finance|\/finance(?:\?|$|\/)/.test(url)) {
    return 'data'
  }

  return null
}

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('lhms_token')
  const locale = localStorage.getItem('lhms_locale') || 'fr'
  const busyConfig = config as BusyConfig

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  config.headers['Accept-Language'] = locale

  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    if (typeof config.headers.delete === 'function') {
      config.headers.delete('Content-Type')
    } else {
      delete config.headers['Content-Type']
      delete config.headers['content-type']
    }
  }

  const kind = busyKindFor(busyConfig)
  if (kind) {
    beginBusy(kind)
    busyConfig.__busy = true
  }

  return config
})

api.interceptors.response.use(
  (response) => {
    if ((response.config as BusyConfig).__busy) {
      endBusy()
    }
    return response
  },
  (error) => {
    if ((error.config as BusyConfig | undefined)?.__busy) {
      endBusy()
    }
    const path = window.location.pathname
    if (error.response?.status === 401 && !path.startsWith('/login') && !path.startsWith('/reset-password')) {
      localStorage.removeItem('lhms_token')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  },
)

export default api
