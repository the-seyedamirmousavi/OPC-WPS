/** A small label next to a name, e.g. the «ایجنت» label that marks an AI agent. */
export function Badge({ children, tone }: { children: React.ReactNode; tone?: "agent" }) {
  return (
    <span
      className={`inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ${
        tone === "agent" ? "bg-accent-soft text-ink" : "bg-surface-2 text-ink-2"
      }`}
    >
      {children}
    </span>
  );
}
