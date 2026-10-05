import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import App from './App.tsx'
import { AlertProvider } from './components/AlertDialog'
import { BusyOverlay } from './components/LoadingScreen'
import { AuthProvider } from './context/AuthContext.tsx'
import { SystemModulesProvider } from './context/ModulesContext.tsx'
import './i18n/index.ts'
import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <AuthProvider>
        <SystemModulesProvider>
          <AlertProvider>
            <App />
            <BusyOverlay />
          </AlertProvider>
        </SystemModulesProvider>
      </AuthProvider>
    </BrowserRouter>
  </StrictMode>,
)
