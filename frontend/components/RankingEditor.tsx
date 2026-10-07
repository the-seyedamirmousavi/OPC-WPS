"use client";

import { Button } from "@/components/ui";
import { useT } from "@/components/Providers";

export interface RankItem {
  id: string;
  name: string;
  /** shown next to the name, e.g. "new" */
  badge?: string;
  detail?: string;
}

/** Priority list: top = most important. Reorder with the arrow buttons (keyboard friendly). */
export function RankingEditor({ items, onChange }: { items: RankItem[]; onChange: (ids: string[]) => void }) {
  const { t, num } = useT();
  const move = (index: number, delta: number) => {
    const ids = items.map((i) => i.id);
    const target = index + delta;
    if (target < 0 || target >= ids.length) return;
    [ids[index], ids[target]] = [ids[target], ids[index]];
    onChange(ids);
  };
  return (
    <ol className="space-y-2" aria-label={t("prio.list")}>
      {items.map((p, i) => (
        <li key={p.id} className="flex items-center gap-3 rounded-lg border border-line bg-surface px-3 py-2">
          <span className="grid size-7 shrink-0 place-items-center rounded-full bg-accent-soft text-sm font-semibold tabular-nums">
            {num(i + 1, 0)}
          </span>
          <div className="min-w-0 flex-1">
            <div className="truncate font-medium">
              {p.name}
              {p.badge && <span className="ms-2 rounded bg-accent px-1.5 py-0.5 text-xs font-medium text-accent-ink">{p.badge}</span>}
            </div>
            {p.detail && <div className="truncate text-xs text-ink-3">{p.detail}</div>}
          </div>
          <div className="flex shrink-0 gap-1">
            <Button variant="ghost" aria-label={t("prio.moveUp")} title={t("prio.moveUp")} disabled={i === 0} onClick={() => move(i, -1)}>
              ▲
            </Button>
            <Button variant="ghost" aria-label={t("prio.moveDown")} title={t("prio.moveDown")} disabled={i === items.length - 1} onClick={() => move(i, 1)}>
              ▼
            </Button>
          </div>
        </li>
      ))}
    </ol>
  );
}
