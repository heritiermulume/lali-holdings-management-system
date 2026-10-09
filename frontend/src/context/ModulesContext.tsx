import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
import { useLiveData } from '../hooks/useLiveData'
import api from '../lib/api'
import type { SystemModule } from '../types'
import { useAuth } from './AuthContext'

type ModulesContextValue = {
  modules: SystemModule[]
}

const ModulesContext = createContext<ModulesContextValue>({ modules: [] })

export function SystemModulesProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth()
  const [modules, setModules] = useState<SystemModule[]>([])

  const loadModules = () => {
    if (!user?.id) {
      setModules([])
      return
    }
    return api.get('/modules').then((res) => setModules(res.data.data)).catch(() => {
      api.get('/dashboard').then((res) => setModules(res.data.data.modules ?? [])).catch(() => undefined)
    })
  }

  useEffect(() => {
    void loadModules()
  }, [user?.id])

  useLiveData(loadModules, { enabled: Boolean(user?.id) })

  return <ModulesContext.Provider value={{ modules }}>{children}</ModulesContext.Provider>
}

export function useSystemModules() {
  return useContext(ModulesContext)
}
