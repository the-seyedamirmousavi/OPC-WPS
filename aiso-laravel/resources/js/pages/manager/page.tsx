import { Navigate } from "react-router-dom";
import { Card, Empty, ErrorBox, Loading } from "@/components/ui";
import { useSession, useT } from "@/components/Providers";
import { useFetch } from "@/lib/api";
import type { DepartmentMenuItem } from "@/lib/types";

/**
 * The manager's home. The manager dashboard lives in the panel of the department the manager heads, so this opens
 * that panel's dashboard tab.
 */
export default function ManagerHome() {
  const { t } = useT();
  const { user } = useSession();
  const menu = useFetch<DepartmentMenuItem[]>("/departments/menu");

  // the owner's home is the dashboard
  if (user?.role === "OWNER") return <Navigate to="/owner" replace />;
  if (menu.loading) return <Loading />;
  if (menu.error || !menu.data) return <ErrorBox message={menu.error ?? t("common.error")} />;

  const head = menu.data.find((d) => d.headOf);
  if (head) return <Navigate to={`/departments/${head.id}?tab=manager`} replace />;

  return (
    <Card>
      <Empty>{t("mgr.home.none")}</Empty>
    </Card>
  );
}
