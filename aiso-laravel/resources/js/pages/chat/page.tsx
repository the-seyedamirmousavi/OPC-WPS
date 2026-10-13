import { useEffect, useRef, useState } from "react";
import { Banner, Button, Card, PageHeader, inputClass } from "@/components/ui";
import { useT } from "@/components/Providers";
import { api, upload, useFetch } from "@/lib/api";

interface PendingAction {
  /** who must agree: the person who asked (SELF) or the owner (OWNER) */
  approval?: string;
  note?: string | null;
  id: number;
  department: string;
  operation: string;
  params: Record<string, unknown>;
  reason: string | null;
  risk: "low" | "medium" | "high";
  status: string;
  error?: string | null;
  /** what the action did once it ran: the new operation, who got it, when it is planned */
  result?: Record<string, unknown> | null;
}

/** One step of what the assistant did to prepare an answer. Shown apart from the answer. */
interface TraceStep {
  step: "route" | "fetch" | "answer" | "retry" | "action" | "limit";
  decision?: string;
  department?: string;
  name?: string;
  intent?: string;
  ok?: boolean;
  truncated?: boolean;
  operation?: string;
  /** the protocol summary of the step, shown on hover: what was sent and what came back */
  sent?: string[];
  got?: string[];
}

interface Message {
  role: "user" | "assistant";
  content: string;
  /** departments whose data the answer used */
  sources?: { slug: string; name: string }[];
  /** actions the assistant proposed; they run only when the user confirms */
  actions?: PendingAction[];
  /** what the assistant did before answering */
  trace?: TraceStep[];
  /** short replies offered under the answer, sent with one click */
  choices?: string[] | null;
  /** the preview of an Excel file sent to the assistant */
  attachment?: ExcelPreview | null;
}

/** What the server found in an Excel file sent to the assistant. Nothing is imported until the proposal is confirmed. */
interface ExcelPreview {
  type: "excel_preview";
  uploadId: string;
  fileName: string;
  status: string;
  project: { id: string | null; name: string | null; isNew: boolean };
  counts: { operations: number; resources: number; predecessors: number; items: number };
  rows: { row: number; id: string; name: string; resource: string | null; hours: number; ok: boolean; problems: string[] }[];
  moreRows: number;
  errors: { sheet: string; row: number; column: string | null; message: string }[];
  errorCount: number;
  warnings: string[];
  plannedFinish: string | null;
  validOnly: { status: string; droppedRows: number } | null;
}

interface ConversationItem {
  id: number;
  title: string;
  updatedAt: string;
}

/** The AI assistant. Each person's conversations are saved on the server and only they can open them. */
export default function ChatPage() {
  const { t, date } = useT();
  const list = useFetch<ConversationItem[]>("/chat/conversations");
  const [current, setCurrent] = useState<number | null>(null);
  const [messages, setMessages] = useState<Message[]>([]);
  const [text, setText] = useState("");
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const end = useRef<HTMLDivElement>(null);
  // the id of a message whose answer did not arrive: sending the same text again reuses it, so it is not run twice
  const unanswered = useRef<{ content: string; id: string } | null>(null);
  const fileInput = useRef<HTMLInputElement>(null);

  useEffect(() => {
    end.current?.scrollIntoView({ block: "end" });
  }, [messages, busy]);

  const fail = (e: unknown) => setError(e instanceof Error ? e.message : String(e));

  const open = async (id: number) => {
    setError(null);
    try {
      const c = await api<{ id: number; messages: Message[] }>(`/chat/conversations/${id}`);
      setCurrent(c.id);
      setMessages(c.messages);
    } catch (e) {
      fail(e);
    }
  };

  const startNew = () => {
    setCurrent(null);
    setMessages([]);
    setError(null);
  };

  const remove = async (id: number) => {
    if (!window.confirm(t("chat.confirmDelete"))) return;
    try {
      await api(`/chat/conversations/${id}`, { method: "DELETE" });
      if (current === id) startNew();
      await list.reload();
    } catch (e) {
      fail(e);
    }
  };

  /** Sends what is typed, or a quick reply the user clicked (then the typed draft is left alone). */
  const send = async (quick?: string) => {
    const content = (quick ?? text).trim();
    if (!content || busy) return;
    setMessages((m) => [...m, { role: "user", content }]);
    if (quick === undefined) setText("");
    setError(null);
    setBusy(true);
    const requestId = unanswered.current?.content === content ? unanswered.current.id : newRequestId();
    unanswered.current = { content, id: requestId };
    try {
      const r = await api<{ conversationId: number; reply: string; pending_actions: PendingAction[]; sources: Message["sources"]; trace: TraceStep[]; choices: string[] }>("/chat", {
        method: "POST",
        body: { conversationId: current ?? undefined, message: content, requestId },
      });
      unanswered.current = null;
      setCurrent(r.conversationId);
      setMessages((m) => [...m, { role: "assistant", content: r.reply, sources: r.sources, actions: r.pending_actions, trace: r.trace, choices: r.choices }]);
      await list.reload();
    } catch (e) {
      // nothing was saved: take the message back so it can be sent again
      setMessages((m) => m.slice(0, -1));
      if (quick === undefined) setText(content);
      fail(e);
    } finally {
      setBusy(false);
    }
  };

  /** Sends an Excel file: the server previews it with the import rules and proposes importing it (nothing is saved yet). */
  const attach = async (file: File) => {
    if (busy) return;
    setError(null);
    setBusy(true);
    setMessages((m) => [...m, { role: "user", content: `📎 ${file.name}` }]);
    try {
      const form = new FormData();
      form.append("file", file);
      if (current !== null) form.append("conversationId", String(current));
      const r = await upload<{ conversationId: number; reply: string; pending_actions: PendingAction[]; attachment: ExcelPreview }>("/chat/upload", form);
      setCurrent(r.conversationId);
      setMessages((m) => [...m, { role: "assistant", content: r.reply, actions: r.pending_actions, attachment: r.attachment }]);
      await list.reload();
    } catch (e) {
      setMessages((m) => m.slice(0, -1));
      fail(e);
    } finally {
      setBusy(false);
      if (fileInput.current) fileInput.current.value = "";
    }
  };

  /** Confirms or cancels one proposed action; the server checks it again and runs it only on confirmation. */
  const decide = async (messageIndex: number, action: PendingAction, verb: "confirm" | "cancel") => {
    setError(null);
    try {
      const done = await api<PendingAction>(`/chat/actions/${action.id}/${verb}`, { method: "POST" });
      setMessages((all) =>
        all.map((m, i) => (i === messageIndex ? { ...m, actions: m.actions?.map((a) => (a.id === action.id ? done : a)) } : m)),
      );
    } catch (e) {
      fail(e);
    }
  };

  return (
    <>
      <PageHeader title={t("chat.title")} sub={t("chat.sub")} actions={<Button onClick={startNew}>{t("chat.new")}</Button>} />
      <div className="grid gap-4 lg:grid-cols-[16rem_1fr]">
        <Card title={t("chat.history")}>
          {!list.data || list.data.length === 0 ? (
            <p className="text-sm text-ink-3">{t("chat.noHistory")}</p>
          ) : (
            <ul className="max-h-[60vh] space-y-1 overflow-y-auto">
              {list.data.map((c) => (
                <li key={c.id} className={`flex items-center gap-1 rounded-lg ${current === c.id ? "bg-accent-soft" : "hover:bg-surface-2"}`}>
                  <button type="button" onClick={() => open(c.id)} className="min-w-0 flex-1 px-2 py-2 text-start">
                    <span className="block truncate text-sm">{c.title}</span>
                    <span className="block text-xs text-ink-3">{date(c.updatedAt)}</span>
                  </button>
                  <button
                    type="button"
                    onClick={() => remove(c.id)}
                    aria-label={t("chat.delete")}
                    title={t("chat.delete")}
                    className="shrink-0 rounded-lg px-2 py-1 text-ink-3 hover:text-bad"
                  >
                    ✕
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card>
          <div className="flex h-[60vh] flex-col">
            <div className="flex-1 space-y-3 overflow-y-auto pe-1" aria-live="polite">
              {messages.length === 0 && <p className="py-8 text-center text-sm text-ink-3">{t("chat.empty")}</p>}
              {messages.map((m, i) => (
                <div key={i} className={`flex ${m.role === "user" ? "justify-start" : "justify-end"}`}>
                  <div className="max-w-[85%] space-y-2">
                    {m.role === "assistant" && m.trace && m.trace.length > 0 && <Trace steps={m.trace} />}
                    <div
                      className={`whitespace-pre-wrap rounded-2xl px-3.5 py-2 text-sm ${
                        m.role === "user" ? "bg-accent text-accent-ink" : "bg-surface-2 text-ink"
                      }`}
                    >
                      {m.content}
                    </div>
                    {m.attachment?.type === "excel_preview" && <ExcelPreviewTable p={m.attachment} />}
                    {m.sources && m.sources.length > 0 && (
                      <p className="text-xs text-ink-3">
                        {t("chat.sources")}: {m.sources.map((s) => s.name).join("، ")}
                      </p>
                    )}
                    {m.actions?.map((a) => (
                      <ActionCard key={a.id} a={a} onDecide={(verb) => decide(i, a, verb)} />
                    ))}
                    {/* only the newest answer offers its replies: older ones are already answered */}
                    {m.role === "assistant" && i === messages.length - 1 && !busy && m.choices && m.choices.length > 0 && (
                      <div className="flex flex-wrap gap-2 pt-1">
                        {m.choices.map((c) => (
                          <button
                            key={c}
                            type="button"
                            onClick={() => void send(c)}
                            className="rounded-full border border-accent px-3.5 py-1.5 text-sm text-accent transition-colors hover:bg-accent hover:text-accent-ink"
                          >
                            {c}
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              ))}
              {busy && <p className="text-xs text-ink-3">{t("chat.thinking")}</p>}
              <div ref={end} />
            </div>

            {error && (
              <div className="mt-3">
                <Banner tone="error">{error}</Banner>
              </div>
            )}

            <form
              className="mt-3 flex gap-2"
              onSubmit={(e) => {
                e.preventDefault();
                void send();
              }}
            >
              <textarea
                className={`${inputClass} min-h-11 flex-1 resize-none`}
                rows={2}
                maxLength={2000}
                aria-label={t("chat.placeholder")}
                placeholder={t("chat.placeholder")}
                value={text}
                onChange={(e) => setText(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === "Enter" && !e.shiftKey) {
                    e.preventDefault();
                    void send();
                  }
                }}
              />
              <input
                ref={fileInput}
                type="file"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                className="hidden"
                onChange={(e) => {
                  const f = e.target.files?.[0];
                  if (f) void attach(f);
                }}
              />
              <Button type="button" disabled={busy} onClick={() => fileInput.current?.click()} aria-label={t("chat.attach")} title={t("chat.attachHint")}>
                📎
              </Button>
              <Button type="submit" variant="primary" busy={busy} disabled={!text.trim()}>
                {t("chat.send")}
              </Button>
            </form>
          </div>
        </Card>
      </div>
    </>
  );
}

/** One proposed action: what it will do, and the buttons. Nothing runs until the user confirms. */
function ActionCard({ a, onDecide }: { a: PendingAction; onDecide: (verb: "confirm" | "cancel") => void }) {
  const { t } = useT();
  const [busy, setBusy] = useState(false);
  const label = t(`chat.action.${a.operation}`);
  const run = async (verb: "confirm" | "cancel") => {
    setBusy(true);
    try {
      await onDecide(verb);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="rounded-xl border border-line bg-surface p-3 text-sm">
      <div className="flex items-center justify-between gap-2">
        <span className="font-medium">{label === `chat.action.${a.operation}` ? a.operation : label}</span>
        <span className={`rounded-full px-2 py-0.5 text-xs ${a.risk === "high" ? "bg-bad-soft text-bad" : "bg-warn-soft text-ink"}`}>
          {a.approval === "OWNER" ? t("chat.approval.owner") : t(`chat.risk.${a.risk}`)}
        </span>
      </div>
      <p className="mt-1 text-xs text-ink-2 ltr">
        {Object.entries(a.params).map(([k, v]) => `${k}: ${String(v)}`).join(" · ")}
      </p>
      {a.reason && <p className="mt-1 text-xs text-ink-3">{a.reason}</p>}
      {a.status === "PENDING" ? (
        <div className="mt-2 flex gap-2">
          <Button variant="primary" busy={busy} onClick={() => run("confirm")}>{t("chat.confirm")}</Button>
          <Button disabled={busy} onClick={() => run("cancel")}>{t("chat.cancel")}</Button>
        </div>
      ) : a.status === "AWAITING_OWNER" ? (
        <div className="mt-2 flex flex-wrap items-center gap-2">
          <span className="text-xs text-ink-2">{t("chat.status.AWAITING_OWNER")}</span>
          <Button disabled={busy} onClick={() => run("cancel")}>{t("chat.withdraw")}</Button>
        </div>
      ) : (
        <>
          <p className="mt-2 text-xs text-ink-2">
            {t(`chat.status.${a.status}`)}
            {a.note ? ` — ${a.note}` : ""}
          </p>
          {a.status === "FAILED" && a.error && <p className="mt-1 text-xs text-bad">{a.error}</p>}
          {a.status === "CONFIRMED" && a.result && <ActionResult result={a.result} />}
        </>
      )}
    </div>
  );
}

/** The rows of an Excel file as the import rules see them: each operation with its problems, then the file-level notes. */
function ExcelPreviewTable({ p }: { p: ExcelPreview }) {
  const { t, date } = useT();
  const ok = p.status === "VALID" || p.status === "PRIORITY_REQUIRED";

  return (
    <div className="space-y-2 rounded-xl border border-line bg-surface p-3 text-xs">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-medium">📎 {p.fileName}</span>
        <span className={`rounded-full px-2 py-0.5 ${ok ? "bg-good-soft text-good-ink" : "bg-bad-soft text-bad"}`}>
          {ok ? t("chat.excel.valid") : t("chat.excel.errors").replace("{n}", String(p.errorCount))}
        </span>
      </div>
      <p className="text-ink-2">
        {t("chat.excel.counts")
          .replace("{ops}", String(p.counts.operations))
          .replace("{res}", String(p.counts.resources))
          .replace("{pred}", String(p.counts.predecessors))}
        {p.project.name ? ` · ${t(p.project.isNew ? "chat.excel.newProject" : "chat.excel.project")}: ${p.project.name}` : ""}
        {p.plannedFinish ? ` · ${t("chat.excel.finish")}: ${date(p.plannedFinish)}` : ""}
      </p>
      {p.rows.length > 0 && (
        <div className="max-h-64 overflow-auto rounded-lg border border-line">
          <table className="w-full text-start">
            <thead className="sticky top-0 bg-surface-2 text-ink-3">
              <tr>
                <th className="px-2 py-1 text-start">{t("chat.excel.row")}</th>
                <th className="px-2 py-1 text-start">{t("chat.excel.id")}</th>
                <th className="px-2 py-1 text-start">{t("chat.excel.name")}</th>
                <th className="px-2 py-1 text-start">{t("chat.excel.resource")}</th>
                <th className="px-2 py-1 text-start">{t("chat.excel.hours")}</th>
                <th className="px-2 py-1 text-start">{t("chat.excel.check")}</th>
              </tr>
            </thead>
            <tbody>
              {p.rows.map((r) => (
                <tr key={r.row} className={`border-t border-line ${r.ok ? "" : "bg-bad-soft"}`}>
                  <td className="px-2 py-1 ltr">{r.row}</td>
                  <td className="px-2 py-1 ltr">{r.id}</td>
                  <td className="px-2 py-1">{r.name}</td>
                  <td className="px-2 py-1 ltr">{r.resource ?? "—"}</td>
                  <td className="px-2 py-1 ltr">{r.hours}</td>
                  <td className="px-2 py-1">{r.ok ? <span className="text-good-ink">✓</span> : <span className="text-bad">{r.problems.join(" · ")}</span>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      {p.moreRows > 0 && <p className="text-ink-3">{t("chat.excel.more").replace("{n}", String(p.moreRows))}</p>}
      {p.errors.filter((e) => !p.rows.some((r) => r.ok === false && e.row === r.row)).length > 0 && (
        <ul className="list-disc space-y-0.5 ps-4 text-bad">
          {p.errors
            .filter((e) => !p.rows.some((r) => r.ok === false && e.row === r.row))
            .slice(0, 10)
            .map((e, i) => (
              <li key={i}>
                {e.sheet} {e.row > 0 ? `#${e.row}` : ""} {e.column ? `[${e.column}]` : ""}: {e.message}
              </li>
            ))}
        </ul>
      )}
      {p.warnings.length > 0 && (
        <ul className="list-disc space-y-0.5 ps-4 text-ink-3">
          {p.warnings.slice(0, 6).map((w, i) => (
            <li key={i}>{w}</li>
          ))}
        </ul>
      )}
      {p.validOnly && <p className="text-ink-2">{t("chat.excel.validOnly").replace("{n}", String(p.validOnly.droppedRows))}</p>}
    </div>
  );
}

/** The useful part of what a confirmed action did, e.g. the new operation, who got it and when it is planned. */
function ActionResult({ result }: { result: Record<string, unknown> }) {
  const { t } = useT();
  const keys = ["operation_id", "project_id", "assigned_to", "planned_start", "planned_end", "not_assigned_because", "skipped_rows", "status"];
  const rows = keys.filter((k) => result[k] !== null && result[k] !== undefined && result[k] !== "");
  if (rows.length === 0) return null;

  return (
    <dl className="mt-2 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 rounded-lg bg-surface-2 p-2 text-xs">
      {rows.map((k) => (
        <div key={k} className="contents">
          <dt className="text-ink-3">{t(`chat.result.${k}`)}</dt>
          <dd className="font-medium">{String(result[k])}</dd>
        </div>
      ))}
    </dl>
  );
}

/** A random id for one message, so the server can recognise it when it is sent again. */
function newRequestId(): string {
  if (typeof crypto !== "undefined" && "randomUUID" in crypto) return crypto.randomUUID();
  return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
}

const STEP_ICON: Record<TraceStep["step"], string> = { route: "◎", fetch: "⇣", answer: "✎", retry: "↻", action: "⚑", limit: "■" };

/**
 * What the assistant did before it answered, one step under another: understanding the question, reading from which
 * department, writing the answer. It is deliberately quiet and dashed so it is not mistaken for the answer. Hovering (or
 * focusing) a step opens a box with the short protocol summary of that step: what was sent and what came back.
 */
function Trace({ steps }: { steps: TraceStep[] }) {
  const { t } = useT();
  const [hover, setHover] = useState<{ i: number; x: number; y: number } | null>(null);
  // a "need more data" round is already visible as the fetches that follow it
  const shown = steps.filter((s) => !(s.step === "answer" && s.decision !== "answer"));
  const label = (s: TraceStep): string => {
    if (s.step === "fetch") {
      const intent = t("chat.trace.intent." + s.intent);
      return s.name + " · " + (intent === "chat.trace.intent." + s.intent ? s.intent : intent) + (s.truncated ? " …" : "");
    }
    if (s.step === "action") return t("chat.trace.action") + ": " + t("chat.action." + s.operation);
    return t("chat.trace." + s.step);
  };

  const show = (i: number, el: HTMLElement) => {
    const r = el.getBoundingClientRect();
    // the box is fixed to the screen so the scrolling message list cannot clip it
    setHover({ i, x: Math.max(8, Math.min(r.left, window.innerWidth - 336)), y: r.bottom + 6 > window.innerHeight - 200 ? Math.max(8, r.top - 190) : r.bottom + 6 });
  };
  const active = hover === null ? null : shown[hover.i];

  return (
    <div className="rounded-xl border border-dashed border-line px-3 py-2" aria-label={t("chat.trace.title")}>
      <div className="mb-1.5 text-[11px] font-medium text-ink-3">{t("chat.trace.title")}</div>
      <ol className="space-y-1 border-s border-dashed border-line ps-3">
        {shown.map((s, i) => (
          <li key={i}>
            <div
              tabIndex={0}
              role="button"
              aria-label={label(s)}
              onMouseEnter={(e) => show(i, e.currentTarget)}
              onFocus={(e) => show(i, e.currentTarget)}
              onMouseLeave={() => setHover(null)}
              onBlur={() => setHover(null)}
              className="inline-flex cursor-help items-center gap-1.5 rounded-full bg-surface-2 px-2.5 py-0.5 text-xs text-ink-2 outline-none hover:bg-line focus-visible:ring-2 focus-visible:ring-[var(--accent)]"
            >
              <span aria-hidden>{STEP_ICON[s.step]}</span>
              <span className={s.ok === false ? "text-bad" : ""}>{label(s)}</span>
              {s.step === "fetch" && <span aria-hidden className={s.ok ? "text-good-ink" : "text-bad"}>{s.ok ? "✓" : "✕"}</span>}
              {s.step === "retry" && <span aria-hidden className="text-warn">!</span>}
            </div>
          </li>
        ))}
      </ol>

      {hover && active && (active.sent || active.got) && (
        <div
          role="tooltip"
          style={{ position: "fixed", left: hover.x, top: hover.y, width: 328 }}
          className="pointer-events-none z-50 rounded-xl border border-line bg-surface p-3 text-xs shadow-xl"
        >
          <div className="mb-2 flex items-center justify-between gap-2">
            <span className="font-semibold text-ink">{label(active)}</span>
            <span className="rounded-full bg-surface-2 px-2 py-0.5 text-[10px] text-ink-3">aiso.v1</span>
          </div>
          <TraceLines title={active.step === "fetch" || active.step === "action" ? t("chat.trace.request") : t("chat.trace.sent")} lines={active.sent} />
          <TraceLines title={active.step === "fetch" || active.step === "action" ? t("chat.trace.result") : t("chat.trace.got")} lines={active.got} />
        </div>
      )}
    </div>
  );
}

/** One half of the protocol summary: a small heading and the lines, left to right as protocol text. */
function TraceLines({ title, lines }: { title: string; lines?: string[] }) {
  if (!lines || lines.length === 0) return null;
  return (
    <div className="mt-2 first:mt-0">
      <div className="mb-0.5 text-[10px] font-medium text-ink-3">{title}</div>
      <pre className="ltr whitespace-pre-wrap break-words rounded-lg bg-surface-2 px-2 py-1.5 font-mono text-[11px] leading-5 text-ink-2">{lines.join("\n")}</pre>
    </div>
  );
}
