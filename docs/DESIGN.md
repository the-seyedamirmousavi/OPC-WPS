# AISO MVP 0.3 — design notes

## 1. Operation states (document §8)

`NOT_READY → READY → ASSIGNED → IN_PROGRESS → COMPLETED`, plus `BLOCKED` (from ASSIGNED / IN_PROGRESS, returns to where it came from when the manager unblocks) and `CANCELLED` (manager, reason required).

| Transition | Who | Notes |
|---|---|---|
| NOT_READY ⇄ READY | engine | automatic, see §2 |
| READY → ASSIGNED | manager (approving a proposal, or manual assign) | re-checked at approval time |
| ASSIGNED → READY | manager (unassign) | |
| ASSIGNED → IN_PROGRESS | the assigned user | |
| IN_PROGRESS → COMPLETED | the assigned user | **does not mean quality-approved** |
| COMPLETED → approved | manager | separate event (`completion_approved`) |
| ASSIGNED / IN_PROGRESS → BLOCKED | the assigned user | reason required, managers are notified |
| BLOCKED → previous | manager | |
| any open state → CANCELLED | manager | reason required |

Setting *“dependants wait for manager approval of completion”* (off by default) makes finish-to-start dependants wait for the approval event instead of the user's COMPLETED.

## 2. Dependency rules (document §9)

- No mandatory predecessor → READY.
- `FINISH_TO_START`: predecessor COMPLETED (and approved if the setting above is on).
- `START_TO_START`: predecessor IN_PROGRESS or COMPLETED.
- **All** mandatory predecessors must be satisfied; one unmet predecessor keeps the operation NOT_READY and the UI lists exactly which ones.
- A CANCELLED predecessor never satisfies a dependency. The manager must act (cancel or rework the dependant).
- Optional predecessors (`Is_Mandatory = FALSE`) are shown but do not gate.
- Re-evaluation runs after import, start, completion, approval, cancellation and setting changes.
- Cycles are rejected at import with the cycle path. They are never removed automatically.

## 3. Assignment

Both methods receive the same **snapshot**: READY operations (duration, *tail hours*, fixed executor), executive users (active tasks, open workload hours), resources (capacity, busy slots, responsible user), and the per-user task limit.

**Tail hours** of an operation = its own duration + the longest chain of unfinished work that depends on it. A larger tail means a delay hurts the finish date more (critical-path weight).

### Hard constraints (checked by `AssignmentState` for both methods)

1. The user is an active executive user.
2. If the OPC sheet fixes an executor for the operation, only that user.
3. The resource is active and has a free slot: `ASSIGNED + IN_PROGRESS < capacity`.
4. The user stays within `maxActiveTasksPerUser` (default 3).

### Method 1 — Algorithm (`AlgorithmStrategy`)

1. Sort READY operations by tail hours (descending), then own duration (descending), then id.
2. For each operation: skip with a reason if its resource has no free slot.
3. Among users allowed by the hard constraints pick the lowest *effective load* = open workload hours, minus `responsible-bonus-hours` (default 8) if the user is the resource's responsible user. Ties: fewer active tasks, then user id.
4. Update the working copy (slots, loads) and continue.

Deterministic: same input, same output. Each proposal carries a human-readable reason (rank, chain length, slot, why this user).

### Method 2 — LLM (`LlmStrategy`)

- System prompt states the hard constraints and the objectives (finish early → critical path; balance load; prefer the resource's responsible user).
- User message: the JSON snapshot only. No contact data.
- Output is a **structured output** (JSON schema derived from `LlmOutput`), not free text. The model id is configuration (`AISO_LLM_MODEL`, required; there is no built-in default).
- Every pick is replayed through the same `AssignmentState`. A pick that breaks a constraint is dropped with a warning; duplicates and invented ids are ignored; operations the model did not address are reported as “not addressed”.
- Failure (no key, API error, refusal, unusable output) → fallback to the algorithm, flagged on the run (`fallback = true`), in the UI and in the audit log.

### Review step

Each run creates PENDING proposals (previous pending ones become SUPERSEDED). Approving re-checks the operation is still READY and the constraints still hold; otherwise the proposal is REJECTED with the reason. Planned start/end stored on approval become the operation's **baseline due time**.

Both methods rank READY operations by **project priority first**, then by the start the optimised multi-project plan gave them (§8), then by critical-path weight. The LLM additionally gets the project priority and planned start in its snapshot, and its answer is processed in priority order; if it leaves out an operation of a better-ranked project while a worse-ranked one holds a slot on the same resource, the slot is handed over (only if every hard constraint still holds) and a warning is recorded.

## 4. Schedule planner (`SchedulePlanner`)

Projected schedule of all unfinished work, on a continuous clock starting now:

- IN_PROGRESS operations occupy a slot for their remaining hours (`total × (1 − progress%)`).
- The rest are placed one at a time: among operations whose predecessors are already placed, the one with the largest tail goes first, at the earliest time that satisfies its predecessors (FS: their end, SS: their start) and fits into a free interval of one of the resource's `capacity` lanes (idle gaps are reused).
- Completed/cancelled predecessors count as satisfied. Output: projected start/end per operation and the project makespan.

## 5. Formulas (document §12 asks for these to be fixed in advance)

| Figure | Definition |
|---|---|
| Duration of an operation | `Preparation + Transport + Setup + Direct` (hours) |
| Progress % | `Σ hours of COMPLETED operations ÷ Σ hours of non-CANCELLED operations × 100` |
| Due time | planned end fixed when the operation is assigned |
| Delayed | not COMPLETED/CANCELLED **and** now > due time. Delay hours = now − due time |
| Finished late | COMPLETED with `completed_at > due time` |
| On-time rate (user) | completed on or before due ÷ completed that had a due time |
| Actual ÷ planned (user) | `Σ (completed_at − started_at) in hours ÷ Σ planned hours` over completed operations (1.0 = as planned; includes waiting time, continuous clock) |
| Projected finish | end of the planner schedule |

## 6. Security model

- Passwords: BCrypt. Login by user id + password; JWT (HS256) signed with `AISO_JWT_SECRET`.
- The browser never holds the token in JavaScript: Next.js route handlers keep it in an `httpOnly`, `SameSite=Strict` cookie and call the backend server-side.
- **Role and active flag are re-read from the database on every request**, so disabling a user or changing a role takes effect immediately, not at token expiry.
- Method-level role checks on every endpoint; executive users can only read/act on operations assigned to them (checked in the service layer, tested).
- OWNER can't be created, demoted, disabled or granted through the API or an import. Only the owner manages users and settings; owner and manager can switch the assignment method.
- Suspended system (`SUSPENDED`): all writes except the owner's return `423 Locked`.
- Audit: `Event_ID, Timestamp, Actor_ID, Action, Entity_Type, Entity_ID, Previous_Value, New_Value, Reason, Related_Project_ID` for imports, status changes, assignments, settings, users, resets. Reset removes operational data only, never the audit trail or the accounts, and requires a reason plus typing `RESET`.
- Uploaded Excel text cells are neutralised against formula injection when exported again.
- The Anthropic key lives only in the backend environment.

## 7. Open decisions of document §16 and what was chosen

| Open item | Choice in MVP 0.1 |
|---|---|
| Messenger | In-app inbox with a connector seam. Failed external delivery is recorded and shown (owner/manager). No Telegram connector yet |
| Storage | Database (H2 file by default, PostgreSQL by configuration), Flyway migrations in `backend/src/main/resources/db/migration` |
| Manager interface | Web panel |
| Hosting | Any: one jar + one Node app. No cloud dependency except the optional LLM API |
| Technology | As requested: Spring Boot + Next.js |
| Auth / owner recovery | ID + password + JWT. Owner recovery needs database access (documented limit) |
| Definition of completion / performance | §1 and §5 above, configurable approval step |

## 8. Several projects at once (`PlanOptimizer`)

### Data model
`project(id, name, priority, due_date, status, ...)`; every operation belongs to exactly one project. Resources, items and users are shared. Active projects hold ranks 1..n without gaps (`priority`, 1 = most important). Operation ids are global; a dependency can only point to an operation of the same project. Existing single-project databases are migrated (`V2`) into one project taken from the old settings.

### Priority semantics: strict
Projects are placed in rank order and everything already placed is frozen. A lower-priority project can never delay a higher-priority one; it can only use the time that is left. IN_PROGRESS operations are committed first and never moved. ASSIGNED-but-not-started operations are re-planned (their baseline due time, set at assignment, is kept, so a delay caused by a new top-priority project shows up as "delayed").

### Minimising wasted resource time
*Wasted time* is, summed over every lane of every resource, `(end of the last planned task on the lane) - (busy time on the lane)`: capacity that existed but could not be used because something was still planned later. Utilisation = busy / (busy + wasted).

The optimiser, for each project in rank order:
1. tries several dispatching rules (critical path, longest first, shortest first, most dependants) and, when the project is small enough, up to 48 seeded randomised variants of the critical-path rule (fixed seeds: reproducible);
2. places each candidate with gap filling (earliest start that fits on any lane, reusing idle gaps left by higher-priority work);
3. **looks ahead**: completes the schedule for all lower-priority projects with the critical-path rule and scores the complete result: finish times in priority order (lexicographic), then churn against the reference plan (§10), then total wasted time, then the sum of finish times;
4. keeps the best candidate and freezes it.

The critical-path candidate always reproduces the plain single-pass plan, so the result is never worse than the single-pass plan in priority order (property-tested on random instances). The UI shows both plans ("simple method" = the single-pass plan) and the difference.

Work budget: random variants are reduced automatically for very large projects (about 1.5 million task-comparisons per project), down to the four deterministic rules.

### Adding a project to a running schedule
`POST /api/import` with `newProjectName`:
- no other active project: it becomes rank 1;
- otherwise the answer is `PRIORITY_REQUIRED`, listing the active projects and the **impact** of the default order (new project last). The client sends the file again with `ranking=` (comma separated ids, `NEW` = the new project) — first as a dry run to preview, then with `apply=true`. The impact is computed in memory from copies of the data; nothing is stored until the ranking is confirmed. Applying creates the project, stores all new ranks and records `PROJECT_CREATED` and `PROJECT_PRIORITIES_CHANGED` in the audit log in one transaction.

`PUT /api/projects/priorities` re-ranks later; `POST /api/scheduling/preview` shows the effect first. The plan is a projection recomputed on every request, so the new order takes effect immediately for the Plan page, the dashboards and the next assignment run.

### Reading the numbers
All figures are hours from "now" on a continuous clock. "Tardiness" of a project = projected finish minus its due date (0 if none or on time); it is reported, never used to reorder work.

## 9. Persian and Solar Hijri support

- **Dates**: the JDK has no Persian calendar; `Jalali` (backend) and `lib/jalali.ts` (frontend) implement the arithmetic conversion (unit-tested against known dates and a 12-year round trip). Dates are shown in Tehran time. Input accepts Jalali (`1405/07/25`, Persian digits) as well as ISO.
- **Excel**: `ExcelService` produces the template and the report in Persian or English (sheet names, headers, status values, Jalali dates, right-to-left sheets). `FaNames` maps Persian sheet names, headers and enum/boolean spellings to the canonical English ones, so the importer reads a Persian workbook without any other change.
- **Generated texts**: source texts are English in the code; `FaTranslator` renders them in Persian at the output boundary using pattern rules (anything that matches no rule, e.g. user-written text, is returned unchanged). Answers to one request use the request's `Accept-Language` (the web app sends the UI language); texts that are stored (notifications, assignment reasons, history notes, audit reasons) use the owner's `language` setting.

## 10. Manager FORCE and stable re-planning

### What a FORCE is
A FORCE is a manager decision about **one operation** that the scheduler must treat as a hard constraint:
*start at exactly time T* and/or *done by user U*. Stored in `schedule_force` (one row per operation, `V4`).
The resource of an operation is fixed by the OPC data (there are no alternative resources in the model), so a FORCE
never changes the resource; the parallel lanes of a resource are identical, so "which lane" is not a decision either.

### Constraint hierarchy
| Level | Constraints | What a FORCE does |
|---|---|---|
| Absolute | resource capacity, mandatory FS/SS precedence, running work stays at t=0, nothing starts before now, finished/cancelled work, active resource, active executive user, the executor of running/blocked work | cannot override; the FORCE is refused (preview: `feasible=false`) |
| Manager FORCE | forced start, forced executor | hard for the optimiser and for both assignment methods |
| Manager-overridable | strict project priority, executor fixed in the OPC sheet, active-task limit per user | a FORCE wins; the preview lists what it overrides as warnings |
| Soft (objective) | project finish times, churn, idle resource time, sum of finish times | optimised in this order |

### Construction (`PlanOptimizer`, `ForceCheck`)
1. Running work is placed at 0.
2. **Exact checks** (`ForceCheck`), decided without search and therefore *proofs*: start in the past; precedence lower bound (earliest start with unlimited resources, running work and other FORCEs fixed) later than the forced start; capacity: forced + running intervals on one resource overlap more than its capacity at some moment (for intervals this is exact; the planner's first-fit in start order then always finds a lane). FORCEs are admitted **oldest first** (seniority), so a new FORCE never pushes out an older one; a new FORCE that would make an older one impossible by precedence is rejected too (`BLOCKS_FORCE`). Running work always wins. A FORCE failing a check constrains nothing and is reported (a "not before T" leftover could itself break an older FORCE).
3. FORCEd operations are placed at their exact start.
4. Projects are placed in priority order as before (§8), with gap filling, several rules and look-ahead.
5. **Escalation**: if a FORCE's predecessors still end too late, its (transitive, not yet placed) predecessors are placed *before* all project work, earliest deadline first (deadline = latest start that still meets the FORCE). Only FORCEs that need it are escalated. If it is still late, a FORCE is given up and reported as `NOT_FOUND` (`proven=false`: the heuristic found no schedule; one may exist): first a *younger* FORCE on a resource the late one depends on, if giving it up rescues the older one; otherwise the youngest late FORCE.

### Stability (minimum churn)
The plan is still recomputed on each request, but it now has a **reference plan** (`plan_baseline`), saved whenever a FORCE is set/removed or the manager re-optimises. For an ASSIGNED operation that was assigned after the reference was saved, the planned start its executor was given is the reference instead. A further dispatching rule, **STABLE**, replays the reference: reference order, each operation not earlier than its reference start. The objective became lexicographic:

1. project finish times in priority order (unchanged, §8);
2. churn against the reference: number of moved **committed** (ASSIGNED) operations, then number of moved operations, then total shift (a move = start changed by more than one minute);
3. idle resource time;
4. sum of finish times.

So a change moves only what it must; anything else moves only if that makes a higher-priority project finish earlier. Because STABLE reproduces the reference exactly where it is feasible, re-planning on the saved plan is a **fixed point**: the plan the manager saw in the preview is the plan everybody sees afterwards (property-tested on 200 random instances). *Re-optimise from scratch* (`POST /api/scheduling/reoptimize`) plans without the reference and saves the result as the new reference.

### API
| Method | Path | Purpose |
|---|---|---|
| GET | `/api/scheduling/forces` | FORCEs with state (`HONOURED`, `NOT_HONOURED` + reason, `DONE`), current conflicts, escalated count, reference time |
| POST | `/api/scheduling/forces/{op}/preview` | impact: feasible, conflicts, warnings, moved operations, project finish deltas, idle time before/after. Stores nothing |
| PUT | `/api/scheduling/forces/{op}` | set/replace; 409 with the reason if not feasible. An ASSIGNED operation is reassigned (forced user) or rescheduled (its executor is notified) |
| DELETE | `/api/scheduling/forces/{op}` | remove |
| POST | `/api/scheduling/reoptimize` | plan from scratch, save as reference |

Body of preview/PUT: `{"start": "1405/07/16 14:30" | "2026-10-08 14:30" | ISO instant | null, "userId": "user3" | null, "reason": "..."}` (wall-clock times are Tehran time). Roles: owner and manager.

A FORCE that later becomes impossible (its time passed, a running operation overran, data changed) is **never silently dropped**: the plan schedules the operation as if it were not forced, and the FORCE list and the operation show it as *not met* with the reason, until the manager changes or removes it.

### Assignment
A forced executor replaces the OPC executor in the snapshot both methods see (`ReadyOp.fixedUserId`, `forcedUser=true`), and the per-user active-task limit does not apply to it. Resource free slot and active user still do. Manual assignment of a forced operation to someone else is refused (409). The LLM snapshot carries `forcedByManager`.

### Honesty about optimality
Nothing here is an exact optimiser. Proven infeasibility is reported only for the three exact checks above; everything else that fails is labelled as "not found by the heuristic". The result is deterministic (fixed seeds, sorted iteration; the same input in any order gives the same plan).

### Cost
One planning run, measured by `PlanPerformanceTest` on a developer laptop (5 projects, 10 resources, 20 random FORCEs + reference; runs vary by about ±40 %): 250 ops ≈ 0.2–0.3 s, 500 ≈ 0.7–0.9 s, 1000 ≈ 1.5–2.2 s, 2000 ≈ 0.5–0.8 s (the random-trial budget shrinks with size; in that run nothing needed escalation). Without FORCEs: 0.1 / 0.2–0.3 / 0.3–0.4 / 0.5–0.7 s. The extra cost comes from escalation rounds (each is a full planning attempt). A preview plans twice, applying a FORCE four times.
