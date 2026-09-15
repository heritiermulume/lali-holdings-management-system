import type { ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { AppShell } from './components/layout/AppShell'
import { ModuleWorkspaceLayout } from './components/layout/ModuleWorkspaceLayout'
import { RequireModule, RequirePermission } from './components/RequirePermission'
import { useAuth } from './context/AuthContext'
import { DASHBOARD_PERMS, SETTINGS_PERMS } from './lib/access'
import { AuditPage } from './pages/AuditPage'
import { CompaniesPage } from './pages/CompaniesPage'
import { DashboardPage } from './pages/DashboardPage'
import { GroupActionsPage, GroupModuleDashboardPage, GroupOverviewPage } from './pages/group/GroupDashboardPages'
import { LoginPage } from './pages/LoginPage'
import { ModulesPage } from './pages/ModulesPage'
import { NotificationsPage } from './pages/NotificationsPage'
import { OrganizationPage } from './pages/OrganizationPage'
import { UsersPage } from './pages/UsersPage'
import { GroupSettingsPage, ModuleSettingsPage, SettingsHome } from './pages/settings/SettingsPages'
import { FinanceSettingsPage } from './pages/settings/FinanceSettingsPage'
import { RolesSettingsPage } from './pages/settings/RolesSettingsPage'
import { KamsCampaigns, KamsClients, KamsEntry, KamsOrders, KamsProducts } from './pages/kams/KamsPages'
import { KamsAgents, KamsCandidates, KamsMembersWorkspace, KamsRecruitmentDashboard } from './pages/kams/KamsRecruitmentPages'

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
        <Route path="/organization" element={<Navigate to="/settings/organization" replace />} />
        <Route path="/companies" element={<Navigate to="/settings/companies" replace />} />
        <Route path="/settings" element={<RequirePermission anyPerm={SETTINGS_PERMS}><ModuleWorkspaceLayout /></RequirePermission>}>
          <Route index element={<SettingsHome />} />
          <Route path="organization" element={<RequirePermission perm="organization.view"><OrganizationPage /></RequirePermission>} />
          <Route path="companies" element={<RequirePermission perm="companies.view"><CompaniesPage /></RequirePermission>} />
          <Route path="finance" element={<RequirePermission perm="settings.manage"><FinanceSettingsPage /></RequirePermission>} />
          <Route path="notifications" element={<RequirePermission perm="settings.manage"><GroupSettingsPage group="notifications" /></RequirePermission>} />
          <Route path="security" element={<RequirePermission perm="settings.manage"><GroupSettingsPage group="security" /></RequirePermission>} />
          <Route path="roles" element={<RequirePermission perm="roles.manage"><RolesSettingsPage /></RequirePermission>} />
          <Route path="modules/:slug" element={<RequirePermission perm="settings.manage"><ModuleSettingsPage /></RequirePermission>} />
        </Route>
        <Route path="/modules" element={<RequirePermission perm="modules.view"><ModulesPage /></RequirePermission>} />
        <Route path="/modules/dashboard" element={<RequirePermission anyPerm={DASHBOARD_PERMS}><ModuleWorkspaceLayout /></RequirePermission>}>
          <Route index element={<GroupOverviewPage />} />
          <Route path="actions" element={<GroupActionsPage />} />
          <Route path=":slug" element={<GroupModuleDashboardPage />} />
        </Route>
        <Route path="/modules/kams" element={<RequireModule slug="kams"><ModuleWorkspaceLayout /></RequireModule>}>
          <Route index element={<KamsEntry />} />
          <Route path="members" element={<RequirePermission anyPerm={['kams.members.view', 'kams.recruitment.view']}><KamsMembersWorkspace /></RequirePermission>}>
            <Route index element={<KamsRecruitmentDashboard />} />
            <Route path="recruitment" element={<RequirePermission perm="kams.recruitment.view"><KamsCandidates /></RequirePermission>} />
            <Route path="agents" element={<RequirePermission perm="kams.members.view"><KamsAgents /></RequirePermission>} />
          </Route>
          <Route path="recruitment" element={<Navigate to="/modules/kams/members" replace />} />
          <Route path="candidates" element={<Navigate to="/modules/kams/members/recruitment" replace />} />
          <Route path="migration" element={<Navigate to="/modules/kams/members/agents" replace />} />
          <Route path="communications" element={<Navigate to="/modules/kams/members" replace />} />
          <Route path="clients" element={<RequirePermission perm="kams.clients.view"><KamsClients /></RequirePermission>} />
          <Route path="products" element={<RequirePermission perm="kams.products.view"><KamsProducts /></RequirePermission>} />
          <Route path="orders" element={<RequirePermission perm="kams.orders.view"><KamsOrders /></RequirePermission>} />
          <Route path="campaigns" element={<RequirePermission perm="kams.campaigns.view"><KamsCampaigns /></RequirePermission>} />
        </Route>
        <Route element={<ModuleWorkspaceLayout />}>
          <Route path="/users" element={<RequirePermission perm="users.view"><UsersPage /></RequirePermission>} />
          <Route path="/notifications" element={<RequirePermission perm="notifications.view"><NotificationsPage /></RequirePermission>} />
          <Route path="/audit" element={<RequirePermission perm="audit.view"><AuditPage /></RequirePermission>} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
