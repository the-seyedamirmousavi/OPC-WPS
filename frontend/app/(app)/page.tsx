"use client";

import { useRouter } from "next/navigation";
import { useEffect } from "react";
import { Loading } from "@/components/ui";
import { homeFor } from "@/components/Shell";
import { useSession } from "@/components/Providers";

export default function Home() {
  const { user } = useSession();
  const router = useRouter();
  useEffect(() => {
    if (user) router.replace(homeFor(user.role));
  }, [user, router]);
  return <Loading />;
}
