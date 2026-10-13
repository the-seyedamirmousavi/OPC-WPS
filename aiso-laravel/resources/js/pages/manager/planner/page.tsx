import { useEffect, useLayoutEffect, useMemo, useRef, useState } from "react";
import { Banner, Button, Card, Empty, ErrorBox, Field, Loading, Mono, PageHeader, inputClass } from "@/components/ui";
import { useT, useToast } from "@/components/Providers";
import { api, upload, useFetch } from "@/lib/api";

// Planning without Excel: the current plan is loaded as a draft, edited in tables, checked and saved through the same
// rules an Excel import uses, and can be downloaded as an Excel file in the import layout.

type DepType = "FINISH_TO_START" | "START_TO_START";
interface Res {
  id: string;
  name: string;
  type: string | null;
  userId: string | null;
  capacity: number;
  status: string;
  description: string | null;
}
interface Op {
  id: string;
  name: string;
  itemId: string | null;
  resourceId: string;
  userId: string | null;
  prep: number;
  transport: number;
  setup: number;
  direct: number;
  description: string | null;
  status?: string;
  locked?: boolean;
}
interface Pred {
  operationId: string;
  predecessorId: string;
  type: DepType;
  mandatory: boolean;
}
interface Snapshot {
  projects: { id: string; name: string; priority: number; dueDate: string | null }[];
  projectId: string | null;
  resources: Res[];
  operations: Op[];
  predecessors: Pred[];
  executors: { id: string; fullName: string }[];
}
interface Finding {
  sheet: string;
  row: number;
  column: string | null;
  message: string;
}
interface Outcome {
  status: "VALID" | "APPLIED" | "REJECTED" | "PRIORITY_REQUIRED";
  errors: Finding[];
  warnings: string[];
  projectId: string | null;
}
interface Draft {
  resources: Res[];
  operations: Op[];
  predecessors: Pred[];
}

type Goal = "PRIORITY" | "RESOURCES" | "TIME";
interface FromExcel {
  status: Outcome["status"];
  errors: Finding[];
  warnings: string[];
  draft: Draft | null;
}
interface GoalOption {
  goal: Goal;
  makespanHours: number;
  wasteHours: number;
  utilizationPercent: number;
  projects: { projectId: string; finishHours: number }[];
}
interface GoalData {
  goal: Goal;
  focusProjectId: string | null;
  projects: { id: string; name: string; priority: number }[];
  options: GoalOption[];
}
const GOALS: Goal[] = ["PRIORITY", "RESOURCES", "TIME"];

const NEW = "__new__";
const small = `${inputClass} py-1!`;

/**
 * Puts the rows of a checked Excel file into the draft: a row with a known id replaces that row (started operations
 * keep their status and lock), a new id is appended, rows the file does not mention stay. The dependencies of every
 * operation the file mentions are replaced by the file's.
 */
function mergeDraft(cur: Draft, inc: Draft): Draft {
  const upsert = <T extends { id: string }>(list: T[], incoming: T[], merge: (old: T, n: T) => T): T[] => {
    const byId = new Map(incoming.map((x) => [x.id, x]));
    const have = new Set(list.map((x) => x.id));
    return [...list.map((x) => (byId.has(x.id) ? merge(x, byId.get(x.id)!) : x)), ...incoming.filter((x) => !have.has(x.id))];
  };
  const touched = new Set([...inc.operations.map((o) => o.id), ...inc.predecessors.map((p) => p.operationId)]);
  return {
    resources: upsert(cur.resources, inc.resources, (old, n) => ({ ...old, ...n })),
    operations: upsert(cur.operations, inc.operations, (old, n) => ({ ...old, ...n, itemId: n.itemId ?? old.itemId })),
    predecessors: [...cur.predecessors.filter((p) => !touched.has(p.operationId)), ...inc.predecessors],
  };
}
// Every table row has this height, so a row never grows with its content. Name and prerequisite cells are sized to fit
// inside it: the name grows to at most three lines, the prerequisites scroll inside their cell.
const ROW = "h-[5.5rem]";
const CELL = "px-1 py-1.5 align-middle";

/** Textarea that starts at one line and grows to at most three. The full text is also in the title attribute. */
function AutoText({ value, ...rest }: { value: string } & Omit<React.TextareaHTMLAttributes<HTMLTextAreaElement>, "rows">) {
  const ref = useRef<HTMLTextAreaElement>(null);
  useLayoutEffect(() => {
    const el = ref.current;
    if (!el) return;
    el.style.height = "auto";
    // scrollHeight leaves out the border, so add it back (offsetHeight - clientHeight)
    el.style.height = `${el.scrollHeight + (el.offsetHeight - el.clientHeight)}px`;
  }, [value]);
  return <textarea ref={ref} rows={1} {...rest} value={value} title={value || undefined} className={`${small} block max-h-[4.5rem] resize-none overflow-y-auto leading-5`} />;
}

/** Next id like OP-004 from the ids already used. */
function nextId(prefix: string, used: string[]): string {
  let max = 0;
  const re = new RegExp(`^${prefix}-(\\d+)$`);
  for (const id of used) {
    const m = re.exec(id);
    if (m) max = Math.max(max, Number(m[1]));
  }
  return `${prefix}-${String(max + 1).padStart(3, "0")}`;
}

/** Choosing a file checks it like an import into this project; when it passes, its values are filled into the draft. */
function ExcelCard({ projectId, newName, lang, onFill }: { projectId: string | null; newName: string; lang: string; onFill: (d: Draft) => void }) {
  const { t, num } = useT();
  const toast = useToast();
  const input = useRef<HTMLInputElement>(null);
  const [busy, setBusy] = useState(false);
  const [res, setRes] = useState<FromExcel | null>(null);

  const choose = async (file: File | null) => {
    if (!file) return;
    setBusy(true);
    setRes(null);
    try {
      const form = new FormData();
      form.append("file", file);
      if (projectId) form.append("projectId", projectId);
      else if (newName.trim()) form.append("newProjectName", newName.trim());
      const r = await upload<FromExcel>("/planning/from-excel", form);
      setRes(r);
      if (r.draft) onFill(r.draft);
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
      if (input.current) input.current.value = "";
    }
  };

  return (
    <Card title={t("planner.excel.title")}>
      <p className="mb-3 text-xs text-ink-3">{t("planner.excel.hint")}</p>
      <div className="flex flex-wrap items-center gap-3">
        <input
          ref={input}
          type="file"
          accept=".xlsx,.xls"
          disabled={busy}
          aria-label={t("planner.excel.choose")}
          onChange={(e) => choose(e.target.files?.[0] ?? null)}
          className="max-w-full text-sm file:me-3 file:rounded-lg file:border file:border-line-strong file:bg-surface file:px-3 file:py-2 file:text-sm file:font-medium"
        />
        <a href={`/api/import/template?lang=${lang}`} download className="text-sm text-accent underline">
          {t("planner.excel.template")}
        </a>
        {busy && <span className="text-sm text-ink-3">{t("planner.excel.reading")}</span>}
      </div>
      {res && (
        <div className="mt-4 space-y-3">
          {res.draft ? (
            <Banner tone="ok">
              {t("planner.excel.filled", { res: num(res.draft.resources.length, 0), ops: num(res.draft.operations.length, 0), deps: num(res.draft.predecessors.length, 0) })}
            </Banner>
          ) : (
            <Banner tone="error">{t("planner.excel.rejected", { n: num(res.errors.length, 0) })}</Banner>
          )}
          {res.errors.length > 0 && (
            <ul className="space-y-1 text-sm">
              {res.errors.map((e, i) => (
                <li key={i} className="rounded border border-bad bg-bad-soft px-2 py-1">
                  <span className="text-ink-3">
                    {e.sheet} #{e.row}
                    {e.column ? ` · ${e.column}` : ""}:{" "}
                  </span>
                  {e.message}
                </li>
              ))}
            </ul>
          )}
          {res.warnings.length > 0 && (
            <ul className="list-disc space-y-1 ps-5 text-sm text-ink-2">
              {res.warnings.map((w, i) => (
                <li key={i}>{w}</li>
              ))}
            </ul>
          )}
        </div>
      )}
    </Card>
  );
}

/** The three planning goals, the one in force, and what each would give on the saved plan. */
function GoalCard({ refresh }: { refresh: string }) {
  const { t, num } = useT();
  const toast = useToast();
  const [choice, setChoice] = useState<Goal | null>(null);
  const [focus, setFocus] = useState("");
  const [busy, setBusy] = useState(false);
  const snap = useFetch<GoalData>(`/planning/goals${focus ? `?focus=${encodeURIComponent(focus)}` : ""}`);
  const first = useRef(true);
  const reloadRef = useRef(snap.reload);
  reloadRef.current = snap.reload;
  // the saved plan changed (a save, or another project picked above): the comparison is out of date
  useEffect(() => {
    if (first.current) {
      first.current = false;
      return;
    }
    void reloadRef.current();
  }, [refresh]);

  const g = snap.data;
  if (snap.error && !g) return <ErrorBox message={snap.error} />;
  if (!g) return <Loading />;

  const goal = choice ?? g.goal;
  const focusId = focus || g.focusProjectId || g.projects[0]?.id || "";
  const unchanged = goal === g.goal && (goal !== "TIME" || focusId === g.focusProjectId);
  const name = (id: string) => g.projects.find((p) => p.id === id)?.name ?? id;

  const save = async () => {
    setBusy(true);
    try {
      await api("/planning/goal", { method: "PUT", body: { goal, focusProjectId: goal === "TIME" ? focusId : null } });
      toast(t("planner.goal.saved"));
      setChoice(null);
      await snap.reload();
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(false);
    }
  };

  return (
    <Card title={t("planner.goal.title")}>
      <p className="mb-3 text-xs text-ink-3">{t("planner.goal.sub")}</p>
      <div role="radiogroup" aria-label={t("planner.goal.title")} className="grid grid-cols-1 gap-3 lg:grid-cols-3">
        {GOALS.map((k) => (
          <label
            key={k}
            className={`block cursor-pointer rounded-lg border p-3 text-sm ${goal === k ? "border-accent bg-accent-soft" : "border-line-strong bg-surface hover:bg-surface-2"}`}
          >
            <span className="flex items-center gap-2 font-medium text-ink">
              <input type="radio" name="planning-goal" value={k} checked={goal === k} onChange={() => setChoice(k)} />
              {t(`planner.goal.${k}`)}
              {g.goal === k && <span className="rounded-full border border-line bg-surface px-2 py-0.5 text-xs font-normal text-ink-2">{t("planner.goal.active")}</span>}
            </span>
            <span className="mt-1 block text-xs text-ink-2">{t(`planner.goal.${k}.desc`)}</span>
          </label>
        ))}
      </div>

      {goal === "TIME" && (
        <div className="mt-3 max-w-sm">
          <Field label={t("planner.goal.focus")}>
            <select className={inputClass} value={focusId} onChange={(e) => setFocus(e.target.value)}>
              {g.projects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.name} ({p.id})
                </option>
              ))}
            </select>
          </Field>
        </div>
      )}
      {goal === "PRIORITY" && g.projects.length > 1 && (
        <p className="mt-3 text-xs text-ink-3">{t("planner.goal.order", { list: g.projects.map((p, i) => `${num(i + 1, 0)}. ${p.name}`).join(", ") })}</p>
      )}
      <div className="mt-3">
        <Button variant="primary" busy={busy} disabled={busy || unchanged || (goal === "TIME" && focusId === "")} onClick={save}>
          {t("planner.goal.save")}
        </Button>
      </div>

      <h3 className="mb-2 mt-5 text-sm font-semibold">{t("planner.goal.compare")}</h3>
      {g.projects.length === 0 ? (
        <Empty>{t("planner.goal.noProjects")}</Empty>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-xs text-ink-3">
                <th className="px-2 py-2 text-start font-medium">{t("planner.goal.col.goal")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("planner.goal.col.makespan")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("planner.goal.col.waste")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("planner.goal.col.util")}</th>
                {g.projects.map((p) => (
                  <th key={p.id} className="max-w-48 truncate px-2 py-2 text-start font-medium" title={`${t("planner.goal.finishes")}: ${p.name}`}>
                    {p.name}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {g.options.map((o) => (
                <tr key={o.goal} className={`border-t border-line ${o.goal === g.goal ? "bg-accent-soft" : ""}`}>
                  <td className="whitespace-nowrap px-2 py-1.5 font-medium">{t(`planner.goal.${o.goal}`)}</td>
                  <td className="whitespace-nowrap px-2 py-1.5">{num(o.makespanHours, 1)}</td>
                  <td className="whitespace-nowrap px-2 py-1.5">{num(o.wasteHours, 1)}</td>
                  <td className="whitespace-nowrap px-2 py-1.5">{num(o.utilizationPercent, 0)}%</td>
                  {g.projects.map((p) => (
                    <td key={p.id} className="whitespace-nowrap px-2 py-1.5" title={name(p.id)}>
                      {num(o.projects.find((x) => x.projectId === p.id)?.finishHours ?? 0, 1)}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-2 text-xs text-ink-3">{t("planner.goal.finishes")}</p>
    </Card>
  );
}

export default function PlannerPage() {
  const { t, lang, num } = useT();
  const toast = useToast();
  const [sel, setSel] = useState("");
  const snap = useFetch<Snapshot>(`/planning/plan?projectId=${encodeURIComponent(sel === NEW ? "__none__" : sel)}`);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [base, setBase] = useState("");
  const [newName, setNewName] = useState("");
  const [newDue, setNewDue] = useState("");
  const [busy, setBusy] = useState<"check" | "save" | null>(null);
  const [outcome, setOutcome] = useState<(Outcome & { kind: "check" | "save" }) | null>(null);
  // resource row being dragged and the row it is currently over (drag and drop reordering)
  const [drag, setDrag] = useState<{ from: number; over: number } | null>(null);
  const resourceBody = useRef<HTMLTableSectionElement>(null);

  // every time a plan arrives from the server it becomes the draft and the "saved" baseline
  useEffect(() => {
    if (!snap.data) return;
    const d: Draft = {
      resources: snap.data.resources,
      operations: snap.data.operations,
      predecessors: snap.data.predecessors,
    };
    setDraft(d);
    setBase(JSON.stringify(d));
    setOutcome(null);
  }, [snap.data]);

  const data = snap.data;
  const isNew = sel === NEW || (data !== null && data.projectId === null);
  const dirty = draft !== null && JSON.stringify(draft) !== base;
  const savedOps = useMemo(() => new Set((data?.operations ?? []).map((o) => o.id)), [data]);
  const savedRes = useMemo(() => new Set((data?.resources ?? []).map((r) => r.id)), [data]);

  if (snap.error && !data) return <ErrorBox message={snap.error} />;
  if (!data || !draft) return <Loading />;

  const set = (patch: Partial<Draft>) => setDraft({ ...draft, ...patch });
  const updRes = (i: number, p: Partial<Res>) => set({ resources: draft.resources.map((r, k) => (k === i ? { ...r, ...p } : r)) });
  const updOp = (i: number, p: Partial<Op>) => set({ operations: draft.operations.map((o, k) => (k === i ? { ...o, ...p } : o)) });

  const addResource = () =>
    set({
      resources: [
        ...draft.resources,
        { id: nextId("R", draft.resources.map((r) => r.id)), name: "", type: null, userId: null, capacity: 1, status: "ACTIVE", description: null },
      ],
    });
  const removeRes = (i: number) => set({ resources: draft.resources.filter((_, k) => k !== i) });
  /** Moves the resource at index $from to index $to; the order is saved with the plan and drives the timeline. */
  const moveRes = (from: number, to: number) => {
    if (from === to || from < 0 || to < 0 || from >= draft.resources.length || to >= draft.resources.length) return;
    const next = [...draft.resources];
    const [row] = next.splice(from, 1);
    next.splice(to, 0, row);
    set({ resources: next });
  };
  /** Index of the resource row under the pointer; above the first row is 0, below the last row is the last. */
  const rowAt = (clientY: number): number => {
    const rows = Array.from(resourceBody.current?.children ?? []);
    for (let k = 0; k < rows.length; k++) {
      if (clientY < rows[k].getBoundingClientRect().bottom) return k;
    }
    return Math.max(rows.length - 1, 0);
  };
  const fillFromFile = (incoming: Draft) => {
    setDraft(mergeDraft(draft, incoming));
    setOutcome(null);
  };

  const addOp = () =>
    set({
      operations: [
        ...draft.operations,
        {
          id: nextId("OP", draft.operations.map((o) => o.id)),
          name: "",
          itemId: null,
          resourceId: draft.resources.find((r) => r.status === "ACTIVE")?.id ?? "",
          userId: null,
          prep: 0,
          transport: 0,
          setup: 0,
          direct: 1,
          description: null,
        },
      ],
    });
  const removeOp = (i: number) => {
    const id = draft.operations[i].id;
    set({
      operations: draft.operations.filter((_, k) => k !== i),
      predecessors: draft.predecessors.filter((p) => p.operationId !== id && p.predecessorId !== id),
    });
  };

  const addPred = (operationId: string, predecessorId: string) =>
    set({ predecessors: [...draft.predecessors, { operationId, predecessorId, type: "FINISH_TO_START", mandatory: true }] });
  const removePred = (operationId: string, predecessorId: string) =>
    set({ predecessors: draft.predecessors.filter((p) => !(p.operationId === operationId && p.predecessorId === predecessorId)) });
  const togglePred = (operationId: string, predecessorId: string) =>
    set({
      predecessors: draft.predecessors.map((p) =>
        p.operationId === operationId && p.predecessorId === predecessorId ? { ...p, type: p.type === "FINISH_TO_START" ? "START_TO_START" : "FINISH_TO_START" } : p,
      ),
    });

  const submit = async (kind: "check" | "save") => {
    if (isNew && newName.trim() === "") {
      toast(t("planner.nameRequired"), "error");
      return;
    }
    setBusy(kind);
    try {
      const r = await api<Outcome>("/planning/submit", {
        method: "POST",
        body: {
          apply: kind === "save",
          resources: draft.resources,
          operations: draft.operations.map(({ locked: _l, status: _s, ...o }) => o),
          predecessors: draft.predecessors,
          ...(isNew ? { newProjectName: newName.trim(), dueDate: newDue.trim() || undefined } : { projectId: data.projectId }),
        },
      });
      setOutcome({ ...r, kind });
      if (kind === "save" && r.status === "APPLIED") {
        toast(t("planner.saved"));
        if (isNew && r.projectId) setSel(r.projectId);
        else await snap.reload();
      }
    } catch (e) {
      toast(e instanceof Error ? e.message : String(e), "error");
    } finally {
      setBusy(null);
    }
  };

  const totalOf = (o: Op) => o.prep + o.transport + o.setup + o.direct;
  const exportHref = `/api/planning/export?projectId=${encodeURIComponent(data.projectId ?? "")}&lang=${lang}`;

  return (
    <>
      <PageHeader
        title={t("planner.title")}
        sub={t("planner.sub")}
        actions={
          isNew || dirty ? (
            <Button disabled title={t("planner.exportHint")}>
              {t("planner.export")}
            </Button>
          ) : (
            <a href={exportHref} download className="rounded-lg border border-line-strong bg-surface px-3.5 py-2 text-sm font-medium hover:bg-surface-2" title={t("planner.exportHint")}>
              {t("planner.export")}
            </a>
          )
        }
      />

      <div className="space-y-4">
        <Card title={t("planner.project")}>
          <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
            <Field label={t("planner.project")}>
              <select className={inputClass} value={isNew ? NEW : (data.projectId ?? "")} onChange={(e) => setSel(e.target.value)}>
                {data.projects.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name} ({p.id})
                  </option>
                ))}
                <option value={NEW}>{t("planner.newProject")}</option>
              </select>
            </Field>
            {isNew && (
              <>
                <Field label={t("planner.projectName")}>
                  <input className={inputClass} value={newName} onChange={(e) => setNewName(e.target.value)} />
                </Field>
                <Field label={t("planner.dueDate")} hint={t("planner.dueHint")}>
                  <input className={`${inputClass} ltr`} value={newDue} onChange={(e) => setNewDue(e.target.value)} />
                </Field>
              </>
            )}
          </div>
          {isNew && data.projects.length > 0 && <p className="mt-2 text-xs text-ink-3">{t("planner.lastPriority")}</p>}
        </Card>

        <ExcelCard projectId={isNew ? null : data.projectId} newName={newName} lang={lang} onFill={fillFromFile} />

        <GoalCard refresh={base} />

        <Card title={t("planner.resources")} action={<Button onClick={addResource}>{t("planner.addResource")}</Button>}>
          <p className="mb-3 text-xs text-ink-3">{t("planner.resourcesHint")}</p>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[65rem] table-fixed text-sm">
              <colgroup>
                <col className="w-16" />
                <col className="w-28" />
                <col />
                <col className="w-24" />
                <col className="w-32" />
                <col className="w-48" />
                <col className="w-24" />
              </colgroup>
              <thead>
                <tr className="text-xs text-ink-3">
                  <th className="px-1 py-2 text-start font-medium">{t("planner.col.order")}</th>
                  {(["id", "name", "capacity", "status", "responsible"] as const).map((c) => (
                    <th key={c} className="px-1 py-2 text-start font-medium">
                      {t(`planner.col.${c}`)}
                    </th>
                  ))}
                  <th />
                </tr>
              </thead>
              <tbody ref={resourceBody}>
                {draft.resources.map((r, i) => (
                  <tr
                    key={i}
                    className={`${ROW} border-t border-line ${drag?.from === i ? "bg-accent-soft opacity-60" : ""} ${
                      drag && drag.over === i && drag.from !== i ? (drag.from < i ? "border-b-2 border-b-accent" : "border-t-2 border-t-accent") : ""
                    }`}
                  >
                    <td className={CELL}>
                      <div className="flex items-center gap-1">
                        {/* pointer events instead of HTML5 drag and drop: the same code serves mouse, touch and pen */}
                        <span
                          role="img"
                          aria-label={t("planner.move.handle")}
                          title={t("planner.move.handle")}
                          className="cursor-grab touch-none select-none px-1 text-lg leading-none text-ink-3 hover:text-ink active:cursor-grabbing"
                          onPointerDown={(e) => {
                            if (e.button !== 0) return;
                            e.currentTarget.setPointerCapture(e.pointerId);
                            e.preventDefault();
                            setDrag({ from: i, over: i });
                          }}
                          onPointerMove={(e) => {
                            if (drag?.from !== i) return;
                            const over = rowAt(e.clientY);
                            if (over !== drag.over) setDrag({ from: i, over });
                          }}
                          onPointerUp={() => {
                            if (drag?.from === i) moveRes(drag.from, drag.over);
                            setDrag(null);
                          }}
                          onPointerCancel={() => setDrag(null)}
                        >
                          ⠿
                        </span>
                        {/* buttons for keyboards and touch screens, where dragging is not available */}
                        <div className="flex flex-col">
                          <button type="button" disabled={i === 0} aria-label={t("planner.move.up")} title={t("planner.move.up")} className="px-1 text-xs leading-4 text-ink-3 hover:text-ink disabled:opacity-30" onClick={() => moveRes(i, i - 1)}>
                            ▲
                          </button>
                          <button type="button" disabled={i === draft.resources.length - 1} aria-label={t("planner.move.down")} title={t("planner.move.down")} className="px-1 text-xs leading-4 text-ink-3 hover:text-ink disabled:opacity-30" onClick={() => moveRes(i, i + 1)}>
                            ▼
                          </button>
                        </div>
                      </div>
                    </td>
                    <td className={CELL}>
                      {savedRes.has(r.id) ? (
                        <div className="min-w-0 truncate" title={r.id}>
                          <Mono>{r.id}</Mono>
                        </div>
                      ) : (
                        <input className={`${small} ltr`} title={r.id} value={r.id} onChange={(e) => updRes(i, { id: e.target.value })} />
                      )}
                    </td>
                    <td className={CELL}>
                      <AutoText aria-label={t("planner.col.name")} value={r.name} onChange={(e) => updRes(i, { name: e.target.value })} />
                    </td>
                    <td className={CELL}>
                      <input type="number" min={1} className={`${small} ltr`} value={r.capacity} onChange={(e) => updRes(i, { capacity: Number(e.target.value || 1) })} />
                    </td>
                    <td className={CELL}>
                      <select className={small} value={r.status} onChange={(e) => updRes(i, { status: e.target.value })}>
                        <option value="ACTIVE">{t("planner.active")}</option>
                        <option value="INACTIVE">{t("planner.inactive")}</option>
                      </select>
                    </td>
                    <td className={CELL}>
                      <select
                        className={small}
                        title={data.executors.find((u) => u.id === r.userId)?.fullName ?? t("planner.anyone")}
                        value={r.userId ?? ""}
                        onChange={(e) => updRes(i, { userId: e.target.value || null })}
                      >
                        <option value="">{t("planner.anyone")}</option>
                        {data.executors.map((u) => (
                          <option key={u.id} value={u.id}>
                            {u.fullName}
                          </option>
                        ))}
                      </select>
                    </td>
                    <td className={`${CELL} text-end`}>{!savedRes.has(r.id) && <Button variant="ghost" onClick={() => removeRes(i)}>{t("planner.remove")}</Button>}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </Card>

        <Card title={t("planner.operations")} action={<Button onClick={addOp}>{t("planner.addOp")}</Button>}>
          <p className="mb-3 text-xs text-ink-3">{t("planner.opsHint")}</p>
          {draft.operations.length === 0 ? (
            <Empty>{t("planner.noOps")}</Empty>
          ) : (
            <div className="overflow-x-auto">
              <table className="w-full min-w-[87rem] table-fixed text-sm">
                <colgroup>
                  <col className="w-28" />
                  <col />
                  <col className="w-32" />
                  <col className="w-20" />
                  <col className="w-20" />
                  <col className="w-20" />
                  <col className="w-20" />
                  <col className="w-18" />
                  <col className="w-36" />
                  <col className="w-64" />
                  <col className="w-18" />
                </colgroup>
                <thead>
                  <tr className="text-xs text-ink-3">
                    {(["id", "name", "resource", "prep", "transport", "setup", "direct", "total", "fixedUser", "after"] as const).map((c) => (
                      <th key={c} className="px-1 py-2 text-start font-medium">
                        {t(`planner.col.${c}`)}
                      </th>
                    ))}
                    <th />
                  </tr>
                </thead>
                <tbody>
                  {draft.operations.map((o, i) => {
                    const locked = !!o.locked;
                    const mine = draft.predecessors.filter((p) => p.operationId === o.id);
                    const candidates = draft.operations.filter((x) => x.id !== o.id && !mine.some((p) => p.predecessorId === x.id));
                    const lockLabel = `${t("planner.locked")}: ${t(`status.${o.status}`)}`;
                    return (
                      <tr key={i} className={`${ROW} border-t border-line`}>
                        <td className={CELL}>
                          <div className="flex items-center gap-1">
                            {savedOps.has(o.id) ? (
                              <div className="min-w-0 flex-1 truncate" title={o.id}>
                                <Mono>{o.id}</Mono>
                              </div>
                            ) : (
                              <input className={`${small} ltr min-w-0 flex-1`} title={o.id} value={o.id} onChange={(e) => updOp(i, { id: e.target.value })} />
                            )}
                            {locked && (
                              <span role="img" aria-label={lockLabel} title={lockLabel} className="shrink-0 text-xs">
                                🔒
                              </span>
                            )}
                          </div>
                        </td>
                        <td className={CELL}>
                          <AutoText aria-label={t("planner.col.name")} value={o.name} onChange={(e) => updOp(i, { name: e.target.value })} />
                        </td>
                        <td className={CELL}>
                          <select
                            className={small}
                            value={o.resourceId}
                            disabled={locked}
                            title={locked ? lockLabel : (draft.resources.find((r) => r.id === o.resourceId)?.name || o.resourceId)}
                            onChange={(e) => updOp(i, { resourceId: e.target.value })}
                          >
                            {o.resourceId === "" && <option value="" />}
                            {draft.resources.map((r) => (
                              <option key={r.id} value={r.id}>
                                {r.name || r.id}
                              </option>
                            ))}
                          </select>
                        </td>
                        {(["prep", "transport", "setup", "direct"] as const).map((k) => (
                          <td key={k} className={CELL}>
                            <input type="number" min={0} step={0.5} className={`${small} ltr`} value={o[k]} onChange={(e) => updOp(i, { [k]: Number(e.target.value || 0) })} />
                          </td>
                        ))}
                        <td className={`${CELL} whitespace-nowrap`}>{num(totalOf(o), 2)}</td>
                        <td className={CELL}>
                          <select
                            className={small}
                            title={data.executors.find((u) => u.id === o.userId)?.fullName ?? t("planner.anyone")}
                            value={o.userId ?? ""}
                            onChange={(e) => updOp(i, { userId: e.target.value || null })}
                          >
                            <option value="">{t("planner.anyone")}</option>
                            {data.executors.map((u) => (
                              <option key={u.id} value={u.id}>
                                {u.fullName}
                              </option>
                            ))}
                          </select>
                        </td>
                        <td className={CELL}>
                          {/* fixed height: a long list of prerequisites scrolls here instead of making the row taller */}
                          <div className="flex h-[4.5rem] flex-wrap content-start items-start gap-1 overflow-y-auto">
                            {mine.map((p) => (
                              <span key={p.predecessorId} className="inline-flex items-center gap-1 rounded-full border border-line bg-surface-2 px-2 py-0.5 text-xs">
                                <Mono>{p.predecessorId}</Mono>
                                <button
                                  type="button"
                                  disabled={locked}
                                  title={`${t(p.type === "FINISH_TO_START" ? "planner.fs" : "planner.ss")}: ${t("planner.toggleType")}`}
                                  className="text-ink-3 hover:text-ink disabled:opacity-50"
                                  onClick={() => togglePred(o.id, p.predecessorId)}
                                >
                                  {p.type === "FINISH_TO_START" ? "FS" : "SS"}
                                </button>
                                {!locked && (
                                  <button type="button" aria-label={t("planner.remove")} className="text-ink-3 hover:text-bad" onClick={() => removePred(o.id, p.predecessorId)}>
                                    ✕
                                  </button>
                                )}
                              </span>
                            ))}
                            {!locked && candidates.length > 0 && (
                              <select
                                aria-label={t("planner.addAfter")}
                                className="max-w-full rounded border border-line-strong bg-surface px-1 py-0.5 text-xs"
                                value=""
                                onChange={(e) => e.target.value && addPred(o.id, e.target.value)}
                              >
                                <option value="">{t("planner.addAfter")}</option>
                                {candidates.map((x) => (
                                  <option key={x.id} value={x.id}>
                                    {x.id} {x.name && `— ${x.name}`}
                                  </option>
                                ))}
                              </select>
                            )}
                          </div>
                        </td>
                        <td className={`${CELL} text-end`}>{!savedOps.has(o.id) && <Button variant="ghost" onClick={() => removeOp(i)}>{t("planner.remove")}</Button>}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          )}
        </Card>

        <Card>
          <div className="flex flex-wrap items-center gap-3">
            <Button busy={busy === "check"} disabled={busy !== null} onClick={() => submit("check")}>
              {t("planner.check")}
            </Button>
            <Button variant="primary" busy={busy === "save"} disabled={busy !== null || (!dirty && !isNew)} onClick={() => submit("save")}>
              {t("planner.saveBtn")}
            </Button>
            <span className="text-sm text-ink-3">{dirty || isNew ? t("planner.dirty") : t("planner.clean")}</span>
          </div>

          {outcome && (
            <div className="mt-4 space-y-3">
              {outcome.status === "REJECTED" ? (
                <Banner tone="error">{t("planner.rejected")}</Banner>
              ) : outcome.kind === "check" ? (
                <Banner tone="ok">{t("planner.checkOk")}</Banner>
              ) : (
                <Banner tone="ok">{t("planner.saved")}</Banner>
              )}
              {outcome.errors.length > 0 && (
                <div>
                  <h3 className="mb-1 text-sm font-semibold">{t("planner.errors")}</h3>
                  <ul className="space-y-1 text-sm">
                    {outcome.errors.map((e, i) => (
                      <li key={i} className="rounded border border-bad bg-bad-soft px-2 py-1">
                        <span className="text-ink-3">
                          {e.sheet} #{e.row}
                          {e.column ? ` · ${e.column}` : ""}:{" "}
                        </span>
                        {e.message}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
              {outcome.warnings.length > 0 && (
                <div>
                  <h3 className="mb-1 text-sm font-semibold">{t("planner.warnings")}</h3>
                  <ul className="list-disc space-y-1 ps-5 text-sm text-ink-2">
                    {outcome.warnings.map((w, i) => (
                      <li key={i}>{w}</li>
                    ))}
                  </ul>
                </div>
              )}
            </div>
          )}
        </Card>
      </div>
    </>
  );
}
