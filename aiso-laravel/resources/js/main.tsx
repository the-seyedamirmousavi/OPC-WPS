import "./app.css";

import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, Outlet, Route, Routes } from "react-router-dom";
import { AppShell } from "@/components/Shell";
import { Providers, type Lang } from "@/components/Providers";
import HomePage from "@/pages/home";
import LoginPage from "@/pages/login/page";
import OwnerPage from "@/pages/owner/page";
import OwnerUsersPage from "@/pages/owner/users/page";
import ManagerPage from "@/pages/manager/page";
import ManagerAssignmentPage from "@/pages/manager/assignment/page";
import ManagerOperationsPage from "@/pages/manager/operations/page";
import ManagerPlanPage from "@/pages/manager/plan/page";
import ManagerProjectsPage from "@/pages/manager/projects/page";
import ManagerReportsPage from "@/pages/manager/reports/page";
import ImportPage from "@/pages/import/page";
import AuditPage from "@/pages/audit/page";
import MyTasksPage from "@/pages/my/page";

/** The language is remembered in a cookie (set by the language switch); Persian is the default. */
const initialLang: Lang = document.cookie.match(/(?:^|; )aiso_lang=(\w+)/)?.[1] === "en" ? "en" : "fa";
document.documentElement.lang = initialLang;
document.documentElement.dir = initialLang === "fa" ? "rtl" : "ltr";

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
          <Route element={<AppLayout />}>
            <Route index element={<HomePage />} />
            <Route path="owner" element={<OwnerPage />} />
            <Route path="owner/users" element={<OwnerUsersPage />} />
            <Route path="manager" element={<ManagerPage />} />
            <Route path="manager/projects" element={<ManagerProjectsPage />} />
            <Route path="manager/assignment" element={<ManagerAssignmentPage />} />
            <Route path="manager/operations" element={<ManagerOperationsPage />} />
            <Route path="manager/plan" element={<ManagerPlanPage />} />
            <Route path="manager/reports" element={<ManagerReportsPage />} />
            <Route path="import" element={<ImportPage />} />
            <Route path="audit" element={<AuditPage />} />
            <Route path="my" element={<MyTasksPage />} />
            <Route path="*" element={<HomePage />} />
          </Route>
        </Routes>
      </Providers>
    </BrowserRouter>
  </StrictMode>,
);
