import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * Cheap gate: pages need a session cookie, otherwise go to /login. The cookie only proves a login happened -
 * the backend still authorises every API call (role checks, deactivated users, token expiry).
 */
export function proxy(request: NextRequest) {
  const hasSession = request.cookies.has("aiso_token");
  const { pathname } = request.nextUrl;
  if (!hasSession && pathname !== "/login") {
    return NextResponse.redirect(new URL("/login", request.url));
  }
  if (hasSession && pathname === "/login") {
    return NextResponse.redirect(new URL("/", request.url));
  }
  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!api|_next/static|_next/image|favicon.ico).*)"],
};
