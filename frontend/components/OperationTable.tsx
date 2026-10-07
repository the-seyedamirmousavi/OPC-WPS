"use client";

import { Empty, Mono, ProgressBar, StatusBadge } from "@/components/ui";
import { useT } from "@/components/Providers";
import type { Operation } from "@/lib/types";

export function OperationTable({
  ops,
  onOpen,
  showAssignee = true,
}: {
  ops: Operation[];
  onOpen: (id: string) => void;
  showAssignee?: boolean;
}) {
  const { t, hours, date } = useT();
  if (ops.length === 0) return <Empty>{t("common.none")}</Empty>;
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] text-sm">
        <thead>
          <tr className="border-b border-line text-start text-xs text-ink-2">
            <th className="px-2 py-2 text-start font-medium">{t("op.id")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.name")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.project")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.resource")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.status")}</th>
            {showAssignee && <th className="px-2 py-2 text-start font-medium">{t("op.assignee")}</th>}
            <th className="px-2 py-2 text-start font-medium">{t("op.hours")}</th>
            <th className="w-28 px-2 py-2 text-start font-medium">{t("op.progress")}</th>
            <th className="px-2 py-2 text-start font-medium">{t("op.due")}</th>
          </tr>
        </thead>
        <tbody>
          {ops.map((o) => (
            <tr
              key={o.id}
              tabIndex={0}
              onClick={() => onOpen(o.id)}
              onKeyDown={(e) => (e.key === "Enter" || e.key === " ") && onOpen(o.id)}
              className="cursor-pointer border-b border-line last:border-0 hover:bg-surface-2"
            >
              <td className="px-2 py-2">
                <Mono>{o.id}</Mono>
              </td>
              <td className="max-w-64 px-2 py-2">
                <div className="truncate" title={o.name}>
                  {o.name}
                </div>
                {o.status === "NOT_READY" && o.waitingFor.length > 0 && (
                  <div className="truncate text-xs text-ink-3">
                    {t("op.waitingFor")}: {o.waitingFor.map((w) => w.id).join(", ")}
                  </div>
                )}
                {o.status === "BLOCKED" && o.blockReason && <div className="truncate text-xs text-bad">{o.blockReason}</div>}
              </td>
              <td className="px-2 py-2 text-xs text-ink-2">{o.projectName}</td>
              <td className="px-2 py-2">{o.resourceName}</td>
              <td className="px-2 py-2">
                <StatusBadge status={o.status} />
                {o.delayed && (
                  <span className="ms-2 rounded bg-bad-soft px-1.5 py-0.5 text-xs font-medium text-bad" title={hours(o.delayHours)}>
                    ⚠ {t("op.delayed")}
                  </span>
                )}
              </td>
              {showAssignee && <td className="px-2 py-2">{o.assignedUserName ?? <span className="text-ink-3">—</span>}</td>}
              <td className="px-2 py-2 tabular-nums">{hours(o.totalHours)}</td>
              <td className="px-2 py-2">
                {o.status === "IN_PROGRESS" || o.status === "COMPLETED" ? <ProgressBar value={o.progressPercent} label={t("op.progress")} /> : <span className="text-ink-3">—</span>}
              </td>
              <td className="whitespace-nowrap px-2 py-2 text-xs text-ink-2">{date(o.plannedEnd ?? o.projectedEnd)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
