import { Empty, ErrorBox, Loading, Card } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { ImportLog } from "@/lib/types";

/**
 * The import history: every check and every save of an Excel file or of the plan builder. It is shown in the production
 * department's panel, to the owner and to the members whose role may view the panel. Data is entered on the plan builder.
 */
export function ImportHistoryTable({ departmentId }: { departmentId: number }) {
  const { t, date, num } = useT();
  const history = useFetch<ImportLog[]>(`/departments/${departmentId}/imports`);

  if (history.error && !history.data) return <ErrorBox message={history.error} />;
  if (!history.data) return <Loading />;

  return (
    <Card>
      {history.data.length === 0 ? (
        <Empty>{t("common.none")}</Empty>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full min-w-[640px] text-sm">
            <thead>
              <tr className="border-b border-line text-xs text-ink-2">
                <th className="px-2 py-2 text-start font-medium">{t("imp.col.when")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("imp.col.file")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("imp.col.format")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("imp.col.status")}</th>
                <th className="px-2 py-2 text-start font-medium">{t("imp.col.by")}</th>
              </tr>
            </thead>
            <tbody>
              {history.data.map((l) => (
                <tr key={l.id} className="border-b border-line align-top last:border-0">
                  <td className="whitespace-nowrap px-2 py-2 text-xs text-ink-2">{date(l.importedAt)}</td>
                  <td className="px-2 py-2">{l.fileName}</td>
                  <td className="px-2 py-2 text-xs">{l.format}</td>
                  <td className="px-2 py-2">
                    <span className={l.status === "REJECTED" ? "font-medium text-bad" : ""}>{t(`imp.status.${l.status}`)}</span>
                    {l.errorCount > 0 && <span className="ms-1 text-xs text-ink-3">({num(l.errorCount, 0)})</span>}
                    {l.details && (
                      <details className="mt-1">
                        <summary className="cursor-pointer text-xs text-accent">{t("imp.details")}</summary>
                        <pre className="ltr mt-1 max-h-40 overflow-auto whitespace-pre-wrap text-xs text-ink-2">{l.details}</pre>
                      </details>
                    )}
                  </td>
                  <td className="px-2 py-2 text-xs text-ink-3">{l.actorId}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Card>
  );
}
