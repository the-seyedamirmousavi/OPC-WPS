/** Server-only helpers shared by the BFF route handlers. */

export const API_URL = (process.env.AISO_API_URL ?? "http://localhost:8080").replace(/\/$/, "");
export const TOKEN_COOKIE = "aiso_token";

export const cookieOptions = (maxAgeSeconds: number) => ({
  httpOnly: true,
  sameSite: "strict" as const,
  secure: process.env.NODE_ENV === "production" && process.env.AISO_INSECURE_COOKIES !== "1",
  path: "/",
  maxAge: maxAgeSeconds,
});
