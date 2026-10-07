"use client";

import { useCallback, useEffect, useRef, useState } from "react";

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

async function parse(res: Response) {
  const text = await res.text();
  let data: unknown = null;
  if (text) {
    try {
      data = JSON.parse(text);
    } catch {
      data = text;
    }
  }
  if (!res.ok) {
    if (res.status === 401 && typeof window !== "undefined" && window.location.pathname !== "/login") {
      // outside React: a hard navigation also drops all client state
      window.location.href = "/login"; // eslint-disable-line @next/next/no-location-assign-relative-destination
    }
    const message =
      data && typeof data === "object" && "message" in data
        ? String((data as { message: unknown }).message)
        : `Request failed (${res.status})`;
    throw new ApiError(message, res.status);
  }
  return data;
}

export async function api<T = unknown>(path: string, init?: { method?: string; body?: unknown }): Promise<T> {
  const res = await fetch(`/api${path}`, {
    method: init?.method ?? "GET",
    headers: init?.body !== undefined ? { "Content-Type": "application/json" } : undefined,
    body: init?.body !== undefined ? JSON.stringify(init.body) : undefined,
    cache: "no-store",
  });
  return (await parse(res)) as T;
}

export async function upload<T = unknown>(path: string, form: FormData): Promise<T> {
  const res = await fetch(`/api${path}`, { method: "POST", body: form, cache: "no-store" });
  return (await parse(res)) as T;
}

/** Simple polling fetch hook. `path = null` pauses it. */
export function useFetch<T>(path: string | null, refreshMs = 0) {
  const [data, setData] = useState<T | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(path !== null);
  const alive = useRef(true);

  const load = useCallback(async () => {
    if (path === null) return;
    try {
      const result = await api<T>(path);
      if (!alive.current) return;
      setData(result);
      setError(null);
    } catch (e) {
      if (!alive.current) return;
      setError(e instanceof Error ? e.message : String(e));
    } finally {
      if (alive.current) setLoading(false);
    }
  }, [path]);

  useEffect(() => {
    alive.current = true;
    const first = setTimeout(load, 0);
    const timer = refreshMs > 0 ? setInterval(load, refreshMs) : undefined;
    return () => {
      alive.current = false;
      clearTimeout(first);
      if (timer) clearInterval(timer);
    };
  }, [load, refreshMs]);

  return { data, error, loading, reload: load };
}
