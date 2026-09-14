import { createContext, useContext, useEffect, useState, type ReactNode } from 'react'
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

  useEffect(() => {
    if (!user) {
      setModules([])
      return
    }

    api.get('/modules').then((res) => setModules(res.data.data)).catch(() => {
      api.get('/dashboard').then((res) => setModules(res.data.data.modules ?? [])).catch(() => undefined)
    })
  }, [user])

  return <ModulesContext.Provider value={{ modules }}>{children}</ModulesContext.Provider>
}

export function useSystemModules() {
  return useContext(ModulesContext)
}
