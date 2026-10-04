import type { ReactNode } from 'react'
import { Navigate, Route, Routes, useLocation } from 'react-router-dom'
import { LoadingScreen } from './components/LoadingScreen'
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
import { KamsEntry } from './pages/kams/KamsPages'
import { KamsAgents, KamsCandidates, KamsMembersWorkspace, KamsRecruitmentDashboard, KamsRecruitmentReports, KamsRecruitmentWorkspace } from './pages/kams/KamsRecruitmentPages'
import { KamsClientsList, KamsClientsWorkspace, KamsSuppliersList } from './pages/kams/KamsClientsPage'
import { KamsAssignments, KamsAssignmentsWorkspace } from './pages/kams/KamsStockFlows'
import { KamsAssignmentReports } from './pages/kams/KamsAssignmentReports'
import { KamsStockJournal } from './pages/kams/KamsStockJournal'
import { KamsDepots, KamsWarehouses } from './pages/kams/KamsStockLocations'
import { KamsWarehouseMovements } from './pages/kams/KamsWarehouseMovements'
import { KamsStockStats, KamsStockWorkspace } from './pages/kams/KamsStockPages'
import { KamsAccountPage } from './pages/kams/KamsAccountPage'
import { KamsCompanyAccountPage } from './pages/kams/KamsCompanyAccountPage'
import { KamsFinanceIndex, KamsFinanceWorkspace } from './pages/kams/KamsFinancePages'
import { KamsMemberAccountsPage } from './pages/kams/KamsMemberAccountsPage'
import { AdministrationIndex } from './pages/administration/AdministrationIndex'

function Guard({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <LoadingScreen variant="page" />
  if (!user) return <Navigate to="/login" replace />
  return children
}

function Guest({ children }: { children: ReactNode }) {
  const { user, loading } = useAuth()
  if (loading) return <LoadingScreen variant="page" />
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
            <Route path="recruitment" element={<RequirePermission perm="kams.recruitment.view"><KamsRecruitmentWorkspace /></RequirePermission>}>
              <Route index element={<KamsCandidates />} />
              <Route path="reports" element={<KamsRecruitmentReports />} />
            </Route>
            <Route path="agents" element={<RequirePermission perm="kams.members.view"><KamsAgents /></RequirePermission>} />
          </Route>
          <Route path="recruitment" element={<Navigate to="/modules/kams/members" replace />} />
          <Route path="candidates" element={<Navigate to="/modules/kams/members/recruitment" replace />} />
          <Route path="migration" element={<Navigate to="/modules/kams/members/agents" replace />} />
          <Route path="communications" element={<Navigate to="/modules/kams/members" replace />} />
          <Route path="clients" element={<RequirePermission perm="kams.clients.view"><KamsClientsWorkspace /></RequirePermission>}>
            <Route index element={<KamsClientsList />} />
            <Route path="suppliers" element={<KamsSuppliersList />} />
          </Route>
          <Route path="stock" element={<RequirePermission anyPerm={['kams.products.view', 'kams.orders.view']}><KamsStockWorkspace /></RequirePermission>}>
            <Route index element={<KamsStockStats />} />
            <Route path="warehouses" element={<RequirePermission perm="kams.products.view"><KamsWarehouses /></RequirePermission>} />
            <Route path="warehouses/:warehouseId/movements" element={<RequirePermission perm="kams.products.view"><KamsWarehouseMovements /></RequirePermission>} />
            <Route path="depots" element={<RequirePermission perm="kams.products.view"><KamsDepots /></RequirePermission>} />
            <Route path="depots/:depotId/movements" element={<RequirePermission perm="kams.products.view"><KamsWarehouseMovements /></RequirePermission>} />
            <Route path="products" element={<Navigate to="/modules/kams/stock/warehouses" replace />} />
            <Route path="transfers" element={<Navigate to="/modules/kams/stock/warehouses" replace />} />
            <Route path="receipts" element={<Navigate to="/modules/kams/stock/warehouses" replace />} />
            <Route path="assignments" element={<RequirePermission perm="kams.products.view"><KamsAssignmentsWorkspace /></RequirePermission>}>
              <Route index element={<KamsAssignments />} />
              <Route path="reports" element={<KamsAssignmentReports />} />
              <Route path="reports/:date" element={<KamsAssignmentReports />} />
              <Route path="journal" element={<KamsStockJournal />} />
            </Route>
            <Route path="sales" element={<Navigate to="/modules/kams/stock/assignments" replace />} />
            <Route path="returns" element={<Navigate to="/modules/kams/stock" replace />} />
            <Route path="commissions" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="savings" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="daily" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="hierarchy" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="orders" element={<Navigate to="/modules/kams/finance" replace />} />
          </Route>
          <Route path="finance" element={<RequirePermission anyPerm={['kams.finance.dashboard.view', 'kams.finance.account.view', 'kams.finance.company.view']}><KamsFinanceWorkspace /></RequirePermission>}>
            <Route index element={<KamsFinanceIndex />} />
            <Route path="accounts" element={<RequirePermission perm="kams.finance.dashboard.view"><KamsMemberAccountsPage /></RequirePermission>} />
            <Route path="account" element={<RequirePermission perm="kams.finance.account.view"><KamsAccountPage /></RequirePermission>} />
            <Route path="company" element={<RequirePermission perm="kams.finance.company.view"><KamsCompanyAccountPage /></RequirePermission>} />
            <Route path="revenue" element={<Navigate to="/administration/settings/finance" replace />} />
            <Route path="orders" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="commissions" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="savings" element={<Navigate to="/modules/kams/finance/accounts" replace />} />
            <Route path="daily" element={<Navigate to="/modules/kams/finance" replace />} />
            <Route path="hierarchy" element={<Navigate to="/modules/kams/finance" replace />} />
          </Route>
          <Route path="products" element={<Navigate to="/modules/kams/stock/warehouses" replace />} />
          <Route path="orders" element={<Navigate to="/modules/kams/finance" replace />} />
        </Route>
        <Route element={<ModuleWorkspaceLayout />}>
          <Route path="/notifications" element={<RequirePermission perm="notifications.view"><NotificationsPage /></RequirePermission>} />
        </Route>
      </Route>
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
  )
}
