# AISO Laravel edition: developer guide

This guide describes the Laravel edition in `aiso-laravel/` as it is in the working tree on 2026-10-11. Classes, methods,
routes, configuration keys and environment variables named here were checked against the source. The Spring and Next.js
originals appear only in the porting map ([PORTING-STATUS.md](../PORTING-STATUS.md)). The test suite was not executed while
this guide was written; the test counts come from the test sources.

## Contents

1. Overview and architecture
2. Directory map
3. Domain model
4. Scheduling engine
5. Services
6. HTTP API reference
7. Frontend
8. Configuration
9. Import and export pipeline
10. Security
11. Testing
12. Build, run and deploy
13. Known limits and open work
14. Contribution notes
15. Messaging connectors

---

## 1. Overview and architecture

One Laravel 12 application serves two things from one process:

- `/api/*` is the JSON API. It runs in the `web` middleware group, so the session cookie and CSRF protection apply.
- Every other path is the React single-page application. `SpaController` renders `resources/views/app.blade.php`, which
  loads the Vite bundle through `@vite`. React Router in `resources/js/main.tsx` draws the screens.

Stack:

| Layer | Technology (from `composer.json` and `package.json`) |
|---|---|
| Runtime | PHP `^8.2` (tested on PHP 8.3.33, ZTS), Composer 2 |
| Framework | Laravel `^12.0`, Eloquent, session authentication |
| Spreadsheets | `phpoffice/phpspreadsheet` `^3.9` |
| Frontend | React 19, TypeScript, Tailwind 4, React Router 7, Vite 7 |
| Database | SQLite by default (`database/database.sqlite`, WAL mode); MySQL and PostgreSQL through `.env` |

```
Browser
 |-- GET /, /manager/..., /my, ...  -> routes/web.php catch-all -> SpaController -> resources/views/app.blade.php
 |                                      (React bundle in public/build, routed in resources/js/main.tsx)
 |-- fetch /api/...  (JSON; cookie aiso_session; X-XSRF-TOKEN on writes; Accept-Language)
        |
        v
 public/index.php -> bootstrap/app.php (middleware aliases, JSON rendering of every /api error)
        |
        v
 web group: cookies, database session, CSRF check
        |
        v
 routes/web.php, /api group:  auth + suspension middleware -> role:... (EnsureRole)
        |
        v
 app/Http/Controllers/Api/*   validate input, call one service, return arrays as JSON
        |
        v
 app/Services/*  ----- ProjectDataService::load()  one read of all tables into a ProjectData snapshot
        |        |---- PlanService -> app/Scheduling  (PlanOptimizer, ForceCheck, SchedulePlanner)
        |        |---- AssignmentService -> app/Assignment  (AlgorithmStrategy, or LlmStrategy -> Messages API)
        |        |---- ImportService -> app/Imports  (SheetReader, parsers, ImportValidator, ImportApplier)
        |        |---- ExcelService -> app/Exports  (PhpSpreadsheet)
        |        |---- AuditService;  NotificationService (in-app inbox, messenger seam: section 15)
        v
 Eloquent models (app/Models) -> SQLite | MySQL | PostgreSQL
```

A typical scheduling read runs: controller, service, `ProjectDataService::load()`, `PlanService::plan()` (the optimiser runs in
PHP on every request; there is no plan cache), then a view array. Read endpoints and write endpoints that show times both
pay this cost.

Design decisions visible in the code:

- Identity and role come from the database on each request (`app_user.user_role`), never from the client.
- Business ids are primary keys (`operation.id`, `work_resource.id`, `project.id`, `app_user.id`), as in the original schema.
- Instants are stored in UTC (`config/app.php` `timezone` is `UTC`). Dates are shown in Solar Hijri in Persian mode, in
  Tehran time.
- The scheduling engine in `app/Scheduling` has no framework dependency.
- The application has no queue, no scheduler and no cache tables. The schema creates no `jobs`, `cache` or
  `password_reset_tokens` tables. Nothing runs in the background, except that the messaging connector in section 15 adds a
  `bale:poll` command that can run as a long-running process.
- Every `/api` error is answered as JSON `{"message": "..."}` (`bootstrap/app.php`).

---

## 2. Directory map

| Path | Contents |
|---|---|
| `app/Scheduling/` | Pure PHP scheduling engine: `SchedulePlanner`, `PlanOptimizer`, `ForceCheck`, `GraphUtil`, `JavaRandom`; value classes `Task`, `Dep`, `Slot`, `Plan`, `Lanes`, `Conflict`, `Ref`, `Pins`, `OptimizeResult`. |
| `app/Services/` | Application services: business rules, transactions, audit, notifications, and the view arrays the API returns (17 classes, section 5). |
| `app/Assignment/` | Task assignment: `AssignmentStrategy` (interface), `AlgorithmStrategy`, `LlmStrategy`, the shared constraint state `AssignmentState`, `AssignmentContext`, and value classes (`ReadyOp`, `UserInfo`, `ResourceInfo`, `Pick`, `Skip`, `Suggestion`, `LlmUnavailableException`). |
| `app/Imports/` | Excel import: `ImportService` (entry point), `SheetReader`, `MasterParser`, `SimpleParser`, `ParsedData`, `ImportValidator`, `ImportApplier`, `FaNames`. |
| `app/Exports/` | `ExcelService` (import template and report workbook), `SheetWriter` (styling, right-to-left, formula guard). |
| `app/Support/` | `Jalali` (Solar Hijri calendar, Tehran time); `Text/FaTranslator` (Persian rendering of system texts); `Text/FaLabels` (Persian labels for exports). |
| `app/Http/Controllers/` | `Controller` (base class: `actor()`, `input()`), `SpaController` (serves the application shell). |
| `app/Http/Controllers/Api/` | Nine JSON controllers: `AuthController`, `UserController`, `AdminController`, `ProjectController`, `ForceController`, `OperationController`, `AssignmentController`, `ImportController`, `DashboardController`. |
| `app/Http/Middleware/` | `EnsureRole` (alias `role`), `CheckSuspension` (alias `suspension`). |
| `app/Models/` | 15 Eloquent models (section 3.3). |
| `app/Enums/` | Eight backed enums: `AssignmentMode`, `DependencyType`, `OperationStatus`, `ProjectStatus`, `ProposalStatus`, `ReportType`, `Role`, `SystemStatus`. |
| `app/Exceptions/` | `ApiException`: an HTTP status and a message that is safe to show, with factories `badRequest`, `forbidden`, `notFound`, `conflict`. |
| `app/Providers/` | `AppServiceProvider` (empty; registered in `bootstrap/providers.php`). |
| `app/Messaging/`, `app/Console/Commands/` | Messaging connector files added by the connector work (section 15). `Console/Commands/` is discovered automatically. |
| `bootstrap/app.php` | Routing, the `role` and `suspension` aliases, and the JSON rendering of every `/api` error. |
| `config/` | Laravel configuration, plus `config/aiso.php` for application settings (section 8). |
| `database/migrations/` | One schema migration, `2026_01_01_000001_create_aiso_schema.php` (replaces Flyway V1 to V4). |
| `database/seeders/` | `DatabaseSeeder` calls `AisoSeeder` (accounts, settings, sample data). |
| `database/database.sqlite` | Default SQLite file (git-ignored). |
| `routes/web.php` | All `/api` routes and the single-page catch-all. `routes/console.php` holds only the default `inspire` command. |
| `resources/js/` | React application: `main.tsx` (routes), `components/`, `pages/`, `lib/` (`api.ts`, `i18n.ts`, `types.ts`, `jalali.ts`). |
| `resources/views/app.blade.php` | The single HTML shell. |
| `resources/samples/simple-sample.xlsx` | Sample workbook (16 operations, 15 predecessor links, 6 resources), loaded by the seeder and by the demo-data endpoint. |
| `public/` | Web root: `index.php`, `.htaccess`, and the generated `build/` directory. |
| `scripts/` | Setup and start scripts for Windows (`*.ps1`) and Linux or macOS (`*.sh`). |
| `tests/` | PHPUnit: `Unit/`, `Feature/`, `Fixtures/test-data/`, `TestCase.php`. |
| `storage/`, `bootstrap/cache/` | Logs, file cache, compiled views, framework files. Must be writable. |

Root files of interest: `composer.json` (scripts `setup` and `test`), `package.json` (scripts `build`, `typecheck`, `dev`),
`vite.config.ts`, `tsconfig.json`, `phpunit.xml`, `.env.example`, `README.md`, `PORTING-STATUS.md`.

---

## 3. Domain model

### 3.1 Tables

Source: `database/migrations/2026_01_01_000001_create_aiso_schema.php`. Primary keys are business ids unless marked
"auto". FK means a database foreign key.

| Table | Primary key | Key columns | Relations |
|---|---|---|---|
| `sessions` | `id` | `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity` | Session table for `SESSION_DRIVER=database`. |
| `app_user` | `id` (string, 64) | `full_name`, `user_role`, `active`, `password_hash` (bcrypt), `must_change_password`, `messenger_id`, `contact_info`, `created_at` | none |
| `work_resource` | `id` | `name`, `resource_type`, `capacity` (default 1), `status` (default `ACTIVE`), `responsible_user_id`, `description` | FK `responsible_user_id` to `app_user` |
| `item` | `id` | `name`, `quantity` (default 1), `unit`, `supply_type`, `related_operation_id`, `description` | none (`related_operation_id` is not a foreign key) |
| `project` | `id` | `name`, `priority` (rank 1..n among active projects), `due_date`, `status` (`ACTIVE`, `ARCHIVED`), `description`, `created_at` | none |
| `operation` | `id` | `name`, `project_id`, `item_id`, `resource_id`, `responsible_user_id`, `preparation_time`, `transport_time`, `setup_time`, `direct_time` (hours), `status`, `assigned_user_id`, `block_reason`, `status_before_block`, `progress_percent`, `planned_start`, `planned_end`, `assigned_at`, `started_at`, `completed_at`, `completion_approved`, `completion_approved_by`, `cancel_reason`, `description`, `created_at`, `updated_at`, `version` | FK `project_id` to `project`, `item_id` to `item`, `resource_id` to `work_resource`, `responsible_user_id` and `assigned_user_id` to `app_user`. Indexes on `status`, `assigned_user_id`, `project_id`. |
| `predecessor` | auto | `operation_id`, `predecessor_id`, `dependency_type` (default `FINISH_TO_START`), `start_condition`, `mandatory` (default true), `description` | FK both to `operation`. Unique (`operation_id`, `predecessor_id`). |
| `system_setting` | `id` (always 1) | `project_id`, `project_name`, `owner_id`, `manager_id`, `messenger_platform` (default `IN_APP`), `system_status`, `data_version`, `configuration_version`, `assignment_mode` (default `ALGORITHM`), `max_active_tasks_per_user` (default 3), `language`, `require_completion_approval` (default false), `updated_at` | none |
| `audit_event` | `event_id` (UUID) | `occurred_at` (indexed), `actor_id`, `action_name`, `entity_type`, `entity_id`, `previous_value`, `new_value` (4000 each), `reason` (1000), `project_id` | Index (`entity_type`, `entity_id`). Append-only by convention. |
| `notification` | auto | `recipient_id`, `kind`, `message` (1000), `related_operation_id`, `created_at`, `read_at`, `delivery_status` (`SENT` or `FAILED`), `delivery_error` | FK `recipient_id` to `app_user`. Index (`recipient_id`, `created_at`). |
| `operation_report` | auto | `operation_id`, `user_id`, `report_type`, `progress_percent`, `note` (2000), `created_at` | FK `operation_id` to `operation` (`user_id` is not a foreign key). |
| `assignment_run` | `id` (UUID) | `requested_mode`, `effective_mode`, `fallback`, `model`, `summary`, `warnings`, `unassigned`, `requested_by`, `created_at`, `duration_ms`, `input_tokens`, `output_tokens` | `unassigned` stores one line per skipped operation, as the operation id, a pipe and the reason. |
| `assignment_proposal` | auto | `run_id`, `operation_id`, `user_id`, `source` (mode), `reason`, `planned_start`, `planned_end`, `status` (`PENDING`, `APPROVED`, `REJECTED`, `SUPERSEDED`), `decision_note`, `created_at`, `decided_at`, `decided_by` | FK `run_id` to `assignment_run`, `operation_id` to `operation`, `user_id` to `app_user`. Index on `status`. |
| `import_log` | auto | `imported_at`, `actor_id`, `file_name`, `file_format`, `status`, `error_count`, `summary`, `details` | none. One row per import call, including dry runs. |
| `schedule_force` | `operation_id` | `forced_start`, `forced_user_id`, `reason`, `created_by`, `created_at` | One FORCE per operation. FK `operation_id` to `operation`, `forced_user_id` to `app_user`. |
| `plan_baseline` | `operation_id` | `planned_start`, `planned_end`, `saved_at` | The reference plan (section 4.6). FK to `operation`. |

### 3.2 Enums (`app/Enums`)

| Enum | Values | Notes |
|---|---|---|
| `Role` | `OWNER`, `MANAGER`, `USER_1`, `USER_2`, `USER_3` | `isExecutor()` is true for `USER_1` to `USER_3`. |
| `OperationStatus` | `NOT_READY`, `READY`, `ASSIGNED`, `IN_PROGRESS`, `BLOCKED`, `COMPLETED`, `CANCELLED` | `isActive()`: `ASSIGNED` or `IN_PROGRESS` (occupies a resource slot and counts toward the executor's task limit). `isTerminal()`: `COMPLETED` or `CANCELLED`. |
| `ProjectStatus` | `ACTIVE`, `ARCHIVED` | An archived project has no unfinished operations. |
| `DependencyType` | `FINISH_TO_START`, `START_TO_START` | Finish-to-start is the default. |
| `ProposalStatus` | `PENDING`, `APPROVED`, `REJECTED`, `SUPERSEDED` | A new suggestion run supersedes pending proposals. |
| `AssignmentMode` | `ALGORITHM`, `LLM` | Stored in `system_setting` and on each run. |
| `ReportType` | `ASSIGN`, `START`, `PROGRESS`, `COMPLETE`, `BLOCK`, `UNBLOCK`, `COMMENT`, `APPROVE`, `CANCEL`, `UNASSIGN` | Type of each row in `operation_report` (the operation history). |
| `SystemStatus` | `ACTIVE`, `SUSPENDED` | `SUSPENDED` makes the system read-only for everyone except the owner (section 10). |

### 3.3 Models (`app/Models`)

| Model | Table | Notes |
|---|---|---|
| `AppUser` | `app_user` | Extends the framework's authenticatable user. Auth password column is `password_hash`. `password_hash` and `remember_token` are hidden. `user_role` is cast to `Role`. Methods `role()`, `isActive()`, `operations()`. |
| `Operation` | `operation` | Casts statuses to enums. `totalHours()` is the sum of the four hour columns. Relations: `predecessors()`, `project()`, `resource()`, `item()`, `assignedUser()`, `responsibleUser()`, `force()`. |
| `Predecessor` | `predecessor` | No declared relations; `Operation::predecessors()` reads it. |
| `Project` | `project` | `status` cast to `ProjectStatus`; `due_date` as datetime. |
| `WorkResource` | `work_resource` | `isActive()` tests `status === 'ACTIVE'`. |
| `Item` | `item` | Bill-of-material row. |
| `ScheduleForce` | `schedule_force` | Primary key `operation_id`. |
| `PlanBaseline` | `plan_baseline` | Primary key `operation_id`. |
| `SystemSetting` | `system_setting` | Singleton. `SystemSetting::current()` creates row 1 with defaults on first use. |
| `AssignmentRun` | `assignment_run` | `proposals()` relation. |
| `AssignmentProposal` | `assignment_proposal` | Casts `source` to `AssignmentMode`, `status` to `ProposalStatus`. |
| `OperationReport` | `operation_report` | `report_type` cast to `ReportType`. |
| `AuditEvent` | `audit_event` | Primary key `event_id`. Written only by `AuditService::record()`. |
| `Notification` | `notification` | In-app inbox row. |
| `ImportLog` | `import_log` | Import history row. |

### 3.4 Operation lifecycle

```
NOT_READY --(predecessors met)--> READY --(assign)--> ASSIGNED --(start)--> IN_PROGRESS --(complete)--> COMPLETED
READY --(predecessor no longer met)--> NOT_READY
ASSIGNED --(unassign)--> READY
ASSIGNED or IN_PROGRESS --(block)--> BLOCKED --(unblock)--> the status before the block
                                                                                         
any non-terminal status --(cancel, reason required)--> CANCELLED
COMPLETED --(approve, manager)--> still COMPLETED, completion_approved = true
```

Transitions, with the service method that performs them:

| From | To | Action (method) | Who |
|---|---|---|---|
| `NOT_READY` | `READY` | automatic: `DependencyService::evaluateAll()` | system |
| `READY` | `NOT_READY` | automatic, when a predecessor is no longer satisfied | system |
| `READY` | `ASSIGNED` | `OperationService::assign()` (approved proposal, manual assignment, FORCE of executor) | manager |
| `ASSIGNED` | `ASSIGNED` | reassignment to another executor (same method) | manager |
| `ASSIGNED` | `READY` | `unassign()` | manager |
| `ASSIGNED` | `IN_PROGRESS` | `start()` | executor (own task) |
| `IN_PROGRESS` | `IN_PROGRESS` | `progress()`, percent 0 to 100 | executor (own task) |
| `IN_PROGRESS` | `COMPLETED` | `complete()`; sets progress to 100 and clears `completion_approved` | executor (own task) |
| `ASSIGNED` or `IN_PROGRESS` | `BLOCKED` | `block()`; remembers `status_before_block` | executor (own task) |
| `BLOCKED` | previous status | `unblock()` (falls back to `ASSIGNED`) | manager |
| `COMPLETED` | `COMPLETED` | `approve()`; sets `completion_approved` (once) | manager |
| any non-terminal | `CANCELLED` | `cancel()`; reason required | manager |

Each action writes an `operation_report` row; each status change writes an audit event (`STATUS_CHANGE`). Completion, blocking and cancellation
notify the managers or the executive user.

Dependency rules (`DependencyService::satisfied`):

| Dependency type | Satisfied when the predecessor is |
|---|---|
| `FINISH_TO_START` | `COMPLETED`, and also approved when `require_completion_approval` is on |
| `START_TO_START` | `IN_PROGRESS` or `COMPLETED` |

A predecessor that is not mandatory never blocks. A `CANCELLED` predecessor never satisfies a dependency, so the manager
has to decide what happens. An operation becomes `READY` only when every mandatory predecessor is satisfied.

---

## 4. Scheduling engine

The engine plans all unfinished work of all active projects together, on shared resources. Code: `app/Scheduling/`. The
orchestration is in `app/Services/PlanService.php`.

### 4.1 Inputs and clock

- `ProjectDataService::load()` returns an immutable `ProjectData` snapshot: settings, projects, operations (by id), resources,
  users, items, predecessors (by successor id), successors, FORCEs, the reference plan (baseline) and `now`.
- Time is a continuous clock in hours from `now` (milliseconds of precision). There is no working calendar (no shifts or
  holidays). `PlanService::hoursFrom()` converts instants to hours.
- Terminal operations (`COMPLETED`, `CANCELLED`) are skipped.
- An `IN_PROGRESS` operation is a running task: it occupies one lane of its resource from t = 0 for its remaining hours,
  `totalHours() * (1 - progress / 100)`.
- A pending operation lasts `totalHours()`: preparation plus transport plus setup plus direct time.
- A resource has `capacity` parallel lanes.

### 4.2 How a plan is computed

- `PlanService::plan($data)` builds the `Task` list (`tasks()`), the capacities, and the `Pins` (`pins()`), then calls
  `PlanOptimizer::optimize()`. The result is wrapped in `PlanView`: slots, makespan, tail hours, the rule chosen per project,
  conflicts, honoured FORCEs and escalated FORCEs.
- `Task` carries the project rank (the project's priority, or a preview override), the resource id, the hours, the running
  flag and `Dep` entries. Only mandatory predecessors become `Dep`s.
- `PlanService::planNaive()` runs the single-pass baseline under the same FORCEs. `SchedulingService` uses it for the
  "naive" comparison shown next to the optimised figures.
- `PlanService::planFresh()` ignores the reference plan (no stability preference). It is used by re-optimisation.

Requests that compute a plan include the project list and overview, the operation list and detail (projected times), the
manager and user dashboards, the FORCE board, previews, assignment runs and proposal views, and the report export. Nothing is
cached.

### 4.3 SchedulePlanner (`app/Scheduling/SchedulePlanner.php`)

- Serial schedule generation: operations whose predecessors are placed are placed in priority order, each at the earliest
  time that fits on any lane. Idle gaps are reused (`earliestFit`).
- Finish-to-start: a successor starts no earlier than its predecessor ends. Start-to-start: no earlier than the predecessor
  starts.
- `plan()` is the single-pass heuristic: project rank, then longest remaining chain (tail), then id. It is the naive baseline.
- `placeAt()` places an operation at an exact start (used for FORCEs). `placeRunning()` puts running work at t = 0.
- `GraphUtil::tailHours()` returns, for each operation, the longest chain of remaining work through its successors, including
  its own duration. `GraphUtil::findCycle()` returns a dependency cycle as a path (used by the importer).

### 4.4 PlanOptimizer (`app/Scheduling/PlanOptimizer.php`)

Constraint hierarchy (from the class documentation):

1. Absolute, never violated: resource capacity, mandatory precedence, running work stays where it is, nothing starts before
   "now".
2. Manager FORCE: a forced operation starts exactly at its forced time. A FORCE that contradicts an absolute constraint is
   not applied; it is reported as a `Conflict`, and the operation becomes "not before that time".
3. Overridable: strict project priority. A FORCE may need the predecessors of the forced operation to run ahead of
   higher-priority work. That happens only when the plain priority order misses the forced start (escalation).
4. Soft: the objective below.

Construction order: running work at 0, then FORCEd operations at their exact starts (after `ForceCheck`), then, when needed,
the predecessors of a late FORCE (earliest latest-start first), then every project in priority order. Placed work is frozen,
so a lower-priority project never delays a higher-priority one.

Escalation loop: the optimiser places the work, collects the FORCEs that are still late, escalates their predecessor chains
and repeats. If a FORCE is still late with everything escalated, the younger FORCE that stands in the way is given up, or the
youngest late one. A given-up FORCE is reported with kind `NOT_FOUND`.

Candidate rules per project (constants `RULE_*`):

| Rule | Order of the project's pending operations |
|---|---|
| `CRITICAL_PATH` | tail hours descending, then id. Always a candidate. |
| `LONGEST_FIRST` | own hours descending, then tail, then id |
| `SHORTEST_FIRST` | own hours ascending, then tail, then id |
| `MOST_SUCCESSORS` | number of descendants descending, then tail, then id |
| `STABLE` | operations of the reference plan first, by reference start, then critical path. Only when a reference exists. |
| `RANDOMIZED` | key = tail * (1 + 0.6 * U), U from `JavaRandom` seeded with `31 * r + 7 * rank + 1`, r the trial index. Descending key, then id. Trials: at most 48, and fewer for large projects (`1 500 000 / work`). |

The naive baseline (`PlanOptimizer::naive`) uses only `CRITICAL_PATH` for each project, in priority order.

Trial and objective. Each candidate places the project's pending work with its rule, then completes every lower-priority
project with `CRITICAL_PATH` (look-ahead). Candidates are compared lexicographically; smaller is better:

1. the finish time of this project, then of each lower-priority project in priority order;
2. the number of committed operations moved away from the reference plan;
3. the number of operations moved (moves below 1/60 h do not count);
4. the total shift from the reference, in hours;
5. wasted lane time (idle time up to the last task of each lane);
6. the sum of all end times.

The optimiser is a heuristic. It does not prove optimality. Seeds are fixed, so the same input gives the same plan (the test
`PlanOptimizerTest::test_result_is_reproducible` checks this).

Pins (`app/Scheduling/Pins.php`): `forcedStart` maps operation id to hours from now, oldest FORCE first (when two FORCEs
contradict, the younger is given up). `reference` maps operation id to `Ref(start, committed)`, where `committed` means the
operation is ASSIGNED.

### 4.5 ForceCheck (`app/Scheduling/ForceCheck.php`)

Exact admission checks. A FORCE rejected here is impossible for every schedule, not only for the heuristic:

1. Start in the past: nothing starts before "now" (`START_IN_PAST`).
2. Precedence: even with unlimited resources the predecessor chain cannot finish (FS) or start (SS) by the forced start.
   Running work and admitted FORCEs count as fixed. A FORCE that makes an admitted FORCE impossible is also rejected
   (`PRECEDENCE`, `BLOCKS_FORCE`).
3. Capacity: at some moment the forced and running operations on one resource need more lanes than the resource has
   (`CAPACITY`).

FORCEs are admitted in seniority order (oldest first). Running work always wins. A rejected FORCE constrains nothing and is
only reported.

Conflict kinds (`app/Scheduling/Conflict.php`):

| Kind | Meaning | `proven` |
|---|---|---|
| `START_IN_PAST` | forced start is before now | true |
| `PRECEDENCE` | predecessors cannot be ready in time | true |
| `BLOCKS_FORCE` | the new FORCE makes an older FORCE impossible | true |
| `CAPACITY` | the resource cannot hold the forced and running work at once | true |
| `NOT_FOUND` | the heuristic found no schedule with this start (one may still exist) | false |

### 4.6 FORCE and reference plan flow (`ForceService`)

- Board, `GET /api/scheduling/forces`: each FORCE with its state, `HONOURED` (in force and met), `NOT_HONOURED` (impossible
  now; the finding is attached), or `DONE` (started or terminal), plus the planned start and end. Also the escalation count
  and the time of the last baseline save.
- Preview, `POST .../preview`: `impact()` stores nothing. It returns `feasible`, conflicts, warnings, moved operations (at most
  100), shifts per project, and waste before and after.
- Apply, `PUT /api/scheduling/forces/{id}`: rejected with 409 `FORCE rejected: <reason>` when not feasible. Otherwise the
  `schedule_force` row is written. An already ASSIGNED operation gets its new executor (`assign()`) or its new planned times
  (`reschedule()`). Audit `FORCE_SET`, then the reference plan is saved.
- Remove, `DELETE`: deletes the row, audit `FORCE_REMOVED`, saves the reference plan. 404 when there is no FORCE.
- Re-optimise, `POST /api/scheduling/reoptimize`: `planFresh()`, saves the result as the reference plan, audit
  `PLAN_REOPTIMIZED`.
- The reference plan (`plan_baseline`) is saved only by these three operations. Import and project priority changes do not
  save it (verified in `ForceService::saveBaseline()` call sites).
- For an operation assigned after the baseline was saved, the reference start is the planned start given at assignment
  (`PlanService::pins()`).

### 4.7 JavaRandom (`app/Scheduling/JavaRandom.php`)

A 48-bit linear congruential generator identical to `java.util.Random` (multiplier `0x5DEECE66D`, addend `0xB`, modulus
2^48). It exists so the randomised rule produces the same plan as the Spring backend for the same input. The check is
`GraphUtilTest::test_java_random_matches_the_java_sequence`.

### 4.8 Ranking and previews

- `SchedulingService::ranks()` requires a ranking that lists every active project exactly once.
- `ProjectService::setPriorities()` stores ranks 1..n and audits `PROJECT_PRIORITIES_CHANGED`.
- `SchedulingService::previewRanking()` and `impact()` compute the current, proposed and naive metrics for a ranking without
  storing it. The metrics are finish hours per project, tardiness against the due date, makespan, busy and wasted hours and
  utilisation per resource.

---

## 5. Services

All services live in `app/Services/` unless noted. Controllers call one service method and return its array.

| Class | Responsibility | Main public methods |
|---|---|---|
| `AdminService` | Owner settings, system status, controlled reset, assignment mode | `view()`, `update()`, `setAssignmentMode()`, `setSystemStatus()`, `reset()`, `saveChanged()`, `toView()` |
| `AssignmentService` | Assignment runs: suggest (algorithm or LLM), history, approve and reject proposals, manual assignment | `suggest()`, `latest()`, `history()`, `approve()`, `reject()`, `manualAssign()`, `buildContext()` |
| `AuditService` | Writes append-only audit events, with reasons in the system language | `record()` |
| `DashboardService` | Manager, owner and executive dashboards; performance report; audit and notification views | `manager()`, `owner()`, `user()`, `performanceAll()`, `statusCounts()`, `auditView()`, `notificationView()`, `iso()` |
| `DependencyService` | READY and NOT_READY engine (section 3.4) | `satisfied()`, `unsatisfied()`, `evaluateAll()` |
| `ForceService` | FORCE board, preview, apply, remove, re-optimise, reference plan save | `board()`, `stateOf()`, `preview()`, `apply()`, `remove()`, `reoptimize()`, `parseStart()`, `describe()` |
| `LanguageService` | Request language (Accept-Language) and system language (owner setting); translation at the output boundary | `systemIsPersian()`, `requestIsPersian()`, `forRequest()`, `forStorage()` |
| `NotificationService` | Stores every notification in the inbox and calls the delivery seam (section 15) | `notifyUser()`, `notifyManagers()` |
| `OperationService` | Operation lifecycle commands, each validated, audited and reported | `start()`, `progress()`, `complete()`, `block()`, `comment()`, `unblock()`, `cancel()`, `approve()`, `unassign()`, `assign()`, `reschedule()` |
| `OperationViewService` | Read models: operation list, detail with history, own tasks | `all()`, `forUser()`, `detail()`, `toView()` |
| `PlanService` | Computes the schedule (plan, fresh, naive) and the pins | `plan()`, `planFresh()`, `planNaive()`, `pins()`, `hoursFrom()` |
| `PlanView` | Value object: a computed schedule with instants | `start()`, `end()`, `startHours()`, `projectEnd()`, `at()` |
| `ProjectData` | Immutable snapshot used by the engine and dashboards | `predsOf()`, `rankOf()`, `forceOf()`, `withForces()`, `projectName()`, `userName()`, `resourceName()` |
| `ProjectDataService` | Loads the snapshot (one read of every table) | `load()` |
| `ProjectService` | Project list, priorities, due dates, archiving; due-date parsing | `list()`, `setPriorities()`, `update()`, `renumber()`, `parseDue()` |
| `SchedulingService` | Schedule metrics, previews and project summaries | `overview()`, `previewRanking()`, `impact()`, `metrics()`, `summaries()`, `activeIds()`, `ranks()` |
| `UserService` | Accounts and roles (owner console) | `list()`, `create()`, `update()`, `resetPassword()`, `changeOwnPassword()`, `view()` |

Import and export classes are documented in section 9: `ImportService` (`process()`, `processSample()`), `ImportValidator`
(`validate()`), `ImportApplier` (`apply()`), `ExcelService` (`template()`, `report()`), `SheetWriter` (`sheet()`),
`SheetReader` (`find()`, `has()`, `rows()`, `str()`, `number()`), `MasterParser` and `SimpleParser` (`parse()`).

Assignment strategies (`app/Assignment/`):

- `AlgorithmStrategy::suggest()`: deterministic dispatch. READY operations are ranked by project priority, then the plan's
  order, then critical-path weight, then own duration, then id. Each operation takes the eligible user with the lowest
  effective load; the resource's responsible user gets a soft bonus (`aiso.assignment.responsible_bonus_hours`). Ties go to
  fewer tasks, then to id.
- `LlmStrategy::suggest()`: asks the model (section 10.5) and checks every pick with the same `AssignmentState`.
- `AssignmentState`: the hard constraints, shared by both strategies. The user must be an active executive user; a FORCE or
  the OPC sheet can fix the executor; the resource must be active and have a free slot; the user must stay within
  `max_active_tasks_per_user`, unless a FORCE names that user.

---

## 6. HTTP API reference

### 6.1 Conventions

- Base path `/api`. Bodies are JSON, or multipart for the import upload. Responses are JSON, except the two `.xlsx`
  downloads and the empty bodies noted below.
- Session: cookie `aiso_session`, `HttpOnly`, `SameSite=Lax`, `Secure` when `SESSION_SECURE_COOKIE` is true.
- CSRF: every non-GET request sends `X-XSRF-TOKEN` with the value of the `XSRF-TOKEN` cookie.
- Language: `Accept-Language: fa` or `en`. It decides the language of request-time texts (errors, import findings, Excel
  downloads). Without it, the system language (owner setting) is used.
- Timestamps are UTC ISO 8601 with milliseconds, for example `2026-10-08T10:30:00.000Z`.
- JSON keys are camelCase; the same names are the interfaces in `resources/js/lib/types.ts`.
- Commands (`start`, `progress`, `unblock`, and similar) return `200` with an empty body. The frontend `parse()` accepts it.
- Any `/api` path that is not registered returns `404 {"message":"Not found"}`, for every method. Registered paths with a wrong
  method also return 404, because the catch-all matches every method (no 405 is produced).
- Errors use the shape in section 6.4.

### 6.2 Role groups

| Access label | Users | Middleware |
|---|---|---|
| `public` | anyone | none (`/api/auth/login`) |
| `any` | every signed-in user | `auth`, `suspension` |
| `EXEC` | `USER_1`, `USER_2`, `USER_3` | `role:USER_1,USER_2,USER_3` |
| `OWNER` | `OWNER` | `role:OWNER` |
| `MGMT` | `OWNER`, `MANAGER` | `role:OWNER,MANAGER` |

A manager is not an owner: owner-only routes (for example `POST /api/users`) answer 403 to a manager. `any` routes check
ownership of the object inside the controller or service where it matters (see the last column of section 6.3 and section 10.3).

### 6.3 Routes

All routes are in `routes/web.php`. Input fields are the validation rules of the controller, plus the checks the service
makes; "required" means the request is refused without the field. Responses named "view" are the arrays described in
section 7.6.

| Method | Path | Access | Controller::method | Input | Success response and notable errors |
|---|---|---|---|---|---|
| POST | `/api/auth/login` | public | `AuthController::login` | `userId`, `password` (required) | `{expiresInSeconds, user}`. 401 `Invalid user id or password` for unknown user, wrong password or inactive user. Regenerates the session. |
| GET | `/api/auth/me` | any | `AuthController::me` | none | user view |
| POST | `/api/auth/change-password` | any | `AuthController::changePassword` | `currentPassword`, `newPassword` (required; at least 8 characters) | empty. 400 when the current password is wrong or the new one is short. |
| POST | `/api/auth/logout` | any | `AuthController::logout` | none | empty. Invalidates the session. |
| GET | `/api/notifications` | any | `DashboardController::myNotifications` | none | notification views, newest 50 of the caller |
| POST | `/api/notifications/read-all` | any | `DashboardController::readAll` | none | empty |
| POST | `/api/notifications/{id}/read` | any | `DashboardController::read` | `id` (integer) | empty. Ids of other users are ignored silently. |
| GET | `/api/operations/{id}` | any; executors only own | `OperationController::detail` | none | `{operation, reports, successors}`. 403 for an executor who is not assigned; 404 unknown id. |
| POST | `/api/operations/{id}/comment` | any; executors only own | `OperationController::comment` | `reason` (required, not blank) | empty. 400 blank; 403 for a foreign task. |
| GET | `/api/my/tasks` | EXEC | `OperationController::myTasks` | none | operation views of the caller. Registered; the screens do not call it (My tasks uses `/api/dashboard/user`). |
| POST | `/api/operations/{id}/start` | EXEC, own | `OperationController::start` | none | empty. 409 unless `ASSIGNED`. |
| POST | `/api/operations/{id}/progress` | EXEC, own | `OperationController::progress` | `percent` (integer 0 to 100, required), `note` | empty. 409 unless `IN_PROGRESS`. |
| POST | `/api/operations/{id}/complete` | EXEC, own | `OperationController::complete` | `note` | empty. 409 unless `IN_PROGRESS`. Notifies managers. |
| POST | `/api/operations/{id}/block` | EXEC, own | `OperationController::block` | `reason` (required) | empty. 409 unless `ASSIGNED` or `IN_PROGRESS`. |
| GET | `/api/dashboard/user` | EXEC | `DashboardController::user` | none | `UserDashboard` of the caller |
| POST | `/api/users` | OWNER | `UserController::create` | `id` (2 to 64 characters: letters, digits, `_`, `.`, `-`), `fullName`, `role` (required; `OWNER` refused with 400), `messengerId`, `contactInfo`, `password` (required; at least 8) | user view. 409 when the id exists. |
| PATCH | `/api/users/{id}` | OWNER | `UserController::update` | `fullName`, `role`, `active`, `messengerId`, `contactInfo` (all optional) | user view. 403 for owner rules; 409 when deactivating a user with open tasks. |
| POST | `/api/users/{id}/reset-password` | OWNER | `UserController::resetPassword` | `newPassword` (required; at least 8) | empty. Sets `must_change_password`. |
| PATCH | `/api/settings` | OWNER | `AdminController::update` | `projectName`, `messengerPlatform`, `maxActiveTasksPerUser` (1 to 50), `requireCompletionApproval`, `language` (`fa` or `en`); all optional | settings view |
| PUT | `/api/admin/system-status` | OWNER | `AdminController::systemStatus` | `status` (`ACTIVE` or `SUSPENDED`, required), `reason` (the service requires it) | settings view |
| POST | `/api/admin/reset` | OWNER | `AdminController::reset` | `confirm` must be `RESET`, `reason` (required) | empty. Keeps users, settings, audit and import log (section 10.6). |
| POST | `/api/admin/demo-data` | OWNER | `AdminController::demoData` | none | import result of the bundled sample, applied |
| GET | `/api/dashboard/owner` | OWNER | `DashboardController::owner` | none | `OwnerDashboard` |
| GET | `/api/users` | MGMT | `UserController::list` | none | user views, owners first, then managers, then executives |
| GET | `/api/projects` | MGMT | `ProjectController::list` | none | project summaries (`ProjectSummary[]`) |
| PUT | `/api/projects/priorities` | MGMT | `ProjectController::priorities` | `ranking` (project ids, required; every active project once, first = highest priority), `dueDates` (optional map of id to date; empty clears) | project summaries. 400 when the ranking is incomplete or unknown. |
| PATCH | `/api/projects/{id}` | MGMT | `ProjectController::update` | `name`, `dueDate`, `clearDueDate`, `status` (`ACTIVE` or `ARCHIVED`); all optional | project summaries. 409 when archiving a project with unfinished operations; 400 for a bad date; 404 unknown. |
| POST | `/api/scheduling/preview` | MGMT | `ProjectController::preview` | `ranking` (required) | `Impact`. Nothing is stored. |
| GET | `/api/scheduling/overview` | MGMT | `ProjectController::overview` | none | `{optimized, naive, projects}` (`SchedulingOverview`) |
| GET | `/api/scheduling/forces` | MGMT | `ForceController::list` | none | `ForceBoard` |
| POST | `/api/scheduling/forces/{operationId}/preview` | MGMT | `ForceController::preview` | `start`, `userId`, `reason` (optional) | `ForceImpact`. Nothing is stored. |
| PUT | `/api/scheduling/forces/{operationId}` | MGMT | `ForceController::apply` | `start`, `userId`, `reason`; at least `start` or `userId` (400 otherwise) | `ForceBoard`. 409 `FORCE rejected: ...` when not feasible. |
| DELETE | `/api/scheduling/forces/{operationId}` | MGMT | `ForceController::remove` | none | `ForceBoard`. 404 when there is no FORCE. |
| POST | `/api/scheduling/reoptimize` | MGMT | `ForceController::reoptimize` | none | `ForceBoard`. Saves the new reference plan. |
| GET | `/api/operations` | MGMT | `OperationController::list` | query: `status`, `resourceId`, `projectId`, `userId`, `q` (text search in id and name) | operation views (`Operation[]`) |
| POST | `/api/operations/{id}/unblock` | MGMT | `OperationController::unblock` | `note` | empty. 409 unless `BLOCKED`. |
| POST | `/api/operations/{id}/cancel` | MGMT | `OperationController::cancel` | `reason` (required) | empty. 409 when already terminal. |
| POST | `/api/operations/{id}/approve` | MGMT | `OperationController::approve` | `note` | empty. 409 unless `COMPLETED`, or already approved. |
| POST | `/api/operations/{id}/unassign` | MGMT | `OperationController::unassign` | none | empty. 409 unless `ASSIGNED`. |
| POST | `/api/operations/{id}/assign` | MGMT | `AssignmentController::manual` | `userId` (required), `note` | empty. 409 when a FORCE fixes another executor; 400 for an unknown, inactive or non-executive user. |
| POST | `/api/assignments/suggest` | MGMT | `AssignmentController::suggest` | `mode` (`ALGORITHM` or `LLM`; default: the setting) | run view with proposals |
| GET | `/api/assignments/latest` | MGMT | `AssignmentController::latest` | none | latest run view, or `null` when no run exists |
| GET | `/api/assignments/runs` | MGMT | `AssignmentController::runs` | query `limit` (default 10, at most 50) | run views |
| POST | `/api/assignments/approve` | MGMT | `AssignmentController::approve` | `proposalIds` (integers, required) | proposal views. Each is re-checked; a failing one is rejected with a note. |
| POST | `/api/assignments/reject` | MGMT | `AssignmentController::reject` | `proposalIds` (integers, required), `note` | proposal views |
| POST | `/api/import` | MGMT | `ImportController::upload` | multipart: `file` (required, not empty), `apply` (boolean), `projectId`, `newProjectName`, `newProjectId`, `dueDate`, `ranking` (comma-separated ids; `NEW` for the new project) | import result (section 9). Nothing is stored unless `apply` is true and the file is clean. |
| GET | `/api/import/template` | MGMT | `ImportController::template` | query `lang` (`fa` or `en`) | `.xlsx` attachment, the master template |
| GET | `/api/import/history` | MGMT | `ImportController::history` | none | the 50 newest import log rows |
| GET | `/api/settings` | MGMT | `AdminController::settings` | none | settings view |
| PUT | `/api/settings/assignment-mode` | MGMT | `AdminController::assignmentMode` | `mode` (required) | settings view. A manager may switch the mode. |
| GET | `/api/dashboard/manager` | MGMT | `DashboardController::manager` | none | `ManagerDashboard` |
| GET | `/api/reports/users` | MGMT | `DashboardController::userPerformance` | none | `UserPerformance[]` |
| GET | `/api/reports/export` | MGMT | `DashboardController::export` | query `lang` | `.xlsx` attachment, the status report |
| GET | `/api/audit` | MGMT | `DashboardController::audit` | `limit` (1 to 500, default 100); `entityType` with `entityId`, or `actorId` | `AuditEvent[]`, newest first |
| GET | `/api/notifications/failed` | MGMT | `DashboardController::failed` | none | the 100 newest notifications with `FAILED` delivery |
| ANY | `/api/{any}` | all | closure in `routes/web.php` | none | 404 `{"message":"Not found"}` |
| GET | `/login` | public | `SpaController::index` | none | application shell |
| GET | `/{any?}` | public | `SpaController::index` | none | application shell (React Router renders the screen) |

Totals: 55 endpoints are listed here, plus the 404 catch-all. The messaging connector adds three routes, `GET /api/me/bale`,
`POST /api/me/bale-link-code` and `DELETE /api/me/bale` (section 15); they are not listed in the table.

### 6.4 Errors

Every error is `{"message": "<text>"}` with an HTTP status. The text is in the language of the request (section 9.8) except
for the messages named below. Source: `bootstrap/app.php` renders the exceptions for paths under `api/`.

| Status | Cause | Example message |
|---|---|---|
| 400 | Laravel validation failed. Several fields are joined by `; `. Messages are Laravel's English text and are not translated. | `percent: The percent field must be at least 0.` |
| 400 | Business rule (`ApiException::badRequest`) | `A reason is required` |
| 401 | No session (`Unauthenticated.`), or wrong login (`Invalid user id or password`) | `Unauthenticated.` |
| 403 | Role not allowed (`EnsureRole`), or an executor touches a task that is not theirs, or an owner-only rule | `Access denied`, `This operation is not assigned to you` |
| 404 | Unknown operation, project, user or FORCE; unknown `/api` path | `Operation OP-9 not found`, `Not found` |
| 409 | Impossible state change, duplicate id, open tasks, FORCE rejected, forced executor | `Operation OP-2 is IN_PROGRESS, expected ASSIGNED`, `FORCE rejected: ...` |
| 419 | Missing or wrong CSRF token (Laravel `TokenMismatchException`, converted to an HTTP exception) | `Request failed` |
| 423 | System suspended and the caller is not the owner (writes only) | `The system is suspended by the owner.` |
| 500 | Any other exception. The details are only in `storage/logs`. | `Unexpected server error` |
| 502 | LLM assignment failed and `AISO_LLM_FALLBACK` is false | `LLM assignment failed: ...` |

---

## 7. Frontend

### 7.1 Routes (`resources/js/main.tsx`)

`BrowserRouter` with the providers wraps all routes. `/login` is outside the shell; every other route renders inside
`AppShell` (navigation and header).

| Path | Page component (file under `resources/js/pages/`) | Roles that see the link (`Shell.tsx` NAV) |
|---|---|---|
| `/login` | `login/page.tsx` (`LoginPage`) | public |
| `/` | `home.tsx` (`HomePage`): sends each user to `homeFor(role)` | all |
| `/owner` | `owner/page.tsx` (`OwnerPage`) | OWNER |
| `/owner/users` | `owner/users/page.tsx` (`UsersPage`) | OWNER |
| `/manager` | `manager/page.tsx` (`ManagerOverview`) | OWNER, MANAGER |
| `/manager/projects` | `manager/projects/page.tsx` (`ProjectsPage`) | OWNER, MANAGER |
| `/manager/assignment` | `manager/assignment/page.tsx` (`AssignmentPage`) | OWNER, MANAGER |
| `/manager/operations` | `manager/operations/page.tsx` (`OperationsPage`) | OWNER, MANAGER |
| `/manager/plan` | `manager/plan/page.tsx` (`PlanPage`) | OWNER, MANAGER |
| `/manager/reports` | `manager/reports/page.tsx` (`ReportsPage`) | OWNER, MANAGER |
| `/import` | `import/page.tsx` (`ImportPage`) | OWNER, MANAGER |
| `/audit` | `audit/page.tsx` (`AuditPage`) | OWNER, MANAGER |
| `/my` | `my/page.tsx` (`MyTasksPage`) | USER_1, USER_2, USER_3 |
| `*` | `HomePage` | all |

The shell redirects a user away from an area the role may not see (`Shell.tsx`, `homeFor`). This is a convenience; the API
enforces the same rules (section 6.2).

### 7.2 API client (`resources/js/lib/api.ts`)

- `api<T>(path, { method, body })` calls `fetch("/api" + path)` with `credentials: "same-origin"`. The body is JSON.
- Headers: `Accept: application/json`; `Accept-Language` from the `aiso_lang` cookie (`en` if set to `en`, otherwise `fa`);
  `Content-Type` only when there is a body; `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie on every non-GET request.
- `upload<T>(path, FormData)` for the import. It does not set `Content-Type`, so the browser adds the multipart boundary.
- `parse()`: an empty body becomes `null`; a JSON body is parsed; a non-2xx status throws `ApiError(message, status)`, where
  `message` is the body's `message`. A 401 outside `/login` navigates to `/login`.
- `useFetch<T>(path, refreshMs)`: loads once and optionally polls. `path = null` pauses it. It returns `data`, `error`,
  `loading` and `reload`.
- Downloads are plain links with `download`: `/api/import/template?lang=` and `/api/reports/export?lang=`.

### 7.3 Providers and shell

- `components/Providers.tsx` provides three contexts. `useT()` gives `t(key, params)`, `num`, `date` (Solar Hijri in Persian
  through `Intl`), `hours` and `percent`. `useSession()` gives `user`, `setUser` and `signOut()`. `useToast()` gives
  `(text, kind)` for transient messages.
- Language is chosen with the `aiso_lang` cookie (one year). Switching sets `document.documentElement.lang` and `dir`. The
  initial value is read in `main.tsx`; Persian is the default.
- Theme: `localStorage` key `aiso_theme` (`light` or `dark`), applied as `data-theme` on `<html>`.
- `components/Shell.tsx` loads `/api/auth/me`, builds the navigation from the `NAV` table (each item lists its roles), shows
  the notification inbox (`/api/notifications`, `read-all`), the password change dialog (`/api/auth/change-password`), and
  sign-out (`signOut()` in `Providers.tsx`, which calls `/api/auth/logout`).
- Login (`pages/login/page.tsx`) posts to `/api/auth/login`, stores the returned user, and navigates to `/`.

### 7.4 Components (`resources/js/components/`)

| File | Purpose |
|---|---|
| `Providers.tsx` | i18n, session and toast contexts |
| `Shell.tsx` | Application shell, navigation, inbox, password change |
| `ui.tsx` | Shared controls (`Button` and others); each status has a shape as well as a colour |
| `OperationTable.tsx` | Table of operations used by the management screens |
| `OperationDetailModal.tsx` | Operation detail with the actions the viewer may take (`manage` mode for managers, `execute` mode for executors) |
| `ForcePanel.tsx` | FORCE editing with preview: start (Tehran date and time) and executor |
| `ImpactPanel.tsx` | Effect of a ranking or of an imported project on finish times and wasted time |
| `RankingEditor.tsx` | Priority ordering of projects (the ranking sent to `/api/projects/priorities` and previews) |
| `Gantt.tsx` | Projected schedule per resource; parallel lanes for capacity above 1 |
| `StatusBar.tsx` | Stacked bar of operations per status; the legend is the data table |
| `DateField.tsx` | Date input: Solar Hijri typing in Persian (Persian digits accepted), Gregorian in English |

`lib/jalali.ts` holds the Solar Hijri arithmetic for date input; display uses `Intl` with the `fa-IR-u-ca-persian` calendar.

### 7.5 Internationalisation (`resources/js/lib/i18n.ts`)

- The file exports `en` and `fa`, both `Record<string, string>`. Each holds 453 keys, and the key sets are identical (checked
  when this guide was written).
- `t(key, params)` looks the key up in the active dictionary, then in `en`, then returns the key itself. `{name}` placeholders
  are replaced from `params`.
- Key prefixes in use include `nav.`, `common.`, `unit.`, `role.`, `mode.`, `op.`, `owner.`.
- Business data (operation names, reasons typed by users) is not translated. System texts from the backend are translated by
  the server (section 9.8), so the frontend shows them as received.

### 7.6 Types contract (`resources/js/lib/types.ts`)

`types.ts` declares 36 interfaces that mirror the JSON the API returns. The PHP side builds these as arrays:

| Interface(s) | PHP source |
|---|---|
| `User` | `UserService::view()` |
| `Operation`, `OperationDetail`, `OpReport`, `PredView` | `OperationViewService::toView()`, `detail()` |
| `ManagerDashboard`, `OwnerDashboard`, `UserDashboard`, `UserLoad`, `ResourceLoad`, `Progress`, `ImportSummary`, `AuditEvent`, `Notification`, `UserPerformance` | `DashboardService` |
| `Proposal`, `Run`, `Skipped` | `AssignmentService::view()`, `runView()` |
| `Settings` | `AdminService::toView()` |
| `ImportResult`, `ImportIssue`, `ProjectRef`, `ImportLog` | `ImportService::finish()`, `ImportController::history()` |
| `ProjectSummary`, `ProjectPlan`, `PlanMetrics`, `ResourceMetric`, `ProjectDelta`, `Impact`, `SchedulingOverview` | `SchedulingService` |
| `ForceBoard`, `ForceView`, `ForceImpact`, `Finding`, `MovedOp`, `ProjectShift`, `ForceState` | `ForceService` |

Procedure when a field changes:

1. Change the PHP array key in the service (or the controller that builds it).
2. Change the interface in `types.ts` and every component that reads it.
3. Run `npx tsc --noEmit`. It checks the frontend only; it cannot see PHP. Confirm the JSON with a request (or a feature test)
   against the real endpoint.

A check made for this guide: every field name of the 36 interfaces appears as a key in the PHP sources. This is a name-level
check across the code base, not a per-endpoint comparison.

Known mismatch: `llmModel` is typed `string` in `Settings`, `ManagerDashboard` and `OwnerDashboard`, but the PHP side sends
`null` when `AISO_LLM_MODEL` is empty (`AdminService::toView()`, `DashboardService`). The screens render it inside a monospace
element, so `null` shows as nothing. Fix either side (the type should be `string | null`).

### 7.7 Adding a page

1. Create `resources/js/pages/<area>/<name>/page.tsx` with a default-exported component (every existing page does this).
2. Add the route in `resources/js/main.tsx`, inside the `AppLayout` route.
3. Add a navigation item in the `NAV` table in `components/Shell.tsx` with its roles.
4. Add every label to both `en` and `fa` in `lib/i18n.ts`, with the same key.
5. Add the endpoint: a controller method, the route in `routes/web.php` inside the correct role group, the service method and
   the view array.
6. Add or extend the interface in `lib/types.ts`.
7. Run `npx tsc --noEmit` and `npm run build`, then reload the page.

### 7.8 Build tooling

- `vite.config.ts`: React and Tailwind plugins; the alias `@` points to `resources/js`; `publicDir: false`; output to
  `public/build`; `manifest.json` is written there for the `@vite` directive.
- `npm run build` runs `tsc --noEmit` and then `vite build`. `npm run typecheck` runs only the type check.
- `npm run dev` starts a Vite server that is not connected to Laravel: there is no `laravel-vite-plugin` and no hot file, so
  `@vite` keeps reading the last build. After a frontend change, run `npm run build` and reload.

---

## 8. Configuration

Values come from the environment (`.env`) through `config/*.php`. Nothing secret is stored in the database; the LLM key is
read from the environment only. The "in .env.example" column shows whether the setup copies the variable.

### 8.1 Laravel and infrastructure

| Variable | Default in config | In `.env.example` | Meaning |
|---|---|---|---|
| `APP_NAME` | `AISO` | yes | Application name |
| `APP_ENV` | `production` | yes | Environment |
| `APP_DEBUG` | `false` | yes | Debug pages. Keep false in production. API errors are JSON either way. |
| `APP_URL` | `http://localhost` | yes (`http://localhost:8080`) | Base URL used when generating URLs |
| `APP_LOCALE` | `en` | yes | Laravel locale |
| `APP_KEY` | empty | yes (empty) | Encryption key. Generated by `key:generate`. Changing it invalidates sessions and encrypted cookies. |
| `APP_PREVIOUS_KEYS` | empty | no | Comma-separated old keys during rotation |
| `DB_CONNECTION` | `sqlite` | yes | `sqlite`, `mysql` or `pgsql` |
| `DB_URL` | empty | no | Full connection URL (overrides the parts) |
| `DB_DATABASE` | `database/database.sqlite` (sqlite), `aiso` (mysql, pgsql) | commented | Database name or SQLite file |
| `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` | `127.0.0.1`, `3306` or `5432`, `root` or `postgres`, empty | commented | Server connection (mysql, pgsql) |
| `DB_FOREIGN_KEYS` | `true` | no | SQLite foreign key enforcement |
| `SESSION_DRIVER` | `database` | yes | Sessions in the `sessions` table |
| `SESSION_LIFETIME` | `480` | yes | Minutes of inactivity before a session ends |
| `SESSION_SECURE_COOKIE` | `true` | yes | Cookie sent over HTTPS only. Set false only for plain HTTP outside localhost. |
| `SESSION_COOKIE` | `aiso_session` | no | Session cookie name |
| `SESSION_DOMAIN`, `SESSION_CONNECTION`, `SESSION_STORE` | empty | no | Advanced session options |
| `CACHE_STORE` | `file` | yes | Cache store. The application code does not use the cache; the messaging connector work does (section 15). |
| `CACHE_PREFIX` | `aiso_cache_` | no | Cache key prefix |
| `LOG_CHANNEL` | `stack` | yes | Logging channel |
| `LOG_STACK` | `single` | no | Channels inside the stack |
| `LOG_LEVEL` | `debug` in config, `info` in `.env.example` | yes | Minimum level |
| `BCRYPT_ROUNDS` | `12` | no | bcrypt cost for password hashes |
| `FILESYSTEM_DISK` | `local` | no | Default disk (no public files are used) |
| `VIEW_COMPILED_PATH` | `storage/framework/views` | no | Compiled Blade views |

Fixed in configuration, not from the environment: SQLite `journal_mode` is `WAL` and `busy_timeout` is 5000 ms
(`config/database.php`).

### 8.2 Application settings (`config/aiso.php`)

| Variable | Default | Config key | Meaning |
|---|---|---|---|
| `AISO_SEED_ENABLED` | `true` | `aiso.seed.enabled` | Seed accounts when the user table is empty (`AisoSeeder`) |
| `AISO_SEED_PASSWORD` | `ChangeMe!123` | `aiso.seed.password` | Password of the five seeded accounts, used when the seeder creates them (first `migrate --seed`). |
| `AISO_LANGUAGE` | `fa` | `aiso.seed.language` | System language on a fresh install (`fa` or `en`); the owner changes it later |
| `AISO_SEED_DEMO_DATA` | `true` | `aiso.seed.demo_data` | Load `resources/samples/simple-sample.xlsx` with the seed |
| `ANTHROPIC_API_KEY` | empty | `aiso.llm.api_key` | Key for the LLM method. Never stored in the database or sent to the browser. |
| `ANTHROPIC_BASE_URL` | `https://api.anthropic.com` | `aiso.llm.base_url` | API base; the client calls `/v1/messages` |
| `AISO_LLM_MODEL` | empty | `aiso.llm.model` | Model id. Required with the key; no default. |
| `AISO_LLM_TIMEOUT` | `120` | `aiso.llm.timeout_seconds` | HTTP timeout of the model call, in seconds |
| `AISO_LLM_MAX_TOKENS` | `16000` | `aiso.llm.max_tokens` | `max_tokens` sent to the model |
| `AISO_LLM_FALLBACK` | `true` | `aiso.llm.fallback_to_algorithm` | On LLM failure, use the algorithm and say so (false: 502) |
| `AISO_RESPONSIBLE_BONUS_HOURS` | `8` | `aiso.assignment.responsible_bonus_hours` | Soft preference for a resource's responsible user, in hours of load |

`config/aiso.php` also has a `bale` section (`BALE_BOT_TOKEN`, `BALE_API_URL`) for the messaging connector in progress
(section 15). It has no effect in the code paths documented here.

### 8.3 Differences between `.env.example` and the configuration

- The example does not list `ANTHROPIC_BASE_URL`, `BCRYPT_ROUNDS`, `SESSION_COOKIE`, `SESSION_DOMAIN`, `CACHE_PREFIX`,
  `DB_URL`, `DB_FOREIGN_KEYS`, `LOG_STACK`, `FILESYSTEM_DISK` or `APP_PREVIOUS_KEYS`. The defaults above apply.
- `LOG_LEVEL` is `info` in the example and `debug` in the configuration.
- `APP_KEY` is empty in the example. `setup` fills it; see section 12 before running setup again.

---

## 9. Import and export pipeline

### 9.1 Flow

```
POST /api/import  (multipart: file, apply, target fields)
 ImportController::upload      file must be present and not empty; ranking split on commas
 ImportService::process
   1. IOFactory::load (PhpSpreadsheet). Not a workbook -> REJECTED.
   2. Detect the layout:
        SimpleParser::matches  sheets "opc" and "source", no "Resources"  -> SimpleParser::parse
        else Resources and OPC present                                      -> MasterParser::parse
        else                                                                -> REJECTED "Unrecognised workbook"
   3. Parsing collects typed rows and errors (sheet, row, column, message) in ParsedData.
   4. resolveTarget: projectId, or newProjectName (+ newProjectId, dueDate). Ambiguous -> error.
   5. ImportValidator::validate, only when parsing found no errors.
   6. Any error -> REJECTED. Nothing is written.
   7. New project, other active projects, no ranking -> PRIORITY_REQUIRED (impact of the default order is returned).
   8. apply = false -> VALID (dry run).
   9. ImportApplier::apply (one transaction) -> APPLIED.
   finish(): one import_log row; the result array (section 6.3).
```

### 9.2 Layouts

Master template (`MasterParser`), sheets:

| Sheet | Required | Columns (English names; Persian names are accepted) |
|---|---|---|
| `Resources` | yes | `Resource_ID`, `Resource_Name` (required), `Resource_Type`, `Responsible_User_ID`, `Capacity` (integer at least 1), `Status` (`ACTIVE` or `INACTIVE`), `Description` |
| `BOM` | no | `Item_ID`, `Item_Name` (required), `Quantity` (not negative), `Unit`, `Supply_Type`, `Related_Operation_ID`, `Description` |
| `OPC` | yes | `Operation_ID`, `Operation_Name`, `Resource_ID` (required), `Item_ID`, `Responsible_User_ID`, `Preparation_Time`, `Transport_Time`, `Setup_Time`, `Direct_Time` (hours, not negative), `Operation_Status` (optional initial status: `NOT_READY`, `READY`, `COMPLETED` or `CANCELLED`), `Description` |
| `Predecessors` | no | `Operation_ID`, `Predecessor_Operation_ID`, `Dependency_Type` (`FINISH_TO_START` or `START_TO_START`), `Start_Condition`, `Is_Mandatory` (TRUE or FALSE), `Description` |
| `Users` | no | `User_ID`, `Full_Name`, `Role` (`OWNER`, `MANAGER`, `USER_1`, `USER_2`, `USER_3`), `Messenger_ID`, `Active_Status` (TRUE/FALSE or ACTIVE/INACTIVE), `Contact_Info` |
| `Settings` | no | exactly one data row, with the columns of the template: `Project_ID`, `Project_Name`, `Owner_ID`, `Manager_ID`, `Messenger_Platform`, `System_Status`, `Data_Version`, `Configuration_Version` |

Sheets that are missing and not required produce a warning ("existing ... data is left unchanged"). The template written by
`ExcelService::template()` has these sheets (section 9.7).

Simple layout (`SimpleParser`, the format of the sample): sheet `opc` with the Persian headers row number, name, specification,
code, quantity, step order, step, predecessor rows, resource, direct hours; sheet `source` with resource name and
simultaneous resources. Predecessors refer to the row numbers of the same sheet (`0` or empty means none; `3,8` means rows 3 and
8). Direct hours are the total duration of the operation, not multiplied by the quantity. The sample holds 16 operations, 15
predecessor links and 6 resources.

### 9.3 Target project and ranking

- `projectId` names an existing ACTIVE project. Otherwise `newProjectName` creates one; its id defaults to `PRJ-<count + 1>`
  and must match `[A-Za-z0-9_.-]{1,64}` and be unused.
- With several active projects and no target, the import is an error asking for the target.
- `ranking` is the comma-separated list of active project ids, plus `NEW` for the new project when there is one. It must list
  every active project and `NEW` exactly once. First = highest priority.
- `dueDate` is parsed as in section 9.8 (`ProjectService::parseDue`).

### 9.4 Validation rules

Every problem is collected, not just the first. A message names the sheet, row and column.

| Category | Rules (examples of the messages) |
|---|---|
| Structure | Required sheet or column missing; empty file; not a workbook; unrecognised layout; duplicate id within the file (`Duplicate id '...' (first used in row N)`) |
| Values | Resource name required; capacity below 1; negative time; invalid status, dependency type, role or boolean; a number that cannot be read |
| References | Resource, item, responsible user or predecessor does not exist; responsible user must be an active executive user; self-predecessor; duplicate predecessor |
| Projects | Operation id already used by another project (ids are unique across projects); dependency that crosses projects |
| Graph | Dependency cycle, reported as a path; nothing is removed |
| Users | `OWNER` cannot be created or changed by an import; only the owner can import a `MANAGER` |
| Started work | An operation past READY (assigned, running, blocked, completed or cancelled) cannot change its resource or its predecessors |
| Warnings (import still applies) | Resource shared by all projects changes capacity; predecessors given for an operation not in the OPC sheet; existing operations not in the file are left unchanged; a sheet is absent |

### 9.5 Apply (`ImportApplier`)

In one transaction:

- Creates the new project (with its priority from the ranking) and writes the new priorities for the other projects, with an
  audit event.
- Creates or updates users. A new user gets a random 12-character temporary password and `must_change_password = true`. The
  password is returned once, in `newUsers[].temporaryPassword` of the response, and is not stored in clear.
- Upserts resources, items and operations by id. Existing operations keep their status and progress.
- Replaces the predecessor links of every operation named in the `Predecessors` sheet (and of the operations in the `OPC` sheet whose predecessors are given).
- Updates the singleton settings. `dataVersion` comes from the Settings sheet, or is incremented: `"3"` becomes `"4"`; a
  non-numeric version gets `.1` appended.
- Writes audit events (`PROJECT_CREATED`, `USER_CREATED`, `USER_UPDATED`, `RESOURCE_CREATED`, `RESOURCE_UPDATED`,
  `OPERATION_CREATED`, `OPERATION_UPDATED`, `IMPORT_APPLIED`).
- Calls `DependencyService::evaluateAll()` so operations become READY or NOT_READY.

Imports do not save the reference plan (section 4.6).

### 9.6 Import history

Every call that reaches `finish()` writes one `import_log` row with its status (`REJECTED`, `VALID`, `APPLIED`,
`PRIORITY_REQUIRED`), the error count and up to 9000 characters of error details. A failed log write is logged and does not
fail the import. `GET /api/import/history` returns the 50 newest rows.

### 9.7 Export (`ExcelService`, `SheetWriter`)

- `template(bool $fa)`: the master template, with the English sheet names (`Resources`, `BOM`, `OPC`, `Predecessors`, `Users`,
  `Settings`) or the Persian names from `FaNames`. The file can be filled in and imported as it is.
- `report(bool $fa)`: the status report with the sheets `Projects`, `Operations`, `User_Performance`, `Import_History` and
  `Audit` (Persian titles in Persian mode).
- `SheetWriter`: bold, frozen header row; right-to-left sheets in Persian. A text cell that starts with `=`, `+`, `-` or `@` (and
  is not a number) gets a leading apostrophe, so spreadsheet software does not run it as a formula.
- Statuses in Persian come from `FaLabels`; dates are Solar Hijri in Tehran time (`Jalali`).
- Language: `?lang=fa` or `?lang=en` decides; without it, `Accept-Language` decides (`LanguageService::requestIsPersian()`).

### 9.8 Persian names, translation and labels

- `FaNames`: the Persian names of sheets, columns and enum values, both ways. Import accepts them; Persian export uses them.
  Matching ignores case, spaces, underscores and zero-width non-joiners. `SheetReader` finds headers and sheets this way.
- `FaTranslator` (`app/Support/Text/FaTranslator.php`): renders the texts the system generates. The code keeps English; the
  translator maps the text to Persian at the output boundary. Rules are `[pattern, template]` pairs. The pattern is a regular
  expression matched against the whole text (`~\A(?:pattern)\z~u`); the first rule that matches wins. Template placeholders:
  - `{n}`: group n of the match;
  - `{sn}`: group n as an operation status, in Persian;
  - `{mn}`: group n as an assignment mode, in Persian;
  - `{tn}`: group n is itself a system text; it is translated recursively;
  - `{dn}`: group n is a Tehran time `yyyy-MM-dd HH:mm`, written as a Solar Hijri date and time.

  A text with `; ` is translated part by part when no whole rule matches. Text with no matching rule, including all user text,
  is returned unchanged.

  Where it is applied: `LanguageService::forStorage()` for texts stored for later (notifications, reasons, history notes;
  system language), `LanguageService::forRequest()` for answers to one request (errors; request language), and
  `ImportController::localized()` for import findings.

  To add a message: add a rule with the exact English text (escape regex characters), then a case in
  `tests/Unit/Support/FaTranslatorTest.php`. Laravel's own messages (`Access denied`, `Unauthenticated.`, `Not found`, and
  the validation texts) have no rules and stay English.
- `FaLabels`: Persian labels for statuses, import statuses, entities and audit actions. Used by `ExcelService` only.
- `LanguageService`: the system language is the owner setting (`system_setting.language`). The request language comes from
  `Accept-Language`; a header that begins with `fa` selects Persian, and no header means the system language.
- `Jalali`: Solar Hijri conversion (arithmetic, checked by `JalaliTest`), parsing of `1405/07/25` with Persian or Latin digits,
  and Tehran-time formatting. Used by `ProjectService::parseDue()` (a date-only due date means the end of that day in Tehran),
  `ForceService::parseStart()`, `FaTranslator` and the exports.

---

## 10. Security

### 10.1 Authentication and sessions

- `POST /api/auth/login` trims the id, checks the password with `Hash::check`, and refuses an unknown id, an inactive user or a
  wrong password with the same 401 message. On success it calls `Auth::login()` and regenerates the session id.
- Sessions are stored in the database (`sessions` table). Lifetime is `SESSION_LIFETIME` minutes; `expire_on_close` is false.
  Payloads are not encrypted (`session.encrypt` is false), so the database file must be protected.
- Logout invalidates the session and regenerates the CSRF token.
- Known behaviour: nothing checks `active` or the password after sign-in. A deactivated user, or a user whose password the owner
  has reset, keeps an open session until it expires or is removed from the `sessions` table.

### 10.2 CSRF

The `web` group checks the CSRF token on every `/api` write. The SPA sends `X-XSRF-TOKEN` (section 7.2). A missing or wrong token
answers 419 `{"message":"Request failed"}`. There is no CORS configuration: the SPA is same-origin.

### 10.3 Authorization

- `role:` middleware (`app/Http/Middleware/EnsureRole.php`): the role is read from the signed-in user's row in `app_user`. A
  role that is not in the list answers 403 `Access denied`.
- `suspension` middleware (`app/Http/Middleware/CheckSuspension.php`): while the system is `SUSPENDED`, every non-GET request
  by a user who is not the owner answers 423. Reads and `/api/auth/*` stay available.
- Executors own their tasks. The checks are in the code paths that act on an operation:
  - `OperationService::ownTask()` for `start`, `progress`, `complete` and `block` (403 when the task is not theirs);
  - `OperationController::detail()` for reading an operation;
  - `OperationService::comment()` for comments;
  - `DashboardService::user()` and the notification routes filter by the caller's id.
- Owner-only rules (`UserService`): the owner cannot be demoted or disabled; ownership cannot be granted through the API;
  only the owner resets the owner's password.
- Client-side navigation is not a security boundary. The route groups are.

### 10.4 Passwords

- Hashed with bcrypt (`config/hashing.php`, `BCRYPT_ROUNDS`, default 12). The hash column is `password_hash`; it is hidden from
  JSON.
- Minimum length 8 (`UserService::checkPassword()`).
- The seeded accounts use `AISO_SEED_PASSWORD`. The seeder prints a warning when the default is used.
- Users created by an import get a random 12-character temporary password, shown once in the import response.
- `must_change_password` is stored and returned in the user view, but nothing enforces the change. The shell offers a
  voluntary change dialog.

### 10.5 Input handling and the LLM

- Every route validates its input (Laravel rules), and the services check the business rules. Models limit mass assignment with
  `$fillable`.
- The upload checks only that the file is present and not empty. There is no extension or size rule in the code; PHP's
  `upload_max_filesize` and `post_max_size` apply. PhpSpreadsheet decides what is a workbook.
- Excel output neutralises formula prefixes (`SheetWriter::neutralize()`).
- The LLM key is read from `ANTHROPIC_API_KEY` only. The browser sees a boolean (`llmAvailable`), never the key.
- The model is sent only ids, names, numbers and flags (`LlmStrategy::snapshot()`): no contact details, no credentials.
- The LLM request is `POST {ANTHROPIC_BASE_URL}/v1/messages` with the headers `x-api-key` and `anthropic-version: 2023-06-01`.
  A refusal, a non-2xx status or an answer without JSON is an `LlmUnavailableException`. Each returned pick is re-checked with
  `AssignmentState`; violating picks are dropped and reported as warnings.
- Error responses never contain stack traces. Non-HTTP exceptions answer `Unexpected server error`; the details are in
  `storage/logs/`.

### 10.6 Audit trail

- `audit_event` rows are written only by `AuditService::record()`. No route updates or deletes them, and the controlled reset
  keeps them.
- Recorded: status changes, assignments and reassignments, progress, comments, completion approvals, FORCE set and removed,
  re-optimisation, priority changes, settings and mode changes, system status changes, user creation, updates and password
  events (no password values), imports, assignment runs, and the reset.
- Query: `GET /api/audit` (managers and owner), filtered by entity or actor, `limit` up to 500.
- Reasons are stored in the system language.

### 10.7 Known gaps

- No login rate limiting, lockout or two-factor authentication.
- No e-mail password reset. An owner who forgets the password needs database access.
- Sessions are not revoked when a user is deactivated or a password is reset (section 10.1).
- `must_change_password` is not enforced (section 10.4).
- No optimistic locking on operations. `operation.version` exists but nothing increments it, and the "changed by someone
  else" message in `FaTranslator` is produced nowhere. The state checks in `OperationService` and the other write paths are
  not locked. SQLite serialises writes, so the race is theoretical there; on MySQL or PostgreSQL two concurrent transitions of
  the same operation can both pass their checks.
- The upload has no size or type check in the application.

---

## 11. Testing

### 11.1 Commands

```bash
php artisan test             # all PHPUnit suites (Unit and Feature)
composer test               # same, through the Composer script
npx tsc --noEmit            # type check of the screens
npm run build               # type check, then the Vite production build into public/build
```

`phpunit.xml` runs the tests with in-memory SQLite, array sessions and cache, `BCRYPT_ROUNDS=4`, seeding disabled, and a
placeholder `APP_KEY`.

### 11.2 Layout

| Path | Contents |
|---|---|
| `tests/Unit/Scheduling/GraphUtilTest.php` | 5 tests: cycle detection, tail hours, the Java random sequence |
| `tests/Unit/Scheduling/PlanOptimizerTest.php` | 6 tests: priority guarantees, gap filling, running work, reproducibility, never worse than naive |
| `tests/Unit/Scheduling/SchedulePlannerTest.php` | 9 tests: chains, capacity 1 and 2, critical chain, start-to-start, gaps, running tasks |
| `tests/Unit/Support/FaTranslatorTest.php` | 5 tests: parameters, multi-part messages, nested texts, assignment reasons, unknown texts |
| `tests/Unit/Support/JalaliTest.php` | 4 tests: known dates, round trips, Persian digits, input parsing |
| `tests/Feature/AisoFlowTest.php` | 8 HTTP tests: roles and boundaries, login and session, sample import and readiness, assignment and approval, executor flow, LLM fallback, rejected file, downloads, FORCE preview and apply |
| `tests/Feature/WorkbookFixturesTest.php` | 8 HTTP tests on the documented workbooks (valid and invalid files, messages, counts, cycle, managers refused), plus one data-provider method with 3 scheduling scenarios |
| `tests/Fixtures/test-data/` | The workbooks (below) |
| `tests/TestCase.php` | Base class (empty) |

Count: 29 unit test methods and 16 feature test methods, plus one data-provider method that runs 3 cases. That makes 48 cases,
the figure in the README. The count was taken from the test sources; the suite itself was not run for this guide.

### 11.3 Fixtures (`tests/Fixtures/test-data/`)

| Group | Files |
|---|---|
| Import, valid and invalid | `I01-valid`, `I02-missing-column`, `I03-missing-sheet`, `I04-invalid-values`, `I05-bad-references`, `I05b-bad-dependency-type`, `I06-cycle`, `I07-duplicates`, `I08-headers-only`, `I09-partial-bad-row`, `I10-users-owner`, `I11-empty`, `I12-malformed` (`.xlsx`), `I13-not-excel.txt` |
| Several projects | `M1-alpha`, `M2-beta`, `M3-gamma` (manual use: two projects sharing machines) |
| Scheduling scenarios | `S01-sequential`, `S02a-parallel-capacity2`, `S02b-parallel-capacity1`, `S03-join`, `S04-critical-first`, `S05-gap-fill`, `S06-gap-too-small`, `S07-long-chain`, `S08-overload`, `S09-start-to-start`, `S10-optional-dependency`, `S11-running-task` |
| Assignment | `A1-three-equal`, `A3-task-limit`, `A4-capacity`, `A5-critical-path`, `A6-fixed-executor`, `A7a-responsible-user-no-win`, `A7b-responsible-user-wins` |
| FORCE | `F1-force` |
| Template | `T1-simple-one`, `T2-simple-two` |

The test sources reference `I01-valid`, `I02-missing-column`, `I05-bad-references`, `I05b-bad-dependency-type`, `I06-cycle`,
`I11-empty`, `I13-not-excel.txt`, `S01-sequential`, `S02a-parallel-capacity2` and `S02b-parallel-capacity1` by name. The other
files are present for manual testing in the UI and are not referenced by a test.

### 11.4 What is covered and what is not

Covered: the engine rules (planner, optimiser, FORCE checks at unit level), the Java-compatible random sequence, the calendar and
the translator, the role boundaries, the dependency flow, the assignment flow, the LLM fallback, the import validation messages
and counts, the downloads, and a FORCE preview and apply over HTTP.

Not covered by the Laravel tests (see section 13 for the list of Java tests not yet ported): the FORCE flow in depth, the
multi-project flow, the performance of large projects, the LLM call against a live model, the screens in a browser.

---

## 12. Build, run and deploy

### 12.1 Scripts

| Script | Platform | What it does |
|---|---|---|
| `scripts/setup.ps1` | Windows PowerShell | `composer install --optimize-autoloader`; copies `.env.example` to `.env` when missing; `php artisan key:generate --force`; creates `database/database.sqlite` when missing; `php artisan migrate --seed --force`; `npm ci`; `npm run build` |
| `scripts/setup.sh` | Linux, macOS (bash) | The same steps |
| `scripts/start.ps1` | Windows | `php artisan serve --host 0.0.0.0 --port 8080` (`-Port` changes the port) |
| `scripts/start.sh` | Linux, macOS | `php artisan serve --host 0.0.0.0 --port ${1:-8080}` |

The Composer scripts `setup` and `test` do the same jobs (`composer setup`, `composer test`); `setup` does not create the
SQLite file.

Warning: `key:generate --force` replaces `APP_KEY` every time the setup runs. The sessions are encrypted with the key; a new key
signs everyone out. Do not run the setup script again on a live installation. Run the individual steps you need.

### 12.2 Prerequisites

- PHP with the extensions in the table below, on the PATH.
- Composer 2.
- Node.js 20 or later (only to build the screens; `public/build` is kept once built).

| Extension | Used for | Where it is required |
|---|---|---|
| `pdo_sqlite`, `sqlite3` | default database | README; setup scripts |
| `pdo` | database layer | Laravel (suggested) |
| `mbstring` | Persian text | Laravel and PhpSpreadsheet require it |
| `openssl` | encryption, HTTPS calls to the LLM | Laravel requires it; setup scripts list it |
| `curl` | HTTP client | README; setup scripts |
| `ctype`, `filter`, `hash`, `session`, `tokenizer` | framework | Laravel requires them |
| `fileinfo` | upload type detection | README; setup scripts |
| `xml`, `dom`, `libxml`, `simplexml`, `xmlreader`, `xmlwriter` | reading and writing `.xlsx` | PhpSpreadsheet requires them |
| `zip`, `zlib` | `.xlsx` is a zip archive | PhpSpreadsheet requires them |
| `gd` | PhpSpreadsheet requires it | PhpSpreadsheet `composer.json`; README |
| `iconv` | PhpSpreadsheet requires it | PhpSpreadsheet `composer.json` |
| `intl` | optional (PhpSpreadsheet suggests it) | not required |

The setup scripts list only part of this table in their comments (they omit `gd`, `iconv`, `simplexml`, `xmlreader`,
`xmlwriter` and `zlib`). Composer reports the missing ones at `composer install`.

### 12.3 Production notes

- Web root: point the server at `public/`. `public/.htaccess` (Apache, `mod_rewrite`) routes requests to `index.php`. With
  another server, use the equivalent front-controller rule.
- `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://...`, a fresh `APP_KEY`.
- HTTPS: keep `SESSION_SECURE_COOKIE=true` behind HTTPS. Plain HTTP on a host other than localhost needs `false`, or the browser
  keeps no session.
- Reverse proxy: `bootstrap/app.php` does not configure trusted proxies. If the application sits behind a proxy and generated
  URLs or the scheme matter, configure trusted proxies there first.
- `php artisan serve` is a single-process development server. Use a web server (Apache, Nginx, IIS with PHP) for production.
- `php artisan config:cache` is safe: no code outside `config/` calls `env()`. Do not run `php artisan route:cache`. The
  `/api` catch-all is a closure, and Laravel refuses to cache routes that contain closures.
- Writable directories: `storage/` (logs, cache, views, sessions) and `bootstrap/cache/`. Also the directory that holds
  `database/database.sqlite`, because SQLite writes `-wal` and `-shm` files next to it.
- Backups: stop the application, then copy `database/database.sqlite` and any `database.sqlite-wal` and
  `database.sqlite-shm` that exist. With WAL mode, copying the main file alone while the application runs can give an
  inconsistent copy. Keep the copies outside the web root. For MySQL or PostgreSQL use `mysqldump` or `pg_dump`.
- Logs: `storage/logs/`. The API never shows stack traces.
- Frontend changes need `npm run build` on the server (or copy the built `public/build`).

---

## 13. Known limits and open work

| Item | Status | Evidence |
|---|---|---|
| LLM method not run against a live model | open | README, PORTING-STATUS; no key was available |
| Screens not run in a browser (only type check and build) | open | PORTING-STATUS |
| Messenger delivery: the in-app inbox and Bale (being added, section 15). Any other platform is recorded as `FAILED`. | open | `NotificationService::deliver()` |
| No e-mail password reset; no login rate limiting | by design so far | no mail or throttle code exists |
| Owner recovery needs database access | open | README |
| Time is a continuous clock; no working calendar | by design so far | `SchedulePlanner`, `PlanService::hoursFrom()` |
| The optimiser runs per request; no plan cache; large projects (about 1000 operations) are slow in PHP | open | `PlanService`; no `Cache::` use outside the messaging work |
| Java tests not yet ported (list in section 13.1) | open | `backend/src/test` against `tests/` |
| No optimistic locking; `operation.version` unused | open | grep: nothing writes `version` |
| Sessions not revoked on deactivation or password reset | open | `UserService`, `CheckSuspension`, `EnsureRole` |
| `must_change_password` not enforced | open | only stored and displayed |
| Laravel messages (validation, `Access denied`, `Unauthenticated.`, `Not found`) are English in Persian mode | open | `FaTranslator` has no rules for them |
| No upload size or type check in the application | open | `ImportController::upload` |
| `llmModel` typed `string` but sent as `null` | open | section 7.6 |
| Setup scripts run `key:generate --force` on every run | open | `scripts/setup.*` |
| `npm run dev` does not serve the Laravel application | by design so far | section 7.8 |

### 13.1 Java tests not ported

`backend/src/test/java/com/aiso/` contains 14 test classes. The Laravel suite covers `AisoFlowIntegrationTest`
(as `AisoFlowTest`), `FaTranslatorTest`, `GraphUtilTest`, `JalaliTest`, `PlanOptimizerTest` and `SchedulePlannerTest` (as
unit tests). These are not ported: `ForceFlowIntegrationTest`, `MultiProjectFlowIntegrationTest`, `PlanForceTest`,
`ForceMessagesTest`, `PlanPerformanceTest`, `AlgorithmStrategyTest`, `LlmStrategyValidationTest`. `MigrationTest` is not listed
in either status document. It checks that an installation at schema V1 upgrades without losing data. The Laravel edition uses one
consolidated migration (PORTING-STATUS.md), so this test has no counterpart.

PORTING-STATUS.md lists the seven above. README.md names only four of them.

---

## 14. Contribution notes

### 14.1 Where code goes

| Kind of change | Put it in |
|---|---|
| A rule about operations, projects, users or settings | a service in `app/Services/`; the controller only validates and calls it |
| Scheduling behaviour | `app/Scheduling/` (pure PHP; no Eloquent, no framework classes) |
| An assignment method | a class implementing `AssignmentStrategy` in `app/Assignment/`, checked by `AssignmentState` |
| A new import rule | `ImportValidator` (with the message) and a fixture plus a test in `tests/Feature/WorkbookFixturesTest.php` |
| A new endpoint | a controller method in `app/Http/Controllers/Api/<Area>Controller.php`, a route in `routes/web.php` inside the correct role group |
| A new error for users | `ApiException::badRequest/forbidden/notFound/conflict` with an English message, and a rule in `FaTranslator` |
| A new audit action | `AuditService::record()` from the service; a label in `FaLabels` if it appears in exports |
| A new screen | section 7.7 |
| A new system text in Persian | a rule in `FaTranslator`, with a unit test |

### 14.2 Conventions observed in the code

- One class per file, PSR-4 under `App\` (`composer.json` autoload). Namespaces follow the folders.
- Controllers are thin: a validated array, one service call, `response()->json(...)`. Commands that return nothing return `null`
  (empty `200`).
- Services are plain classes with constructor injection (`ProjectData` and `PlanView` are `final`). Stateless helpers are `static`.
- Value objects in the engine (`Task`, `Slot`, `Plan`, ...) are `final` and immutable where practical.
- Enums are backed by the database strings (`OperationStatus::READY->value === 'READY'`).
- Database columns are `snake_case`; JSON keys are `camelCase`; the translation happens in the view arrays.
- Timestamps in JSON use the format `Y-m-d\TH:i:s.v\Z` (UTC, milliseconds).
- Errors are thrown as `ApiException`; a bare `\Exception` becomes a 500.
- Audit entries are written by the service that makes the change, next to the change itself.

### 14.3 Keeping the frontend types in sync

- Treat `resources/js/lib/types.ts` as the contract. When a view array changes, update the interface and the readers in the same
  change (section 7.6).
- Use a real response to check the names and the `null` cases. Typed fields that can be `null` in PHP must be `| null` in
  TypeScript.
- Keep the two dictionaries in `lib/i18n.ts` identical in keys. Add a key to `en` and `fa` in the same edit.
- After any change: `npx tsc --noEmit`, `npm run build`, `php artisan test`.

### 14.4 Tests to add with a change

- Engine change: a unit test in `tests/Unit/Scheduling/`, with a small input built from `Task` objects.
- Endpoint change: a case in `tests/Feature/AisoFlowTest.php` (or a new feature test class) that checks the status code and the
  JSON keys.
- Import rule: a fixture in `tests/Fixtures/test-data/` and an assertion on its message.

---

## 15. Messaging connectors

Status: a Bale messenger connector is being added by another change. This section describes the delivery seam and the files
that exist at the time of writing. The connector's behaviour and set-up belong in its own documentation.

The seam: `NotificationService::notifyUser()` always stores the in-app inbox row (`notification`). It then calls the private
method `deliver(string $message, AppUser $recipient)`, which reads `system_setting.messenger_platform`:

| Platform value | Result |
|---|---|
| `IN_APP` or empty | Nothing beyond the inbox. The row stays `SENT`. |
| `BALE` | Sends the message to the recipient's linked chat (`app_user.messenger_id`). Without a linked chat, the inbox only. |
| any other value | `deliver()` throws. The row becomes `FAILED` with the error text. |

Failed deliveries are listed by `GET /api/notifications/failed` (managers and owner) and counted on the owner dashboard. The
platform name is stored upper-cased (`AdminService::update()`) and any value is accepted there. A platform without a connector
fails visibly on purpose.

Files that exist at the time of writing:

| File | Role |
|---|---|
| `app/Messaging/BaleBot.php`, `BaleClient.php`, `BaleException.php` | Bot and HTTP client of the connector |
| `app/Console/Commands/BalePoll.php` | Command `bale:poll` (receives and answers bot messages; `--once` processes one batch) |
| `app/Services/BaleLinkService.php` | Links a Bale chat to a user with a short-lived code (cache-based) |
| `app/Http/Controllers/Api/BaleController.php` | Routes `GET /api/me/bale`, `POST /api/me/bale-link-code`, `DELETE /api/me/bale` |
| `config/aiso.php`, `bale` section | `BALE_BOT_TOKEN`, `BALE_API_URL` |
| `lib/i18n.ts`, `bale.*` and `user.bale` keys | Labels in both dictionaries |
| `tests/Feature/BaleConnectorTest.php` | Feature tests of the connector |
