import { cookies } from "next/headers";
import { API_URL, TOKEN_COOKIE, cookieOptions } from "@/lib/server";

/** POST = log in (stores the JWT in an httpOnly cookie), GET = current user, DELETE = log out. */

export async function POST(request: Request) {
  const body = await request.text();
  let upstream: Response;
  try {
    upstream = await fetch(`${API_URL}/api/auth/login`, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "Accept-Language": (await cookies()).get("aiso_lang")?.value === "en" ? "en" : "fa",
      },
      body,
    });
  } catch {
    return Response.json({ message: "The server is not reachable" }, { status: 502 });
  }
  const data = await upstream.json().catch(() => ({}));
  if (!upstream.ok) {
    return Response.json(data, { status: upstream.status });
  }
  const jar = await cookies();
  jar.set(TOKEN_COOKIE, data.token, cookieOptions(data.expiresInSeconds ?? 28800));
  return Response.json(data.user);
}

export async function GET() {
  const jar = await cookies();
  const token = jar.get(TOKEN_COOKIE)?.value;
  if (!token) {
    return Response.json({ message: "Not signed in" }, { status: 401 });
  }
  try {
    const upstream = await fetch(`${API_URL}/api/auth/me`, {
      headers: { Authorization: `Bearer ${token}` },
      cache: "no-store",
    });
    if (upstream.status === 401) {
      jar.delete(TOKEN_COOKIE);
    }
    return new Response(await upstream.text(), {
      status: upstream.status,
      headers: { "Content-Type": "application/json" },
    });
  } catch {
    return Response.json({ message: "The server is not reachable" }, { status: 502 });
  }
}

export async function DELETE() {
  const jar = await cookies();
  jar.delete(TOKEN_COOKIE);
  return new Response(null, { status: 204 });
}
