import axios, { type InternalAxiosRequestConfig } from 'axios'
import { notifyPermissionsPossiblyStale } from './authSync'
import { setActiveCurrency } from './format'
import { beginBusy, endBusy, type BusyKind } from './busy'
import { isSoftRefresh, notifyDataChanged } from './dataSync'

declare module 'axios' {
  export interface AxiosRequestConfig {
    skipBusy?: boolean
    busyKind?: BusyKind
    skipDataSync?: boolean
  }
}

type BusyConfig = InternalAxiosRequestConfig & {
  __busy?: boolean
}

const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

function busyKindFor(config: BusyConfig): BusyKind | null {
  if (config.skipBusy || isSoftRefresh()) return null
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

function shouldNotifyDataChanged(config: BusyConfig | undefined, status: number | undefined) {
  if (!config || config.skipDataSync) return false
  if (isSoftRefresh()) return false
  if (!status || status < 200 || status >= 300) return false

  const method = String(config.method || 'get').toLowerCase()
  if (method === 'get' || method === 'head' || method === 'options') return false

  const url = String(config.url || '')
  if (/\/auth\/(?:login|logout|register|forgot-password|reset-password|change-password)/i.test(url)) return false
  if (/\/public\//i.test(url)) return false

  return true
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
    const config = response.config as BusyConfig
    if (config.__busy) {
      endBusy()
    }
    if (shouldNotifyDataChanged(config, response.status)) {
      notifyDataChanged('mutation')
    }
    const url = String(config.url || '')
    const payload = response.data?.data
    if (/\/modules\/kams\//.test(url) && payload && typeof payload === 'object' && payload.currency && payload.money_rate != null) {
      setActiveCurrency(String(payload.currency), Number(payload.money_rate))
    }
    return response
  },
  (error) => {
    if ((error.config as BusyConfig | undefined)?.__busy) {
      endBusy()
    }
    const path = window.location.pathname
    const status = error.response?.status
    if (status === 401 && !path.startsWith('/login') && !path.startsWith('/reset-password')) {
      localStorage.removeItem('lhms_token')
      window.location.href = '/login'
    } else if (status === 403 && localStorage.getItem('lhms_token')) {
      // Permissions may have been revoked — refresh the session user so the UI hides forbidden actions.
      notifyPermissionsPossiblyStale()
    }
    return Promise.reject(error)
  },
)

export default api
