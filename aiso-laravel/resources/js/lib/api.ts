
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
      window.location.href = "/login";
    }
    const message =
      data && typeof data === "object" && "message" in data
        ? String((data as { message: unknown }).message)
        : `Request failed (${res.status})`;
    throw new ApiError(message, res.status);
  }
  return data;
}

/** Cookie value by name (the browser keeps the session and the CSRF token in cookies). */
function cookie(name: string): string | null {
  if (typeof document === "undefined") return null;
  const hit = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return hit ? decodeURIComponent(hit.slice(name.length + 1)) : null;
}

/** Headers every request carries: JSON in and out, the UI language, and the CSRF token for changing requests. */
function baseHeaders(method: string, json: boolean): Record<string, string> {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Accept-Language": cookie("aiso_lang") === "en" ? "en" : "fa",
  };
  if (json) headers["Content-Type"] = "application/json";
  if (method !== "GET" && method !== "HEAD") {
    const token = cookie("XSRF-TOKEN");
    if (token) headers["X-XSRF-TOKEN"] = token;
  }
  return headers;
}

export async function api<T = unknown>(path: string, init?: { method?: string; body?: unknown }): Promise<T> {
  const method = init?.method ?? "GET";
  const res = await fetch(`/api${path}`, {
    method,
    credentials: "same-origin",
    headers: baseHeaders(method, init?.body !== undefined),
    body: init?.body !== undefined ? JSON.stringify(init.body) : undefined,
  });
  return (await parse(res)) as T;
}

export async function upload<T = unknown>(path: string, form: FormData): Promise<T> {
  const res = await fetch(`/api${path}`, {
    method: "POST",
    credentials: "same-origin",
    headers: baseHeaders("POST", false),
    body: form,
  });
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
