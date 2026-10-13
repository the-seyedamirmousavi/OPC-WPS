import { Link, Navigate } from "react-router-dom";
import { Banner, ErrorBox, Loading } from "@/components/ui";
import { useSession, useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { DepartmentMenuItem } from "@/lib/types";

/**
 * Guards a manager page. A manager uses it where it is. The owner does not work in the page on its own: the owner is
 * sent to the same page inside the Production workspace, so there is one place to find it and the workspace shows which
 * department the owner is in. Anyone else never reaches here (the shell and the API refuse them).
 */
export function ManagerArea({ section, children }: { section: string; children: React.ReactNode }) {
  const { t } = useT();
  const { user } = useSession();
  const menu = useFetch<DepartmentMenuItem[]>(user?.role === "OWNER" ? "/departments/menu" : null);

  if (user?.role !== "OWNER") return <>{children}</>;
  if (menu.loading) return <Loading />;
  if (menu.error || !menu.data) return <ErrorBox message={menu.error ?? t("common.error")} />;

  const production = menu.data.find((d) => d.code === "PRODUCTION");
  if (production) return <Navigate to={`/departments/${production.id}?tab=${section}`} replace />;

  return (
    <Banner tone="warn">
      {t("manager.area.none")}{" "}
      <Link to="/owner" className="font-medium text-accent underline">
        {t("manager.area.open")}
      </Link>
    </Banner>
  );
}
