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
- Excel: PhpSpreadsheet (replaces Apache POI). LLM: Laravel HTTP client against the Messages API (replaces the Anthropic SDK).

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
| `backend/src/test/**` (unit tests) | `tests/Unit/**` (engine, calendar, translator) |

Every endpoint the screens call is a route here (checked against `resources/js` and `lib/types.ts`). The JSON field names
follow `lib/types.ts`.

## Verification status

Run on Windows with PHP 8.3.33 (ZTS, winget) and Composer 2.10.3:

- `composer install`, `php artisan migrate --seed --force`: succeed.
- `php artisan test`: **48 tests, 12 445 assertions, all passing** (unit: engine, calendar, translator, Java RNG; feature:
  HTTP scenarios and the documented workbooks).
- Live server (`php artisan serve`) checked with curl: the SPA shell and its built bundle, JSON 404 for unknown API paths,
  login with the CSRF token, session-based `/api/auth/me` and `/api/dashboard/owner`, wrong-password 401, deep links to the SPA.
- Frontend: `tsc --noEmit` passes; `vite build` produces `public/build/manifest.json` and the bundle.

Bugs the tests found and that are fixed: translator rules containing `/` broke the regex delimiter; PhpSpreadsheet's
`getCellByColumnAndRow` does not exist (cells are addressed by coordinate string); imported operations did not set
`created_at`.

Not yet run: the screens in a browser (only their build and type-check ran), the LLM method against a live model, and the
Java tests listed under "Not ported".

## Not ported (yet)

- Java HTTP-level tests: `ForceFlowIntegrationTest`, `MultiProjectFlowIntegrationTest`, `PlanForceTest`, `ForceMessagesTest`,
  `PlanPerformanceTest`, `AlgorithmStrategyTest`, `LlmStrategyValidationTest`.
  The unit-level ones are ported (`SchedulePlannerTest`, `PlanOptimizerTest`, `GraphUtilTest`, `JalaliTest`, `FaTranslatorTest`).
- Backup/restore tooling (the same as before: copy the database file while stopped).
