import "server-only";
import { createHash } from "node:crypto";
import type { z } from "zod";
import { db } from "@/lib/db";
import { randomToken } from "@/lib/crypto";
import { canEdit, canFeature, canUse, getViewer } from "@/lib/tenancy";
import type { Access } from "@/modules/customers/query";

/**
 * /api/v1 plumbing: who is calling (a workspace API key, or the signed-in user),
 * what they may do, a per-caller rate limit and one JSON error shape.
 */

export const SCOPES = [
  { key: "read", label: "Read customers, timelines and scores" },
  { key: "write", label: "Send events and identify customers" },
  { key: "pii", label: "See phone numbers and emails in responses" },
] as const;
export type Scope = (typeof SCOPES)[number]["key"];

export type ApiCtx = Access & { userId: string | null; keyId: string | null; scopes: Scope[] };

const sha = (s: string) => createHash("sha256").update(s).digest("hex");

/** A new key: shown once, stored only as a hash. */
export function newKey(): { key: string; prefix: string; hash: string } {
  const key = `gk_${randomToken(30)}`;
  return { key, prefix: key.slice(0, 10), hash: sha(key) };
}

export function apiError(status: number, code: string, message: string, extra: Record<string, unknown> = {}) {
  return Response.json({ error: { code, message, ...extra } }, { status, headers: { "cache-control": "no-store" } });
}

const hits = new Map<string, { n: number; reset: number }>();
const LIMIT = 600; // calls per minute per key or user
function limited(id: string): boolean {
  const now = Date.now();
  const h = hits.get(id);
  if (!h || h.reset < now) { hits.set(id, { n: 1, reset: now + 60_000 }); if (hits.size > 20_000) hits.clear(); return false; }
  return ++h.n > LIMIT;
}

/** The caller, or an error response. `need` is the scope this endpoint requires. */
export async function apiContext(req: Request, need: Scope): Promise<ApiCtx | Response> {
  const bearer = (req.headers.get("authorization") ?? "").match(/^Bearer\s+(gk_[A-Za-z0-9_-]{20,})$/)?.[1];
  if (bearer) {
    const k = await db.apiKey.findUnique({ where: { hash: sha(bearer) } });
    if (!k || k.revokedAt) return apiError(401, "invalid_key", "This API key is not valid or was revoked.");
    if (limited(`k:${k.id}`)) return apiError(429, "rate_limited", `More than ${LIMIT} calls a minute.`);
    const scopes = k.scopes as Scope[];
    if (!scopes.includes(need)) return apiError(403, "missing_scope", `This key needs the “${need}” scope.`);
    if (!k.lastUsedAt || Date.now() - k.lastUsedAt.getTime() > 60_000) await db.apiKey.update({ where: { id: k.id }, data: { lastUsedAt: new Date() } }).catch(() => {});
    return { clientId: k.clientId, companyIds: null, pii: scopes.includes("pii"), userId: null, keyId: k.id, scopes };
  }
  const v = await getViewer();
  if (!v?.clientId) return apiError(401, "unauthenticated", "Send `Authorization: Bearer <API key>` or sign in.");
  if (!canUse(v, "customers")) return apiError(403, "forbidden", "Customers isn't one of your sections.");
  if (need === "write" && !canEdit(v)) return apiError(403, "read_only", "Your role is read-only.");
  if (limited(`u:${v.id}`)) return apiError(429, "rate_limited", `More than ${LIMIT} calls a minute.`);
  const pii = canFeature(v, "customers.pii");
  return { clientId: v.clientId, companyIds: v.access ? v.companyIds : null, pii, userId: v.id, keyId: null, scopes: ["read", ...(canEdit(v) ? (["write"] as Scope[]) : []), ...(pii ? (["pii"] as Scope[]) : [])] };
}

/** Parses a JSON body against a schema, or answers 400 with the problems. */
export async function body<T extends z.ZodType>(req: Request, schema: T): Promise<z.infer<T> | Response> {
  let raw: unknown;
  try { raw = await req.json(); } catch { return apiError(400, "invalid_json", "The body is not valid JSON."); }
  const r = schema.safeParse(raw);
  if (!r.success) return apiError(400, "invalid_body", "The body doesn't match the schema.", { issues: r.error.issues.slice(0, 20).map((i) => ({ path: i.path.join("."), message: i.message })) });
  return r.data;
}

export async function audit(ctx: Pick<ApiCtx, "clientId" | "userId" | "keyId">, action: string, target = "", detail: Record<string, unknown> = {}) {
  await db.auditLog.create({ data: { clientId: ctx.clientId, userId: ctx.userId, apiKeyId: ctx.keyId, action, target, detail: JSON.parse(JSON.stringify(detail)) } }).catch(() => {});
}

export const ok = (data: unknown, status = 200) => Response.json(data, { status, headers: { "cache-control": "no-store" } });
