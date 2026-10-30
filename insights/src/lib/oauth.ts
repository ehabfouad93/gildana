import "server-only";
import { createHmac, timingSafeEqual } from "node:crypto";

/**
 * OAuth `state`: clientId + platform + nonce, HMAC-signed with AUTH_SECRET
 * and also kept in an httpOnly cookie, so a callback can only complete a
 * flow this browser started for this workspace.
 */
const secret = () => process.env.AUTH_SECRET ?? "";

export function signState(payload: { clientId: string; platform: string; nonce: string }): string {
  const body = Buffer.from(JSON.stringify(payload)).toString("base64url");
  const mac = createHmac("sha256", secret()).update(body).digest("base64url");
  return `${body}.${mac}`;
}

export function verifyState(state: string): { clientId: string; platform: string; nonce: string } | null {
  const [body, mac] = state.split(".");
  if (!body || !mac) return null;
  const expect = createHmac("sha256", secret()).update(body).digest("base64url");
  if (mac.length !== expect.length || !timingSafeEqual(Buffer.from(mac), Buffer.from(expect))) return null;
  try { return JSON.parse(Buffer.from(body, "base64url").toString()); } catch { return null; }
}

export const redirectUri = (slug: string) => `${process.env.APP_URL ?? "http://localhost:3000"}/api/oauth/${slug}/callback`;
