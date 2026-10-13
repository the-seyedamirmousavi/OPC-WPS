import { Link } from "react-router-dom";

export interface Crumb {
  label: string;
  /** omitted for the page the person is on */
  to?: string;
}

/** Where the person is, e.g. Departments / Production / Operations. The last crumb is the current page. */
export function Breadcrumbs({ items }: { items: Crumb[] }) {
  return (
    <nav aria-label="Breadcrumb" className="mb-2 flex flex-wrap items-center gap-1 text-xs text-ink-3">
      {items.map((c, i) => (
        <span key={`${i}-${c.label}`} className="flex items-center gap-1">
          {i > 0 && <span aria-hidden>/</span>}
          {c.to ? (
            <Link to={c.to} className="hover:text-ink hover:underline">
              {c.label}
            </Link>
          ) : (
            <span aria-current={i === items.length - 1 ? "page" : undefined} className="text-ink-2">
              {c.label}
            </span>
          )}
        </span>
      ))}
    </nav>
  );
}
