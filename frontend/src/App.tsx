import type { ReactNode } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { AppShell } from './components/layout/AppShell'
import { ModuleWorkspaceLayout } from './components/layout/ModuleWorkspaceLayout'
import { RequireModule, RequirePermission } from './components/RequirePermission'
import { useAuth } from './context/AuthContext'
import { DASHBOARD_PERMS, SETTINGS_PERMS, ADMIN_PERMS } from './lib/access'
import { AuditPage } from './pages/AuditPage'
import { CompaniesPage } from './pages/CompaniesPage'
import { DashboardPage } from './pages/DashboardPage'
import { GroupActionsPage, GroupDashboardWorkspace, GroupModuleDashboardPage, GroupOverviewPage } from './pages/group/GroupDashboardPages'
import { LoginPage } from './pages/LoginPage'
import { ModulesPage } from './pages/ModulesPage'
import { NotificationsPage } from './pages/NotificationsPage'
import { OrganizationPage } from './pages/OrganizationPage'
import { UsersPage } from './pages/UsersPage'
import { GroupSettingsPage, ModuleSettingsPage, SettingsHome, SettingsWorkspace } from './pages/settings/SettingsPages'
import { FinanceSettingsPage } from './pages/settings/FinanceSettingsPage'
import { RolesSettingsPage } from './pages/settings/RolesSettingsPage'
import { KamsCampaigns, KamsClients, KamsEntry } from './pages/kams/KamsPages'
import { KamsAgents, KamsCandidates, KamsMembersWorkspace, KamsRecruitmentDashboard } from './pages/kams/KamsRecruitmentPages'
import { KamsAssignments, KamsTransfers } from './pages/kams/KamsStockFlows'
import { KamsReceipts, KamsReturns } from './pages/kams/KamsStockCommerce'
import { KamsDepots, KamsStockProducts, KamsWarehouses } from './pages/kams/KamsStockLocations'
import { KamsStockStats, KamsStockWorkspace } from './pages/kams/KamsStockPages'
import { KamsAccountPage } from './pages/kams/KamsAccountPage'
import { KamsCompanyAccountPage } from './pages/kams/KamsCompanyAccountPage'
import { KamsFinanceIndex, KamsFinanceWorkspace } from './pages/kams/KamsFinancePages'
import { AdministrationIndex } from './pages/administration/AdministrationIndex'

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

function SettingsLegacyRedirect() {
  const { pathname } = useLocation()
  return <Navigate to={pathname.replace(/^\/settings/, '/administration/settings')} replace />
}

function DashboardLegacyRedirect() {
  const { pathname } = useLocation()
  const rest = pathname.replace(/^\/modules\/dashboard/, '')
  return <Navigate to={`/administration/dashboard${rest}`} replace />
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Guest><LoginPage /></Guest>} />
      <Route element={<Guard><AppShell /></Guard>}>
        <Route path="/" element={<DashboardPage />} />
        <Route path="/organization" element={<Navigate to="/administration/settings/organization" replace />} />
        <Route path="/companies" element={<Navigate to="/administration/settings/companies" replace />} />
        <Route path="/users" element={<Navigate to="/administration/users" replace />} />
        <Route path="/audit" element={<Navigate to="/administration/audit" replace />} />
        <Route path="/settings/*" element={<SettingsLegacyRedirect />} />
        <Route path="/modules/dashboard/*" element={<DashboardLegacyRedirect />} />
        <Route path="/administration" element={<RequirePermission anyPerm={ADMIN_PERMS}><ModuleWorkspaceLayout /></RequirePermission>}>
          <Route index element={<AdministrationIndex />} />
          <Route path="dashboard" element={<RequirePermission anyPerm={DASHBOARD_PERMS}><GroupDashboardWorkspace /></RequirePermission>}>
            <Route index element={<GroupOverviewPage />} />
            <Route path="actions" element={<GroupActionsPage />} />
            <Route path=":slug" element={<GroupModuleDashboardPage />} />
          </Route>
          <Route path="users" element={<RequirePermission perm="users.view"><UsersPage /></RequirePermission>} />
          <Route path="audit" element={<RequirePermission perm="audit.view"><AuditPage /></RequirePermission>} />
          <Route path="settings" element={<RequirePermission anyPerm={SETTINGS_PERMS}><SettingsWorkspace /></RequirePermission>}>
            <Route index element={<SettingsHome />} />
            <Route path="organization" element={<RequirePermission perm="organization.view"><OrganizationPage /></RequirePermission>} />
            <Route path="companies" element={<RequirePermission perm="companies.view"><CompaniesPage /></RequirePermission>} />
            <Route path="finance" element={<RequirePermission perm="settings.manage"><FinanceSettingsPage /></RequirePermission>} />
            <Route path="notifications" element={<RequirePermission perm="settings.manage"><GroupSettingsPage group="notifications" /></RequirePermission>} />
            <Route path="security" element={<RequirePermission perm="settings.manage"><GroupSettingsPage group="security" /></RequirePermission>} />
            <Route path="roles" element={<RequirePermission perm="roles.manage"><RolesSettingsPage /></RequirePermission>} />
            <Route path="modules/:slug" element={<RequirePermission perm="settings.manage"><ModuleSettingsPage /></RequirePermission>} />
          </Route>
        </Route>
        <Route path="/modules" element={<RequirePermission perm="modules.view"><ModulesPage /></RequirePermission>} />
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
          <Route path="stock" element={<RequirePermission anyPerm={['kams.products.view', 'kams.orders.view']}><KamsStockWorkspace /></RequirePermission>}>
            <Route index element={<KamsStockStats />} />
            <Route path="warehouses" element={<RequirePermission perm="kams.products.view"><KamsWarehouses /></RequirePermission>} />
            <Route path="depots" element={<RequirePermission perm="kams.products.view"><KamsDepots /></RequirePermission>} />
            <Route path="products" element={<RequirePermission perm="kams.products.view"><KamsStockProducts /></RequirePermission>} />
            <Route path="transfers" element={<RequirePermission perm="kams.products.view"><KamsTransfers /></RequirePermission>} />
            <Route path="receipts" element={<RequirePermission perm="kams.products.view"><KamsReceipts /></RequirePermission>} />
            <Route path="assignments" element={<RequirePermission perm="kams.products.view"><KamsAssignments /></RequirePermission>} />
            <Route path="sales" element={<Navigate to="/modules/kams/stock/assignments" replace />} />
            <Route path="returns" element={<RequirePermission perm="kams.products.view"><KamsReturns /></RequirePermission>} />
            <Route path="commissions" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="savings" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="daily" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="hierarchy" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="orders" element={<Navigate to="/modules/kams/finance" replace />} />
          </Route>
          <Route path="finance" element={<RequirePermission anyPerm={['kams.finance.dashboard.view', 'kams.finance.account.view', 'kams.finance.company.view']}><KamsFinanceWorkspace /></RequirePermission>}>
            <Route index element={<KamsFinanceIndex />} />
            <Route path="account" element={<RequirePermission perm="kams.finance.account.view"><KamsAccountPage /></RequirePermission>} />
            <Route path="company" element={<RequirePermission perm="kams.finance.company.view"><KamsCompanyAccountPage /></RequirePermission>} />
            <Route path="orders" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="commissions" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="savings" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="daily" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="hierarchy" element={<Navigate to="/modules/kams/finance" replace />} />
          </Route>
          <Route path="products" element={<Navigate to="/modules/kams/stock/products" replace />} />
          <Route path="orders" element={<Navigate to="/modules/kams/finance" replace />} />
          <Route path="campaigns" element={<RequirePermission perm="kams.campaigns.view"><KamsCampaigns /></RequirePermission>} />
        </Route>
        <Route element={<ModuleWorkspaceLayout />}>
          <Route path="/notifications" element={<RequirePermission perm="notifications.view"><NotificationsPage /></RequirePermission>} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
