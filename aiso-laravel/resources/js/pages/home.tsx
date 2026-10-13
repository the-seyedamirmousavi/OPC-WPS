
import { useEffect } from "react";
import { useNavigate } from "react-router-dom";
import { Loading } from "@/components/ui";
import { homeFor } from "@/components/Shell";
import { useSession } from "@/components/Providers";

export default function Home() {
  const { user } = useSession();
  const navigate = useNavigate();
  useEffect(() => {
    if (user) navigate(homeFor(user), { replace: true });
  }, [user, navigate]);
  return <Loading />;
}
