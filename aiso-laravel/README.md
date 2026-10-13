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
resources). Each executive user sees their own tasks on *My tasks*. To load another workbook, open *Plan builder*, choose a new project and pick one
of the files in `tests/Fixtures/test-data/` in the *Fill from an Excel file* card (for example `M1-alpha.xlsx`, then `M2-beta.xlsx` to see two projects share
machines).

## Running on a server

- Point the web server's document root at `public/` (the `.htaccess` file is included), or run `php artisan serve` behind a reverse proxy with HTTPS.
- Set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, and a fresh `APP_KEY` (`php artisan key:generate`).
- Keep `SESSION_SECURE_COOKIE=true` behind HTTPS. Over plain HTTP on a non-localhost host set it to `false`, otherwise the browser keeps no session.
- Back up by copying `database/database.sqlite` while the application is stopped (or `mysqldump` / `pg_dump` for those databases).
- Cron is not needed. The Bale connector can run as a long-running process, `php artisan bale:poll` (for example under Supervisor), when Bale is used.

## Configuration

| Variable | Default | Meaning |
|---|---|---|
| `DB_CONNECTION` | `sqlite` | `sqlite`, `mysql` or `pgsql`; other `DB_*` variables as usual |
| `SESSION_LIFETIME` | `480` | Minutes a signed-in session lasts (the former JWT lifetime) |
| `SESSION_SECURE_COOKIE` | `true` | `false` only for plain HTTP outside localhost |
| `AISO_SEED_PASSWORD` | `ChangeMe!123` | Password of the seeded accounts (first migration only) |
| `AISO_LANGUAGE` | `fa` | Language of system-generated texts on a fresh install (`fa` or `en`); the owner can change it |
| `AISO_LLM_API_KEY` | empty | GapGPT token for the AI assignment method. The owner normally enters it in the owner console (stored encrypted); this applies only when nothing is entered there |
| `AISO_LLM_MODEL` | `gapgpt-gemma-26b-a4b` | Model id (also editable in the owner console) |
| `AISO_LLM_BASE_URL` | `https://api.gapgpt.app/v1` | Base URL of the OpenAI-compatible service; the client calls `/chat/completions` |
| `AISO_CA_BUNDLE` | empty | Certificate bundle file for HTTPS calls to the AI service and Bale. Needed when PHP has no `curl.cainfo` (common on Windows: "cURL error 60") |
| `AISO_LLM_TIMEOUT`, `AISO_LLM_MAX_TOKENS`, `AISO_LLM_FALLBACK` | `300`, `4096`, `true` | AI call settings; with fallback on, a failed call falls back to the algorithm and says so |
| `AISO_RESPONSIBLE_BONUS_HOURS` | `8` | Soft preference for a resource's responsible user in the algorithm |
| `AISO_VENDOR_SALT`, `AISO_VENDOR_USER_HASH`, `AISO_VENDOR_PASSWORD_HASH` | empty | The software vendor's sign-in, stored as hashes; created with `php artisan aiso:vendor-credentials` (see *Vendor console* below) |
| `AISO_VENDOR_TOKEN_HASH`, `AISO_VENDOR_TOTP_SECRET`, `AISO_VENDOR_IDLE_MINUTES` | empty, empty, `30` | Optional second factors of the vendor console (a device cookie and an authenticator app) and the idle time of a vendor session |
| `BALE_BOT_TOKEN`, `BALE_API_URL` | empty, `https://tapi.bale.ai` | The Bale messenger bot (see *Bale* below) |

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
- **Plan builder** (`/manager/planner`): the plan is built and edited in tables, without Excel. It is checked with the same rules as an
  Excel import, saved, and downloaded as an importable workbook with a schedule sheet. A checked Excel file can fill the tables
  automatically. The same page sets the **planning goal**: strict project order (default), resources kept busy, or one chosen
  project finished fastest, with a comparison of the three on the saved plan.
- **Import history** (`/import`): the screen lists every check and save of an Excel file or of the plan builder. Nothing is imported
  from it; files are read on the plan builder, which fills the tables and saves nothing until the manager presses Save. A new project
  joining active ones gets the lowest priority.
- **Licence**: an installation can carry a licence (unlimited, one year, a 30-day trial, or a custom period), a grace period after
  expiry (read-only), user and project limits, and switches for the LLM and Bale. Without a licence nothing is restricted.
- **Language**: Persian (RTL) and English screens; all dates in Solar Hijri in Persian mode, Tehran time; system texts
  translated by the same rules as the Spring version (`app/Support/Text/FaTranslator.php`).

## Architecture

```
app/
  Scheduling/     planner, optimiser, FORCE admission, graph helpers, Java-compatible random (pure PHP, no framework)
  Services/       data snapshot, operations, dependencies, assignment, FORCE, projects, scheduling metrics, dashboards, admin,
                  the plan builder, the licence and the vendor console
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

## Vendor console

The software vendor manages the licence of an installation (company, period, grace, limits, suspension), creates the owner and the
first users, and sets the starting settings. It is reached only by signing in on the normal sign-in page with the vendor's own
credentials; nothing in the application links to it. See `docs/VENDOR-CONSOLE.md`.

```bash
php artisan aiso:vendor-credentials                       # creates the user name and password (shown once)
php artisan aiso:vendor-credentials --token-only            # device cookie aiso_dev
php artisan aiso:vendor-credentials --totp-only             # authenticator app
```

## Bale

Users can link their account to the Bale messenger bot with a code (valid 15 minutes, used once). Notifications are then sent to
Bale as well as the in-app inbox. Set the platform to `BALE` in Settings, enter the bot token in the owner console (Settings, Bale messenger; `BALE_BOT_TOKEN` in `.env` is the fallback), and run
`php artisan bale:poll`. The steps for users are in the user manual, section 12. The connector has not been tried against a live bot.

## Tests

```bash
composer test              # PHPUnit: unit tests for the engine, calendar and translator (tests/Unit)
npx tsc --noEmit           # type-check the screens
npm run build              # production build of the screens
```

Last run: PHP 8.3.33, `php artisan test` — 328 tests, 14 142 assertions, all passing.

- `tests/Unit`: the engine and the calendar and translator rules, ported from the Java unit tests (`PlanOptimizerTest`,
  `SchedulePlannerTest`, `GraphUtilTest`, `JalaliTest`, `FaTranslatorTest`) and the bit-exact `java.util.Random`.
- `tests/Feature/AisoFlowTest.php`: the HTTP scenarios of `AisoFlowIntegrationTest` (roles, sample import and readiness,
  assignment and approval, the executor flow, LLM fallback, a rejected file, downloads, a FORCE).
- `tests/Feature/WorkbookFixturesTest.php`: the documented workbooks in `tests/Fixtures/test-data` (valid and invalid files
  with their messages, the import counts, and the makespans of the scheduling scenarios).

Not ported yet: `ForceFlowIntegrationTest`, `MultiProjectFlowIntegrationTest`, `PlanForceTest`, `AlgorithmStrategyTest`.

## Known limits

- The AI method has been tested against a simulated GapGPT only (no token was available): the request and the answer format follow the
  OpenAI chat completions API and the answer is validated like the algorithm's. Try it once with your own token.
- Messenger delivery: the in-app inbox, and Bale when the owner selects it (not yet tried against a live bot). Other platforms are reported as failed.
- No email-based password reset. Sign-in is limited to 10 failed attempts per address per minute, without lockout. Owner recovery needs
  database access.
- The vendor console and its extra factors are for the software vendor only. Their credentials are created with `php artisan aiso:vendor-credentials`.
- Time is a continuous clock (no working calendar); a FORCE fixes time and executor, not the machine.
- The optimiser runs per request; large projects (about 1000 operations) take noticeably longer in PHP than in Java.
  There is no plan cache yet.
