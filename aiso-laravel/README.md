# AISO — Laravel edition (frontend and backend on one server)

A standalone port of the AISO Spring/Next.js application. One Laravel application serves the JSON API (`/api`) and the
React screens (every other path), so the whole system is one folder, one database file and one process.

- **Backend**: Laravel 12 (PHP 8.2+), session authentication, Eloquent, PhpSpreadsheet for Excel.
- **Frontend**: React 19 + TypeScript + Tailwind 4, built with Vite into `public/build`, routed with React Router. The screens,
  Persian/English texts and Solar Hijri calendar are carried over from the Next.js app.
- **Database**: SQLite by default (`database/database.sqlite`); MySQL and PostgreSQL work through `.env`.
- **Portable**: copy the folder (with `vendor/` and `public/build/`, or run the setup once), keep `.env`, and run `php artisan serve`.

The porting log and the mapping of every Spring class are in [PORTING-STATUS.md](PORTING-STATUS.md).

## Requirements

- PHP 8.2+ with `pdo_sqlite`, `sqlite3`, `mbstring`, `xml`, `dom`, `zip`, `fileinfo`, `openssl`, `curl`, `gd` (PhpSpreadsheet needs it)
- Composer 2
- Node.js 20+ (only to build the screens; `public/build/` is kept in the folder once built)

## Quick start

Windows (PowerShell):

```powershell
./scripts/setup.ps1     # installs, creates .env and the SQLite file, migrates, seeds, builds the screens
./scripts/start.ps1     # http://localhost:8080
```

Linux / macOS:

```bash
./scripts/setup.sh
./scripts/start.sh      # http://localhost:8080
```

The seed also loads the sample project (16 operations, 15 dependencies, 6 resources), so the screens have data at once.

| Account | Role | Password |
|---|---|---|
| `owner` | Owner (settings, users, reset) | `ChangeMe!123` |
| `manager` | Manager (plan, projects, assignment, reports) | `ChangeMe!123` |
| `user1`, `user2`, `user3` | Executive users (their own tasks) | `ChangeMe!123` |

The password is `ChangeMe!123` unless `AISO_SEED_PASSWORD` was set **before the first migration**; change it for anything
beyond a trial. Set `AISO_SEED_DEMO_DATA=false` to start with an empty system instead.

Then, as **manager**: *Task assignment → Suggest assignments → Approve* (the algorithm proposes work for the two single-slot
resources). Each executive user sees their own tasks on *My tasks*. To load another workbook, use *Excel import* with one
of the files in `tests/Fixtures/test-data/` (for example `M1-alpha.xlsx`, then `M2-beta.xlsx` to see two projects share
machines).

## Running on a server

- Point the web server's document root at `public/` (the `.htaccess` file is included), or run `php artisan serve` behind a reverse proxy with HTTPS.
- Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, and a fresh `APP_KEY` (`php artisan key:generate`).
- Keep `SESSION_SECURE_COOKIE=true` behind HTTPS. Over plain HTTP on a non-localhost host set it to `false`, otherwise the browser keeps no session.
- Back up by copying `database/database.sqlite` while the application is stopped (or `mysqldump` / `pg_dump` for those databases).
- Cron is not needed; the application has no background jobs.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql`; other `DB_*` variables as usual |
| `SESSION_LIFETIME` | `480` | Minutes a signed-in session lasts (the former JWT lifetime) |
| `SESSION_SECURE_COOKIE` | `true` | `false` only for plain HTTP outside localhost |
| `AISO_SEED_PASSWORD` | `ChangeMe!123` | Password of the seeded accounts (first migration only) |
| `AISO_LANGUAGE` | `fa` | Language of system-generated texts on a fresh install (`fa` or `en`); the owner can change it |
| `ANTHROPIC_API_KEY` | empty | Key for the LLM assignment method; read from the environment, never stored in the database |
| `AISO_LLM_MODEL` | empty | Model id for the LLM method (required together with the key) |
| `AISO_LLM_TIMEOUT`, `AISO_LLM_MAX_TOKENS`, `AISO_LLM_FALLBACK` | `120`, `16000`, `true` | LLM call settings; with fallback on, a failed call falls back to the algorithm and says so |
| `AISO_RESPONSIBLE_BONUS_HOURS` | `8` | Soft preference for a resource's responsible user in the algorithm |

## What is included (feature parity with the Spring version)

Everything the Spring MVP 0.3 did is ported; the map from the Java classes to the PHP classes is in
[PORTING-STATUS.md](PORTING-STATUS.md).

- **Scheduling engine**: capacity-constrained planner, the multi-project optimiser (strict priority, gap filling, look-ahead
  scoring, reference plan stability), the randomised rule with a bit-exact `java.util.Random`, and the manager FORCE
  (exact admission checks, preview with impact, board, re-optimise). See `app/Scheduling/`.
- **Operations workflow**: start, progress, complete, block/unblock, comment, cancel, approve completion, unassign,
  dependency engine (`READY` / `NOT_READY`, FS and SS, approval setting), history reports.
- **Projects**: priorities with preview, due dates, archiving, the schedule overview and the impact of an import.
- **Task assignment**: algorithm and LLM methods (switchable), proposals, approve/reject, manual assignment, run history,
  constraint re-check of every LLM answer, priority repair.
- **Excel**: master template and simple layout import (validated first, nothing written unless the file is clean,
  Persian sheet/column/value names accepted), template and report export in Persian or English.
- **Dashboards**: manager, owner (users by role, failed notifications, recent imports and audit), executive user
  (own tasks, notifications, performance), performance report, audit log, in-app inbox.
- **Owner console**: users and roles, settings, language of system texts, assignment method, suspend/resume with reason,
  controlled reset (type `RESET`), demo data.
- **Language**: Persian (RTL) and English screens; all dates in Solar Hijri in Persian mode, Tehran time; system texts
  translated by the same rules as the Spring version (`app/Support/Text/FaTranslator.php`).

## Architecture

```
app/
  Scheduling/     planner, optimiser, FORCE admission, graph helpers, Java-compatible random (pure PHP, no framework)
  Services/       data snapshot, operations, dependencies, assignment, FORCE, projects, scheduling metrics, dashboards, admin
  Assignment/     algorithm and LLM strategies and the shared constraint state
  Imports/        workbook reader, master and simple parsers, validator, applier, Persian names
  Exports/        Excel template and report
  Support/        Jalali calendar, Persian text translator and labels
  Http/           JSON controllers under /api, role and suspension middleware
database/migrations/   the schema (replaces the Flyway scripts V1–V4)
resources/js/          React screens (Vite); resources/views/app.blade.php is the single HTML shell
routes/web.php         /api routes (session + CSRF) and the single-page catch-all
```

Access rules are the same as before: OWNER, MANAGER, and the executive users USER_1..3. Every API error is answered as
`{"message": "..."}` in the language of the request. While the system is suspended only the owner can change data.

## Tests

```bash
composer test              # PHPUnit: unit tests for the engine, calendar and translator (tests/Unit)
npx tsc --noEmit           # type-check the screens
npm run build              # production build of the screens
```

Last run: PHP 8.3.33, `php artisan test` — 48 tests, 12 445 assertions, all passing.

- `tests/Unit`: the engine and the calendar and translator rules, ported from the Java unit tests (`PlanOptimizerTest`,
  `SchedulePlannerTest`, `GraphUtilTest`, `JalaliTest`, `FaTranslatorTest`) and the bit-exact `java.util.Random`.
- `tests/Feature/AisoFlowTest.php`: the HTTP scenarios of `AisoFlowIntegrationTest` (roles, sample import and readiness,
  assignment and approval, the executor flow, LLM fallback, a rejected file, downloads, a FORCE).
- `tests/Feature/WorkbookFixturesTest.php`: the documented workbooks in `tests/Fixtures/test-data` (valid and invalid files
  with their messages, the import counts, and the makespans of the scheduling scenarios).

Not ported yet: `ForceFlowIntegrationTest`, `MultiProjectFlowIntegrationTest`, `PlanForceTest`, `AlgorithmStrategyTest`.

## Known limits

- The LLM method has not been run against a live model (no key was available). The request format follows the Messages API
  and the answer is validated like the algorithm's; try it once with your key.
- Messenger delivery is the in-app inbox only, as before (other platforms are reported as failed).
- No email-based password reset and no login rate limiting, as before. Owner recovery needs database access.
- Time is a continuous clock (no working calendar); a FORCE fixes time and executor, not the machine.
- The optimiser runs per request; large projects (about 1000 operations) take noticeably longer in PHP than in Java.
  There is no plan cache yet.
