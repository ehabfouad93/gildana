import { describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
vi.mock("next/headers", () => ({ cookies: async () => ({ get: () => undefined }) }));
vi.mock("next/navigation", () => ({ redirect: () => { throw new Error("redirect"); } }));
vi.mock("@/auth", () => ({ auth: async () => null }));
vi.mock("@/lib/db", () => ({ db: {} }));
vi.mock("@/lib/settings", () => ({ loadSettings: async () => undefined }));

const { accountWhere, canEdit, canManageClient, canManageTeam, companyWhere } = await import("@/lib/tenancy");
type V = Parameters<typeof canEdit>[0];

const base: V = { id: "u", role: "CLIENT", email: "", name: "", locale: "en", clientId: "c1", isAdmin: false, isGildana: false, companyIds: [], clientIds: [] };

describe("roles", () => {
  it("admins manage every client; account managers only theirs", () => {
    expect(canManageClient({ ...base, role: "ADMIN", isAdmin: true, isGildana: true }, "c9")).toBe(true);
    const am = { ...base, role: "STAFF" as const, isGildana: true, clientIds: ["c1"] };
    expect(canManageClient(am, "c1")).toBe(true);
    expect(canManageClient(am, "c2")).toBe(false);
  });

  it("only owners and Gildana staff of the client manage the team", () => {
    expect(canManageTeam({ ...base, role: "OWNER" })).toBe(true);
    expect(canManageTeam(base)).toBe(false);
    expect(canManageTeam({ ...base, role: "VIEWER" })).toBe(false);
    expect(canManageTeam({ ...base, role: "STAFF", isGildana: true, clientIds: ["c1"] })).toBe(true);
    expect(canManageTeam({ ...base, role: "STAFF", isGildana: true, clientIds: ["c2"] })).toBe(false);
  });

  it("viewers are read-only", () => {
    expect(canEdit({ ...base, role: "VIEWER" })).toBe(false);
    expect(canEdit(base)).toBe(true);
  });

  it("company-limited users never see other companies or unassigned accounts", () => {
    expect(companyWhere({ ...base, companyIds: ["a"] })).toEqual({ id: { in: ["a"] } });
    expect(companyWhere(base)).toEqual({});
    const allowed = ["a", "b"];
    expect(accountWhere("c1", { allowedCompanyIds: allowed }).companyId).toEqual({ in: allowed });
    expect(accountWhere("c1", { allowedCompanyIds: allowed, companyId: "a" }).companyId).toBe("a");
    expect(accountWhere("c1", { allowedCompanyIds: allowed, companyId: "z" }).companyId).toEqual({ in: allowed });
    expect(accountWhere("c1", { allowedCompanyIds: allowed, companyId: "none" }).companyId).toEqual({ in: allowed });
    expect(accountWhere("c1", { companyId: "none" }).companyId).toBeNull();
  });
});
