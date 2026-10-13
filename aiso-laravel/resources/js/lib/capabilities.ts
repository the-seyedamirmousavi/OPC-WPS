import type { Role, User } from "@/lib/types";

/** The roles built into the system: their names are translated; the company's own roles show the name they were given. */
export const BUILTIN_ROLES: Role[] = ["OWNER", "MANAGER", "USER_1", "USER_2", "USER_3", "AGENT"];

/**
 * Whether a person holds a capability. The owner holds all of them. The server enforces every rule; this only decides
 * what the menu and the buttons show.
 */
export function can(user: Pick<User, "role" | "capabilities"> | null | undefined, capability: string): boolean {
  if (!user) return false;

  return user.role === "OWNER" || user.capabilities.includes(capability);
}

/** The name of a role for the screen: translated for the built-in roles, the company's own name otherwise. */
export function roleLabel(t: (key: string) => string, role: Role, name?: string | null): string {
  return BUILTIN_ROLES.includes(role) ? t(`role.${role}`) : (name ?? role);
}
