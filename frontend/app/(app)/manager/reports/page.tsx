"use client";

import { Banner, Card, Empty, ErrorBox, Loading, Mono, PageHeader } from "@/components/ui";
import { useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { Notification, UserPerformance } from "@/lib/types";

export default function ReportsPage() {
  const { t, num, hours, percent, date, lang } = useT();
  const perf = useFetch<UserPerformance[]>("/reports/users", 30000);
  const failed = useFetch<Notification[]>("/notifications/failed", 60000);

  if (perf.loading) return <Loading />;
  if (perf.error || !perf.data) return <ErrorBox message={perf.error ?? t("common.error")} />;

  return (
    <>
      <PageHeader
        title={t("rep.title")}
        sub={t("rep.sub")}
        actions={
          <a href={`/api/reports/export?lang=${lang}`} download className="rounded-lg bg-accent px-3.5 py-2 text-sm font-medium text-accent-ink hover:brightness-110">
            {t("rep.export")}
          </a>
        }
      />

      <Card title={t("rep.userPerformance")}>
        {perf.data.length === 0 ? (
          <Empty>{t("common.none")}</Empty>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-sm">
              <thead>
                <tr className="border-b border-line text-xs text-ink-2">
                  <th className="px-2 py-2 text-start font-medium">{t("user.name")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("user.assigned")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("status.IN_PROGRESS")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("status.BLOCKED")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("status.COMPLETED")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("rep.completedHours")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("rep.onTime")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("rep.actualVsPlanned")}</th>
                  <th className="px-2 py-2 text-start font-medium">{t("rep.blockReports")}</th>
                </tr>
              </thead>
              <tbody>
                {perf.data.map((u) => (
                  <tr key={u.userId} className="border-b border-line last:border-0">
                    <td className="px-2 py-2">
                      {u.name} <Mono>{u.userId}</Mono>
                    </td>
                    <td className="px-2 py-2 tabular-nums">{num(u.assigned, 0)}</td>
                    <td className="px-2 py-2 tabular-nums">{num(u.inProgress, 0)}</td>
                    <td className="px-2 py-2 tabular-nums">{num(u.blocked, 0)}</td>
                    <td className="px-2 py-2 tabular-nums">{num(u.completed, 0)}</td>
                    <td className="px-2 py-2 tabular-nums">{hours(u.completedHours)}</td>
                    <td className="px-2 py-2 tabular-nums">{u.onTimeRate === null ? "—" : percent(u.onTimeRate * 100)}</td>
                    <td className="px-2 py-2 tabular-nums">{u.actualToPlanned === null ? "—" : num(u.actualToPlanned, 2)}</td>
                    <td className="px-2 py-2 tabular-nums">{num(u.blockReports, 0)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
        <div className="mt-4 space-y-1 rounded-lg bg-surface-2 p-3 text-xs text-ink-2">
          <p className="font-medium text-ink">{t("rep.formulas")}</p>
          <p>{t("rep.fProgress")}</p>
          <p>{t("rep.fDelay")}</p>
          <p>{t("rep.fOnTime")}</p>
          <p>{t("rep.fActual")}</p>
        </div>
      </Card>

      <Card title={t("rep.failedMessages")} className="mt-4">
        {failed.data && failed.data.length > 0 ? (
          <>
            <Banner tone="warn">{t("rep.failedHint")}</Banner>
            <ul className="mt-3 divide-y divide-line text-sm">
              {failed.data.map((n) => (
                <li key={n.id} className="py-2">
                  <div>{n.message}</div>
                  <div className="text-xs text-bad">{n.deliveryError}</div>
                  <div className="text-xs text-ink-3">{date(n.createdAt)}</div>
                </li>
              ))}
            </ul>
          </>
        ) : (
          <Empty>{t("rep.noFailed")}</Empty>
        )}
      </Card>
    </>
  );
}
