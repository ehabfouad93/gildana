import type { User } from "@prisma/client";
import type { ManagedUser } from "@/components/user-manager";

/** Users as the team table shows them; access is companies (client users) or clients (account managers). */
export function toManagedUsers(users: User[], access: "company" | "client"): ManagedUser[] {
  return users.map((u) => ({
    id: u.id, email: u.email, name: u.name, role: u.role, active: u.active,
    lastLogin: u.lastLoginAt ? u.lastLoginAt.toISOString().slice(0, 16).replace("T", " ") : null,
    access: access === "company" ? u.companyIds : u.clientIds,
  }));
}
