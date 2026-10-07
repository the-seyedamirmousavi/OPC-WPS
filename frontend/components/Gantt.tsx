"use client";

import { Empty, STATUS_COLOR } from "@/components/ui";
import { useT } from "@/components/Providers";
import type { Operation } from "@/lib/types";

interface Bar {
  op: Operation;
  start: number;
  end: number;
  lane: number;
}

/**
 * Projected schedule (capacity-constrained plan) per resource. Operations on a resource with capacity > 1 are
 * packed into parallel lanes. The left edge is "now".
 */
export function Gantt({ ops, capacities, onOpen }: { ops: Operation[]; capacities: Record<string, number>; onOpen: (id: string) => void }) {
  const { t, date, hours } = useT();
  const items = ops.filter((o) => o.projectedStart && o.projectedEnd && o.status !== "COMPLETED" && o.status !== "CANCELLED");
  if (items.length === 0) return <Empty>{t("plan.empty")}</Empty>;

  const min = Math.min(...items.map((o) => Date.parse(o.projectedStart!)));
  const max = Math.max(...items.map((o) => Date.parse(o.projectedEnd!)));
  const span = Math.max(max - min, 1);

  const byResource = new Map<string, { name: string; bars: Bar[]; lanes: number }>();
  for (const op of [...items].sort((a, b) => Date.parse(a.projectedStart!) - Date.parse(b.projectedStart!))) {
    const entry = byResource.get(op.resourceId) ?? { name: op.resourceName, bars: [], lanes: 0 };
    const start = Date.parse(op.projectedStart!);
    const end = Date.parse(op.projectedEnd!);
    const laneEnds: number[] = [];
    entry.bars.forEach((b) => (laneEnds[b.lane] = Math.max(laneEnds[b.lane] ?? 0, b.end)));
    let lane = laneEnds.findIndex((e) => e <= start);
    if (lane === -1) lane = laneEnds.length;
    entry.bars.push({ op, start, end, lane });
    entry.lanes = Math.max(entry.lanes, lane + 1);
    byResource.set(op.resourceId, entry);
  }

  const ticks = [0, 0.25, 0.5, 0.75, 1].map((f) => new Date(min + span * f).toISOString());

  return (
    <div className="overflow-x-auto">
      <div className="min-w-[720px]">
        <div className="ms-40 flex justify-between border-b border-line pb-1 text-xs text-ink-3">
          {ticks.map((tk, i) => (
            <span key={i}>{date(tk)}</span>
          ))}
        </div>
        {[...byResource.entries()].map(([rid, r]) => (
          <div key={rid} className="flex border-b border-line py-1.5">
            <div className="w-40 shrink-0 pe-3 text-sm">
              <div className="truncate font-medium" title={r.name}>
                {r.name}
              </div>
              <div className="text-xs text-ink-3">
                {t("res.capacity")}: {capacities[rid] ?? 1}
              </div>
            </div>
            <div className="relative flex-1" style={{ height: r.lanes * 26 }}>
              {r.bars.map((b) => (
                <button
                  key={b.op.id}
                  onClick={() => onOpen(b.op.id)}
                  title={`${b.op.projectName} · ${b.op.id} ${b.op.name}\n${date(b.op.projectedStart)} → ${date(b.op.projectedEnd)}\n${hours(b.op.totalHours)}`}
                  style={{
                    insetInlineStart: `${((b.start - min) / span) * 100}%`,
                    width: `max(${((b.end - b.start) / span) * 100}%, 6px)`,
                    top: b.lane * 26,
                    background: STATUS_COLOR[b.op.status],
                  }}
                  className="absolute h-5 overflow-hidden whitespace-nowrap rounded px-1 text-start text-[11px] font-medium leading-5 text-white outline-offset-1 ring-2 ring-surface"
                >
                  {b.op.id}
                </button>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
