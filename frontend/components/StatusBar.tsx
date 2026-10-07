"use client";

import { STATUS_COLOR, StatusIcon } from "@/components/ui";
import { useT } from "@/components/Providers";
import { OP_STATUSES, type StatusCounts } from "@/lib/types";

/** Stacked bar of operations per status. 2px gaps between segments; the legend doubles as the data table. */
export function StatusBar({ counts }: { counts: StatusCounts }) {
  const { t, num } = useT();
  const total = OP_STATUSES.reduce((s, k) => s + (counts[k] ?? 0), 0);
  return (
    <div>
      <div className="flex h-3 w-full gap-0.5 overflow-hidden rounded-full bg-surface-2" role="img" aria-label={t("chart.statusDistribution")}>
        {total > 0 &&
          OP_STATUSES.filter((k) => counts[k] > 0).map((k) => (
            <div
              key={k}
              title={`${t(`status.${k}`)}: ${num(counts[k], 0)}`}
              style={{ width: `${(counts[k] / total) * 100}%`, background: STATUS_COLOR[k] }}
              className="min-w-1"
            />
          ))}
      </div>
      <ul className="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 sm:grid-cols-4">
        {OP_STATUSES.map((k) => (
          <li key={k} className="flex items-center justify-between gap-2 text-xs">
            <span className="flex items-center gap-1.5 text-ink-2">
              <StatusIcon status={k} />
              {t(`status.${k}`)}
            </span>
            <span className="font-semibold tabular-nums text-ink">{num(counts[k] ?? 0, 0)}</span>
          </li>
        ))}
      </ul>
    </div>
  );
}
