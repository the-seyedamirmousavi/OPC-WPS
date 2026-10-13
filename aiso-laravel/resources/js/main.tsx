import "./app.css";

import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, Navigate, Outlet, Route, Routes, useParams } from "react-router-dom";
import { AppShell } from "@/components/Shell";
import { Providers, type Lang } from "@/components/Providers";
import HomePage from "@/pages/home";
import LoginPage from "@/pages/login/page";
import OwnerPage from "@/pages/owner/page";
import DepartmentPanelPage from "@/pages/departments/panel/page";
import SystemAdministrationPage from "@/pages/owner/system/page";
import OwnerRolesPage from "@/pages/owner/roles/page";
import AssistantLogPage from "@/pages/owner/assistant/page";
import { ManagerArea } from "@/components/ManagerArea";
import ManagerPage from "@/pages/manager/page";
import ManagerAssignmentPage from "@/pages/manager/assignment/page";
import ManagerOperationsPage from "@/pages/manager/operations/page";
import ManagerPlanPage from "@/pages/manager/plan/page";
import ManagerProjectsPage from "@/pages/manager/projects/page";
import ManagerReportsPage from "@/pages/manager/reports/page";
import MyTasksPage from "@/pages/my/page";
import ChatPage from "@/pages/chat/page";
import PlannerPage from "@/pages/manager/planner/page";
import VendorPage from "@/pages/vendor/page";

/** The language is remembered in a cookie (set by the language switch); Persian is the default. */
const initialLang: Lang = document.cookie.match(/(?:^|; )aiso_lang=(\w+)/)?.[1] === "en" ? "en" : "fa";
document.documentElement.lang = initialLang;
document.documentElement.dir = initialLang === "fa" ? "rtl" : "ltr";

/** The panel's old address under the owner console: it is the same page at /departments/:id now. */
function OldDepartmentPanel() {
  const { id = "" } = useParams();
  return <Navigate to={`/departments/${id}`} replace />;
}

/** Every screen that needs a signed-in user renders inside the application shell (navigation, header). */
function AppLayout() {
  return (
    <AppShell>
      <Outlet />
    </AppShell>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <BrowserRouter>
      <Providers initialLang={initialLang}>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          {/* The software vendor's console: no link anywhere, reached by signing in with the vendor's own credentials. */}
          <Route path="/vendor" element={<VendorPage />} />
          <Route element={<AppLayout />}>
            <Route index element={<HomePage />} />
            <Route path="owner" element={<OwnerPage />} />
            <Route path="owner/system" element={<SystemAdministrationPage />} />
            <Route path="owner/roles" element={<OwnerRolesPage />} />
            <Route path="owner/assistant" element={<AssistantLogPage />} />
            <Route path="owner/departments" element={<Navigate to="/departments" replace />} />
            <Route path="owner/departments/:id" element={<OldDepartmentPanel />} />
            <Route path="departments" element={<Navigate to="/owner" replace />} />
            <Route path="departments/:id" element={<DepartmentPanelPage />} />
            <Route path="manager" element={<ManagerPage />} />
            <Route path="manager/projects" element={<ManagerArea section="projects"><ManagerProjectsPage /></ManagerArea>} />
            <Route path="manager/assignment" element={<ManagerArea section="assignment"><ManagerAssignmentPage /></ManagerArea>} />
            <Route path="manager/operations" element={<ManagerArea section="operations"><ManagerOperationsPage /></ManagerArea>} />
            <Route path="manager/plan" element={<ManagerArea section="plan"><ManagerPlanPage /></ManagerArea>} />
            <Route path="manager/planner" element={<ManagerArea section="planner"><PlannerPage /></ManagerArea>} />
            <Route path="manager/reports" element={<ManagerArea section="reports"><ManagerReportsPage /></ManagerArea>} />
            <Route path="my" element={<MyTasksPage />} />
            <Route path="chat" element={<ChatPage />} />
            <Route path="*" element={<HomePage />} />
          </Route>
        </Routes>
      </Providers>
    </BrowserRouter>
  </StrictMode>,
);
