import { describe, expect, it, vi } from "vitest";

vi.mock("server-only", () => ({}));
vi.mock("next/headers", () => ({ cookies: async () => ({ get: () => undefined }) }));
vi.mock("next/navigation", () => ({ redirect: () => { throw new Error("redirect"); } }));
vi.mock("@/auth", () => ({ auth: async () => null }));
vi.mock("@/lib/db", () => ({ db: {} }));
vi.mock("@/lib/settings", () => ({ loadSettings: async () => undefined }));

const { accountWhere, canEdit, canManageClient, canManageTeam, canUse, companyWhere } = await import("@/lib/tenancy");
const { homePath } = await import("@/lib/modules");
type V = Parameters<typeof canEdit>[0];

const base: V = { id: "u", role: "CLIENT", email: "", name: "", locale: "en", clientId: "c1", isAdmin: false, isGildana: false, access: null, companyIds: [], modules: [], clientIds: [] };

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

  it("limited users see only granted companies and single ad accounts", () => {
    const access = { companyIds: ["a"], accountIds: ["x1"] };
    expect(companyWhere({ ...base, access, companyIds: ["a", "b"] })).toEqual({ id: { in: ["a", "b"] } });
    // An account-only grant whose accounts are all unassigned sees no companies — never "all".
    expect(companyWhere({ ...base, access: { companyIds: [], accountIds: ["x1"] }, companyIds: [] })).toEqual({ id: { in: [] } });
    expect(companyWhere(base)).toEqual({});
    const grant = { OR: [{ companyId: { in: ["a"] } }, { id: { in: ["x1"] } }] };
    expect(accountWhere("c1", { access }).AND).toEqual([grant]);
    expect(accountWhere("c1", { access, companyId: "b" }).AND).toEqual([grant, { companyId: "b" }]);
    expect(accountWhere("c1", { companyId: "none" }).AND).toEqual([{ companyId: null }]);
    expect(accountWhere("c1", {}).AND).toBeUndefined();
  });

  it("sections: empty means all; otherwise only the ticked ones", () => {
    expect(canUse(base, "sites")).toBe(true);
    const v = { ...base, modules: ["spend", "reports"] };
    expect(canUse(v, "spend")).toBe(true);
    expect(canUse(v, "sites")).toBe(false);
    expect(homePath(v.modules)).toBe("/spend");
    expect(homePath([])).toBe("/overview");
  });
});
