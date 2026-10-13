# AISO → Laravel port: status and map

Source of truth: `backend/` (Spring Boot 4.1, Java 21) and `frontend/` (Next.js 16) in the parent repository, working tree
as it was when the port was made (including the uncommitted MVP 0.3 FORCE work).

## Architecture

- One Laravel 12 application (PHP 8.2+). `/api/*` is the JSON API inside the web middleware group, so the session cookie and
  CSRF protection apply. Every other path serves the React single-page application (`resources/views/app.blade.php`).
- Authentication: Laravel session (`app_user` is the authenticatable model). The Spring JWT and the Next.js session proxy are
  gone; the browser holds the session cookie, and the client sends the CSRF token from the `XSRF-TOKEN` cookie.
- Frontend: React 19 + TypeScript + Tailwind 4, built by Vite into `public/build` (manifest read by `@vite`), routed with
  React Router. `lib/i18n.ts`, `lib/jalali.ts`, `lib/types.ts` and the components are the originals; the pages use the same
  components.
- Database: SQLite by default (`database/database.sqlite`); MySQL and PostgreSQL through `.env`. One consolidated migration
  replaces the Flyway scripts V1–V4.
- Excel: PhpSpreadsheet (replaces Apache POI). AI: Laravel HTTP client against GapGPT's OpenAI-compatible chat completions API (replaces the Anthropic SDK; the Spring version used Anthropic).

## Java → PHP map

| Spring / Java | Laravel / PHP |
|---|---|
| `domain/*` entities and enums | `app/Models/*` (15 models), `app/Enums/*` (8 backed enums) |
| Flyway `V1`–`V4` | `database/migrations/2026_01_01_000001_create_aiso_schema.php` |
| `DataSeeder` | `database/seeders/AisoSeeder.php` (run by `migrate --seed`) |
| `SecurityConfig`, `TokenService`, `UserJwtConverter`, `CurrentUser` | `config/auth.php`, session auth, `Controller::actor()` |
| `Access` (role expressions) | `role:…` middleware (`app/Http/Middleware/EnsureRole.php`) |
| `SuspensionInterceptor` | `app/Http/Middleware/CheckSuspension.php` |
| `GlobalExceptionHandler`, `ApiException` | `bootstrap/app.php` (renders), `app/Exceptions/ApiException.php` |
| `AisoProperties` | `config/aiso.php` |
| `SchedulePlanner`, `ForceCheck`, `PlanOptimizer`, `GraphUtil` | `app/Scheduling/*` (+ value classes `Task`, `Dep`, `Slot`, `Plan`, `Lanes`, `Conflict`, `Ref`, `Pins`, `OptimizeResult`) |
| `java.util.Random` (in `PlanOptimizer`) | `app/Scheduling/JavaRandom.php` (bit-exact LCG) |
| `ProjectData`, `ProjectDataService` | `app/Services/ProjectData.php`, `ProjectDataService.php` |
| `PlanService`, `PlanView`, `SchedulingService`, `ProjectService` | `app/Services/PlanService.php`, `PlanView.php`, `SchedulingService.php`, `ProjectService.php` |
| `ForceService`, `Forces` (views) | `app/Services/ForceService.php` (views as arrays) |
| `OperationService`, `OperationViewService`, `DependencyService` | `app/Services/OperationService.php`, `OperationViewService.php`, `DependencyService.php` |
| `AssignmentService`, `AlgorithmStrategy`, `LlmStrategy`, `AssignmentState`, `AssignmentContext`, `Suggestion` | `app/Services/AssignmentService.php`, `app/Assignment/*` |
| `UserService`, `AdminService`, `SettingsService` | `app/Services/UserService.php`, `AdminService.php` (settings helper `AdminService::saveChanged`) |
| `DashboardService`, `Dashboards` | `app/Services/DashboardService.php` (views as arrays) |
| `AuditService`, `NotificationService`, `LanguageService` | `app/Services/AuditService.php`, `NotificationService.php`, `LanguageService.php` |
| `ImportService`, `MasterParser`, `SimpleParser`, `SheetReader`, `ParsedData`, `FaNames`, `ImportTarget/Result/Issue` | `app/Imports/*` (`ImportService`, `ImportValidator`, `ImportApplier`, parsers, `SheetReader`, `ParsedData`, `FaNames`) |
| `ExcelService` | `app/Exports/ExcelService.php`, `SheetWriter.php` |
| `FaTranslator`, `FaLabels`, `Jalali`, `FaNames` | `app/Support/Text/FaTranslator.php`, `FaLabels.php`, `app/Support/Jalali.php`, `app/Imports/FaNames.php` |
| `web/*Controller` (all `@*Mapping`s) | `app/Http/Controllers/Api/*` (9 controllers) and `routes/web.php` |
| `frontend/app/**`, `components/**`, `lib/**` | `resources/js/**` (pages moved to `pages/`, routes in `main.tsx`) |
| `frontend/app/api/session`, `app/api/[...path]`, `proxy.ts`, `lib/server.ts` | not needed: the Laravel session serves the browser directly |
| `backend/src/test/**` (unit tests) | `tests/Unit/**` (engine, calendar, translator, TOTP) |
| *new in the Laravel edition, no Spring class* | `app/Services/LicenseService.php`, `VendorAuth.php`, `VendorService.php`, `PlanningService.php`; `app/Support/Totp.php`; `app/Http/Middleware/EnforceLicense.php`, `EnsureVendor.php`; `app/Http/Controllers/Api/VendorController.php`, `PlanningController.php`; `app/Messaging/*`, `app/Services/BaleLinkService.php` |

Every endpoint the screens call is a route here (checked against `resources/js` and `lib/types.ts`). The JSON field names
follow `lib/types.ts`.

## Verification status

Run on Windows with PHP 8.3.33 (ZTS, winget) and Composer 2.10.3:

- `composer install`, `php artisan migrate --seed --force`: succeed.
- `php artisan test`: **328 tests, 14 142 assertions, all passing** (for the current revision; the first port had 48) (unit: engine, calendar, translator, Java RNG; feature:
  HTTP scenarios and the documented workbooks).
- Live server (`php artisan serve`) checked with curl: the SPA shell and its built bundle, JSON 404 for unknown API paths,
  login with the CSRF token, session-based `/api/auth/me` and `/api/dashboard/owner`, wrong-password 401, deep links to the SPA.
- Frontend: `tsc --noEmit` passes; `vite build` produces `public/build/manifest.json` and the bundle.

Bugs the tests found and that are fixed: translator rules containing `/` broke the regex delimiter; PhpSpreadsheet's
`getCellByColumnAndRow` does not exist (cells are addressed by coordinate string); imported operations did not set
`created_at`.

Screens run in a browser: the import (including the priority step), the plan builder, the vendor console and the login page. The other
screens only build and type-check. Not yet run: the LLM method against a live model, and the
Java tests listed under "Not ported".

## Changes since the port

Made after the first port (the Spring version is unchanged):

- **Dialogue engine** (`app/AI/Dialogue`, `AISO_AGENT_ENGINE=dialogue`, default): stateful multi-turn assistant. Server-side conversation state (pending question, summary, focus) with expiry and version check; tools generated from the intent config; missing parameters collected over turns (names resolved to ids, choices for ambiguous names, «بی‌خیال» drops it); small context instead of the full briefing (first call 2,867 vs 11,980 prompt tokens measured on the development data; the legacy call overflowed the 16k window). Same rights and approval policy. `requestId` replays a retried message. Docs: docs/DEVELOPER.md 10.5a. Tests: `DialogueTest` (21). The legacy engine stays behind the setting.
- **First gate** (`IntentRouter`, your four-kind template, `AISO_AGENT_GATE`): every question gets one of read, action, chat or clarify before the route. Chat is answered without reading data; clarify is asked back; an unusable answer is ignored and the route decides as before. Covered by `IntentRouterTest` (8 tests); checked live on seven questions. Jev is not wired (its endpoint refuses chat requests).
- **Assistant log for the owner** (`AiLogController`, `/owner/assistant`, `AgentOrchestrator::systemPrompt()`): every question with its steps, the exact message each step received, the answer, tokens, duration and errors. Owner only, read only, and every value goes through `SensitiveDataFilter`. Covered by `AiLogTest` (5 tests).
- **Briefing for every run** (2nd round): every chat question and agent run gets the bounded briefing built from the departments the run may read. The HR section
  now lists every agent with its job description, the departments it reads and its five latest tasks with their results, so the assistant can answer
  what each agent does. Covered by `AgentBriefingTest` (9 tests).
- **Agent briefing** (`AgentBriefing`, `AgentOrchestrator`, `AgentService::runTask`, `aiso.agent.briefing`): every run of an AI agent is given its
  full job description (`briefing.agent.instructions`) and a bounded snapshot of what it may read: system settings, open operations with their state,
  projects, resources, executive users (production), accounts without contact details and the agents (HR), and members, stale resources and
  suggestions (other departments). Only the agent's scoped departments are included. The model sees the briefing in the route and answer stages;
  the trace shows its size. Covered by `AgentBriefingTest` and the updated `AgentsTest` assertion. A real check on the development database built
  Khosrow's briefing for its three departments (about 8 KB). Also fixed: `DepartmentMember::department()` relation was missing, which would have failed
  with memberships present.
- **Long AI calls and PHP time limit** (`HttpTls::allowWait`, `LlmStrategy`, `LlmClient`, `Embedder`): PHP stops a web request after `max_execution_time` (30 s here) even while it waits for the AI service, which ended a slow answer in a fatal error (reference E-JBG7LC). Before each AI call the limit is raised to the call timeout plus 15 s. Covered by `HttpWaitTest`.
- **Owner menu** (`Shell.tsx`): the owner sees one Dashboard, the department panels and the AI assistant, nothing else. Every planning page
  (projects, assignment, operations, plan builder, plan, reports) and the import history live inside the Production department's workspace; users,
  roles and the audit log inside Human resources (the owner's old addresses redirect there). A manager keeps the six planning pages as their own short
  menu and the assistant is a small button in their header. `AisoSeeder` creates the Production department on a fresh installation
  (`AISO_SEED_PRODUCTION_DEPARTMENT`, off in the tests); Human resources is created by its migration. An earlier attempt that put users, audit and
  import history back into the side menu was undone; a copy of the front end from before it is in `.claude/ui-backup-2026-10-12` (not in git).

- **Error explanations** (`NetworkDiagnosis`, `HttpTls`, `AISO_CA_BUNDLE`, error `reference` and owner-only `detail`): a failed AI or Bale call now says
  why (certificate bundle missing, timeout, DNS, token rejected, quota, model not found). Fixes a bug where any connection failure of the AI
  call ended in a TypeError (HTTP 500). Covered by `ErrorExplanationTest`.
- **AI service is GapGPT** (`LlmStrategy`, `LlmConfig`, `PUT /api/settings/llm`, owner console card): an OpenAI-compatible chat completions call replaces
  the Anthropic Messages call. The token and model are entered in the owner console; the token is stored encrypted and never returned.
  Covered by `LlmGapGptTest` against a simulated service (no live call was made).
- **Resource order** (`work_resource.sort_order`, `WorkResource::inEnteredOrder()`): resources keep the row order of the Resources sheet or of
  the plan builder; the timeline (`Gantt` `order` prop) and the tables follow it instead of the id order. Rows of the plan builder's resource table are reordered by drag and drop (pointer events on the ⠿ handle, so mouse, touch and pen work) or by the ▲▼ buttons, then saved with the plan. The order is covered by `ResourceOrderTest`; the drag itself has no automated test.
- **Plan builder** (`/manager/planner`, `PlanningService`, `PlanningController`, `ExcelService::plan()`): build the plan without Excel,
  check and save it, download it as an importable workbook. New; there is no Spring equivalent.
- **Shared import path:** `ImportService::processParsed()` serves both the Excel import and the plan builder, so the rules and the
  history are the same.
- **Excel inside the plan builder** (`POST /api/planning/from-excel`, `ImportService::parseWorkbook()`): choosing a file runs the import
  checks as a dry run and, when it passes, fills the tables. Covered by `PlanningGoalTest`. New.
- **Planning goal** (`PlanningGoal`, `system_setting.planning_goal`, `PlanOptimizer` pooled mode, `PlanService::focusRanks()`):
  project order (the existing behaviour, still the default), resources kept busy, or one chosen project finished fastest. A
  comparison of the three on the saved plan is on the plan builder. New; the Spring version has only project order. Covered by
  `PlanOptimizerTest` and `PlanningGoalTest`.
- **Import screen is now a history list** (`/import`, menu "Import history"): files are read on the plan builder instead. `POST /api/import`
  and its priority step (`PRIORITY_REQUIRED`) still exist on the server and are covered by `PriorityImportTest`; no screen uses them.
- **Persian messages:** validation and import messages are translated in Persian mode (the translation had been overwritten by the
  English text); covered by a plan-builder test.
- **Licence:** `company` and `license_event` tables, `LicenseService`, the `license` middleware. Limits for users, projects, the LLM
  and Bale. New; no Spring equivalent.
- **Vendor console** (`/vendor`, `VendorController`, `VendorService`, `VendorAuth`, `EnsureVendor`, `aiso:vendor-credentials`):
  the vendor's own sign-in, hidden from the application. Optional device cookie and authenticator code (`Totp`). New.
- **Sign-in throttling:** 10 failed attempts per address per minute. The first port had none.
- **Bale messenger connector** (`BaleBot`, `BaleClient`, `BaleLinkService`, `bale:poll`). New; in the first port the Spring version
  delivered in-app only.
- **Screens:** the date in the header, phone layout fixes, and the assignment page no longer fails when there is no run yet
  (`/api/assignments/latest` answers `null`).
- **Documentation:** the Excel import guide and the planner (user manual, Word manual); the vendor console guide.

## Not ported (yet)

- Java HTTP-level tests: `ForceFlowIntegrationTest`, `MultiProjectFlowIntegrationTest`, `PlanForceTest`, `ForceMessagesTest`,
  `PlanPerformanceTest`, `AlgorithmStrategyTest`, `LlmStrategyValidationTest`.
  The unit-level ones are ported (`SchedulePlannerTest`, `PlanOptimizerTest`, `GraphUtilTest`, `JalaliTest`, `FaTranslatorTest`).
- Backup/restore tooling (the same as before: copy the database file while stopped).
