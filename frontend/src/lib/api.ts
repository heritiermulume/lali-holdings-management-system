import axios from 'axios'

const api = axios.create({
  baseURL: '/api/v1',
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('lhms_token')
  const locale = localStorage.getItem('lhms_locale') || 'fr'

  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }

  config.headers['Accept-Language'] = locale
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401 && !window.location.pathname.startsWith('/login')) {
      localStorage.removeItem('lhms_token')
      window.location.href = '/login'
    }
    return Promise.reject(error)
  },
)

export default api
