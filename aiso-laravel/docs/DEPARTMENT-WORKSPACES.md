# Department workspaces: page inventory and map

This document describes how the owner's area is organized around departments, which page belongs to which department,
and how access is enforced. It reflects the code as of this change; the evidence for each classification is the page
implementation and the API it calls.

## 1. How the application works

- **Stack:** Laravel (JSON API under `/api`, session cookie, role middleware) and a React single-page application
  (`resources/js`, `react-router`). `resources/views/app.blade.php` is only the page shell.
- **Roles:** OWNER, MANAGER, USER_1..3 (executors). Authorization is enforced on the server: `role:` middleware for
  whole areas and `DepartmentService` for department rules. The menu and the shell redirects are conveniences only.
- **Departments:** created by the owner. A department has members (role HEAD or MEMBER) and a permission matrix per
  role (`PANEL_VIEW`, `RESOURCES_MANAGE`, `SUGGESTIONS_SUBMIT`). Two departments carry a **special role** that the
  application knows: `HR` (human resources) and `PRODUCTION`. The owner assigns a special role in the department's
  settings. The migrations adopt an existing department named «منابع انسانی» as HR, and one named «تولید» as PRODUCTION.
- **Workspace:** `/departments/:id` shows tabs. Which tabs a person sees is computed on the server (`abilities`).

## 2. Owner navigation

| Primary destination | Route | Content |
|---|---|---|
| Dashboard | `/owner` | KPIs, what needs attention, department overview, status distribution, accounts summary, latest imports and audit events |
| Departments | `/departments` | directory: purpose, head, members, resources, summary, link to the workspace; create a department |

Everything else is inside a workspace, except **System administration** (`/owner/system`), which is in the account
menu: it is not department work and is not a primary navigation item.

## 3. Inventory of pages and their department

| Page | Route | Business purpose (from the code) | APIs | Department | Roles | Placement |
|---|---|---|---|---|---|---|
| Owner dashboard | `/owner` | business summary | `GET /dashboard/owner`, `GET /departments` | n/a | OWNER | Owner Dashboard |
| Departments directory | `/departments` | list, create, open workspaces | `GET/POST /departments` | n/a | OWNER | Owner navigation |
| Department overview | `/departments/:id` | people and roles, permissions, resources kept up to date, suggestions to the manager | `/departments/*` | each department | OWNER; members with PANEL_VIEW | Workspace tab |
| Manager dashboard | tab `manager` | KPIs, critical path, resources, workload, suggestions to decide | `GET /dashboard/manager`, `/department-suggestions` | the department a manager heads | OWNER; that manager | Workspace tab |
| Projects and priorities | tab `projects` (`/manager/projects`) | project priority, due dates, schedule impact | `/projects`, `/scheduling/*` | PRODUCTION | OWNER, MANAGER | Workspace tab |
| Task assignment | tab `assignment` | assign operations to executors (algorithm or AI), approve proposals, assignment mode | `/assignments/*`, `/settings/assignment-mode` | PRODUCTION | OWNER, MANAGER | Workspace tab |
| Operations | tab `operations` | list and filter operations | `GET /operations` | PRODUCTION | OWNER, MANAGER | Workspace tab |
| Plan builder | tab `planner` | build or load a plan (projects, resources, operations), planning goal | `/planning/*` | PRODUCTION | OWNER, MANAGER | Workspace tab |
| Plan | tab `plan` | timeline of the plan | `/dashboard/manager`, `/operations` | PRODUCTION | OWNER, MANAGER | Workspace tab |
| Reports | tab `reports` | user performance, failed messages | `/reports/users`, `/notifications/failed` | PRODUCTION (uncertain, see 5) | OWNER, MANAGER | Workspace tab |
| Import history | tab `imports` | history of every plan or Excel save | `GET /departments/{id}/imports` | PRODUCTION | OWNER; members with PANEL_VIEW | Workspace tab |
| Users | tab `users` | accounts, roles, activation, passwords | `/users` | HR | OWNER | Workspace tab |
| Audit log | tab `audit` | every change in the system | `GET /departments/{id}/audit` | HR (uncertain, see 5) | OWNER | Workspace tab |
| System administration | `/owner/system` | assignment mode, AI service, settings, demo data, reset, suspend | `/settings*`, `/admin/*` | n/a (system) | OWNER | Account menu |
| My tasks | `/my` | an executor's own tasks | `/my/tasks`, `/dashboard/user` | n/a (shared) | USER_1..3 | Executor navigation |
| Notifications, Bale link | header | own inbox and messenger link | `/notifications`, `/me/bale` | n/a (shared) | all | Header |
| Vendor console | `/vendor` | licence and vendor operations | `/vendor/*` | n/a (outside the app) | vendor sign-in | Unchanged |

Managers keep their own navigation (the six pages in place plus the departments they belong to). The owner reaches the
same pages only inside the Production workspace: an owner opening `/manager/*` is sent to the matching tab.

## 4. Access control

- Every API route keeps its original `role:` middleware. Nothing was loosened.
- New department endpoints are checked in `DepartmentService`: `assertCanView` (PANEL_VIEW or owner), audit (owner, HR
  department only), import history (PRODUCTION department, panel viewers), planning tabs (OWNER or MANAGER who is a
  member), manager dashboard (owner, or the manager who heads the department).
- `/api/audit` and `/api/import/history` still exist for owner and manager (used by existing tests and tools).
- Tests: `tests/Feature/DepartmentAccessTest.php`.

## 5. Open questions for the business owner

1. **Reports** mixes user performance (could be HR) and failed messages (system). It is placed in Production.
2. **Audit log** is cross-department. It is in HR and owner-only; a different home, or visibility for HR members, is a
   business decision.
3. **Assignment mode** is also on the Task assignment page, so it is in System administration and in Production.
4. **No Production department:** the owner cannot reach the planning pages until a department has the Production role.
   The directory warns about this.
5. **Managers:** they keep their own navigation and are not forced into the workspace; say if they should be.
6. `/api/audit` and `/api/import/history` are still open to managers.
