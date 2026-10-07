import { cookies } from "next/headers";
import type { NextRequest } from "next/server";
import { API_URL, TOKEN_COOKIE } from "@/lib/server";

/**
 * Same-origin proxy to the Spring Boot API. It adds the Authorization header from the httpOnly cookie, so the
 * token is never readable by page scripts. Request/response bodies are streamed (file uploads and downloads).
 */
async function forward(request: NextRequest, ctx: RouteContext<"/api/[...path]">) {
  const { path } = await ctx.params;
  const jar = await cookies();
  const token = jar.get(TOKEN_COOKIE)?.value;

  const target = `${API_URL}/api/${path.map(encodeURIComponent).join("/")}${request.nextUrl.search}`;
  const headers = new Headers();
  const type = request.headers.get("content-type");
  if (type) headers.set("content-type", type);
  headers.set("accept", request.headers.get("accept") ?? "application/json");
  if (token) headers.set("authorization", `Bearer ${token}`);
  headers.set("accept-language", jar.get("aiso_lang")?.value === "en" ? "en" : "fa");

  const hasBody = !["GET", "HEAD"].includes(request.method);
  let upstream: Response;
  try {
    upstream = await fetch(target, {
      method: request.method,
      headers,
      body: hasBody ? request.body : undefined,
      // required by Node's fetch when streaming a request body
      ...(hasBody ? { duplex: "half" } : {}),
      cache: "no-store",
    } as RequestInit);
  } catch {
    return Response.json({ message: "The server is not reachable" }, { status: 502 });
  }

  if (upstream.status === 401 && token) {
    jar.delete(TOKEN_COOKIE);
  }
  const out = new Headers();
  for (const h of ["content-type", "content-disposition"]) {
    const v = upstream.headers.get(h);
    if (v) out.set(h, v);
  }
  return new Response(upstream.status === 204 ? null : upstream.body, { status: upstream.status, headers: out });
}

export const GET = forward;
export const POST = forward;
export const PUT = forward;
export const PATCH = forward;
export const DELETE = forward;
