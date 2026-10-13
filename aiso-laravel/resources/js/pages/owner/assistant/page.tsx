import { useState } from "react";
import { Banner, Card, Empty, ErrorBox, Loading, Mono, PageHeader } from "@/components/ui";
import { useT } from "@/components/Providers";
import { api, useFetch } from "@/lib/api";

interface RunRow {
  id: number;
  createdAt: string | null;
  userName: string | null;
  question: string;
  status: string;
  rounds: number;
  tokensIn: number;
  tokensOut: number;
  durationMs: number;
  error: string | null;
}

interface RunStep {
  id: number;
  stage: string;
  department: string | null;
  input: string | null;
  output: string | null;
  tokensIn: number;
  tokensOut: number;
  durationMs: number;
  error: string | null;
  createdAt: string | null;
}

interface RunDetail extends RunRow {
  steps: RunStep[];
}

/** Pretty-prints JSON text; anything that is not JSON is shown as it is. */
function pretty(text: string | null): string {
  if (text === null || text === "") return "";
  try {
    return JSON.stringify(JSON.parse(text), null, 2);
  } catch {
    return text;
  }
}

export default function AssistantLogPage() {
  const { t, date, num } = useT();
  const runs = useFetch<RunRow[]>("/ai/runs?limit=100", 15000);
  const prompt = useFetch<{ text: string; note: string }>("/ai/prompt");
  const [selected, setSelected] = useState<number | null>(null);
  const [detail, setDetail] = useState<RunDetail | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [loadingDetail, setLoadingDetail] = useState(false);

  const open = async (id: number) => {
    setSelected(id);
    setLoadingDetail(true);
    setDetailError(null);
    try {
      setDetail(await api<RunDetail>(`/ai/runs/${id}`));
    } catch (e) {
      setDetail(null);
      setDetailError(e instanceof Error ? e.message : String(e));
    } finally {
      setLoadingDetail(false);
    }
  };

  if (runs.loading) return <Loading />;
  if (runs.error || !runs.data) return <ErrorBox message={runs.error ?? t("common.error")} />;

  return (
    <>
      <PageHeader title={t("assistantLog.title")} sub={t("assistantLog.sub")} />

      <Card title={t("assistantLog.instructions")} className="mb-4">
        <p className="mb-2 text-xs text-ink-3">{t("assistantLog.instructionsNote")}</p>
        {prompt.loading ? (
          <Loading />
        ) : prompt.data ? (
          <details>
            <summary className="cursor-pointer text-sm text-accent">{t("assistantLog.showInstructions")}</summary>
            <pre className="ltr mt-2 max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-surface-2 p-3 text-xs leading-6">{prompt.data.text}</pre>
          </details>
        ) : (
          <Banner tone="error">{prompt.error ?? t("common.error")}</Banner>
        )}
      </Card>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <Card title={t("assistantLog.runs")}>
          {runs.data.length === 0 ? (
            <Empty>{t("assistantLog.none")}</Empty>
          ) : (
            <ul className="max-h-[70vh] divide-y divide-line overflow-y-auto text-sm">
              {runs.data.map((r) => (
                <li key={r.id}>
                  <button
                    type="button"
                    onClick={() => open(r.id)}
                    aria-current={selected === r.id ? "true" : undefined}
                    className={`block w-full px-2 py-2 text-start hover:bg-surface-2 ${selected === r.id ? "bg-accent-soft" : ""}`}
                  >
                    <div className="flex justify-between gap-2 text-xs text-ink-3">
                      <span>{r.createdAt ? date(r.createdAt) : ""}</span>
                      <span className={r.status === "FAILED" ? "text-bad" : ""}>{t(`assistantLog.status.${r.status}`)}</span>
                    </div>
                    <div className="truncate font-medium">{r.question}</div>
                    <div className="text-xs text-ink-3">
                      {r.userName ?? "—"} · {num(r.rounds, 0)} {t("assistantLog.rounds")} · {num(r.tokensIn + r.tokensOut, 0)} {t("assistantLog.tokens")}
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card title={t("assistantLog.detail")}>
          {selected === null ? (
            <Empty>{t("assistantLog.pick")}</Empty>
          ) : loadingDetail ? (
            <Loading />
          ) : detailError ? (
            <ErrorBox message={detailError} />
          ) : detail ? (
            <div className="space-y-4">
              <div className="space-y-1 text-sm">
                <div className="font-medium">{detail.question}</div>
                <div className="text-xs text-ink-3">
                  #<Mono>{detail.id}</Mono> · {detail.userName ?? "—"} · {detail.createdAt ? date(detail.createdAt) : ""} ·{" "}
                  {num(detail.durationMs, 0)} ms · {num(detail.tokensIn, 0)} / {num(detail.tokensOut, 0)} {t("assistantLog.tokens")}
                </div>
                {detail.error && <Banner tone="error">{detail.error}</Banner>}
              </div>

              {detail.steps.length === 0 && <Empty>{t("assistantLog.noSteps")}</Empty>}
              {detail.steps.map((s, i) => (
                <details key={s.id} open={i === 0} className="rounded-lg border border-line p-3">
                  <summary className="cursor-pointer text-sm font-medium">
                    {i + 1}. {t(`assistantLog.stage.${s.stage}`)}
                    {s.department && <span className="text-ink-3"> · {s.department}</span>}
                    <span className="ms-2 text-xs text-ink-3">
                      {num(s.durationMs, 0)} ms · {num(s.tokensIn, 0)} / {num(s.tokensOut, 0)}
                    </span>
                  </summary>
                  {s.error && <div className="mt-2"><Banner tone="error">{s.error}</Banner></div>}
                  <div className="mt-3 space-y-3">
                    <div>
                      <div className="mb-1 text-xs font-medium text-ink-2">{t("assistantLog.input")}</div>
                      <pre className="ltr max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-surface-2 p-3 text-xs leading-5">
                        {pretty(s.input) || "—"}
                      </pre>
                    </div>
                    <div>
                      <div className="mb-1 text-xs font-medium text-ink-2">{t("assistantLog.output")}</div>
                      <pre className="ltr max-h-96 overflow-auto whitespace-pre-wrap rounded-lg bg-surface-2 p-3 text-xs leading-5">
                        {pretty(s.output) || "—"}
                      </pre>
                    </div>
                  </div>
                </details>
              ))}
            </div>
          ) : null}
        </Card>
      </div>
    </>
  );
}
