import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/layout/AppShell'
import { ModuleWorkspaceLayout } from './components/layout/ModuleWorkspaceLayout'
import { useAuth } from './context/AuthContext'
import { AuditPage } from './pages/AuditPage'
import { CompaniesPage } from './pages/CompaniesPage'
import { DashboardPage } from './pages/DashboardPage'
import { GroupActionsPage, GroupModuleDashboardPage, GroupOverviewPage } from './pages/group/GroupDashboardPages'
import { LoginPage } from './pages/LoginPage'
import { ModulesPage } from './pages/ModulesPage'
import { NotificationsPage } from './pages/NotificationsPage'
import { OrganizationPage } from './pages/OrganizationPage'
import { UsersPage } from './pages/UsersPage'
import { KamsCampaigns, KamsClients, KamsDashboard, KamsOrders, KamsProducts } from './pages/kams/KamsPages'

function Guard({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <div className="grid min-h-dvh place-items-center text-sm text-muted">LHMS</div>
  if (!user) return <Navigate to="/login" replace />
  return children
}

function Guest({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <div className="grid min-h-dvh place-items-center text-sm text-muted">LHMS</div>
  if (user) return <Navigate to="/" replace />
  return children
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Guest><LoginPage /></Guest>} />
      <Route element={<Guard><AppShell /></Guard>}>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/settings" element={<Navigate to="/organization" replace />} />
        <Route path="/modules" element={<ModulesPage />} />
        <Route path="/modules/dashboard" element={<ModuleWorkspaceLayout />}>
          <Route index element={<GroupOverviewPage />} />
          <Route path="actions" element={<GroupActionsPage />} />
          <Route path=":slug" element={<GroupModuleDashboardPage />} />
        </Route>
        <Route path="/modules/kams" element={<ModuleWorkspaceLayout />}>
          <Route index element={<KamsDashboard />} />
          <Route path="clients" element={<KamsClients />} />
          <Route path="products" element={<KamsProducts />} />
          <Route path="orders" element={<KamsOrders />} />
          <Route path="campaigns" element={<KamsCampaigns />} />
        </Route>
        <Route element={<ModuleWorkspaceLayout />}>
          <Route path="/organization" element={<OrganizationPage />} />
          <Route path="/companies" element={<CompaniesPage />} />
          <Route path="/users" element={<UsersPage />} />
          <Route path="/notifications" element={<NotificationsPage />} />
          <Route path="/audit" element={<AuditPage />} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
