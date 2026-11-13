import { createHash } from "node:crypto";
import { metaPost } from "./write";

/**
 * Customer lists → Meta custom audiences. Emails and phone numbers are
 * normalised and SHA-256 hashed on this server; only the hashes are sent.
 * The parsing and hashing are pure and unit-tested.
 */

const sha = (s: string) => createHash("sha256").update(s).digest("hex");

export const normalEmail = (s: string) => { const e = s.trim().toLowerCase(); return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e) ? e : null; };

/** Digits only, with the country code. A local number starting with 0 gets `defaultCountryCode` (e.g. "20" for Egypt). */
export function normalPhone(s: string, defaultCountryCode = ""): string | null {
  let d = s.replace(/[^\d+]/g, "");
  if (d.startsWith("+")) d = d.slice(1);
  else if (d.startsWith("00")) d = d.slice(2);
  else if (d.startsWith("0") && defaultCountryCode) d = defaultCountryCode + d.slice(1);
  d = d.replace(/\D/g, "");
  return d.length >= 8 && d.length <= 15 ? d : null;
}

/** Reads emails and phone numbers out of pasted or uploaded text (CSV, one per line, any column order). */
export function parseCustomerList(text: string, defaultCountryCode = ""): { emails: string[]; phones: string[] } {
  const emails = new Set<string>(), phones = new Set<string>();
  for (const cell of text.split(/[\n\r,;\t]+/)) {
    const c = cell.trim().replace(/^["']|["']$/g, "");
    if (!c) continue;
    if (c.includes("@")) { const e = normalEmail(c); if (e) emails.add(e); }
    else if (/^[+\d][\d\s().-]{6,}$/.test(c)) { const p = normalPhone(c, defaultCountryCode); if (p) phones.add(p); }
  }
  return { emails: [...emails], phones: [...phones] };
}

export const hashAll = (values: string[]) => values.map(sha);

/** Creates the audience and uploads the hashes in batches. Returns the audience id. */
export async function uploadCustomerList(token: string, accountExternalId: string, name: string, list: { emails: string[]; phones: string[] }): Promise<string> {
  const act = accountExternalId.startsWith("act_") ? accountExternalId : `act_${accountExternalId}`;
  const created = await metaPost(`${act}/customaudiences`, token, { name, subtype: "CUSTOM", customer_file_source: "USER_PROVIDED_ONLY", description: "Uploaded from Gildana Insights" });
  const id = String(created.id);
  const send = async (schema: string, hashes: string[]) => {
    for (let i = 0; i < hashes.length; i += 10_000) await metaPost(`${id}/users`, token, { payload: { schema, data: hashes.slice(i, i + 10_000) } });
  };
  if (list.emails.length) await send("EMAIL_SHA256", hashAll(list.emails));
  if (list.phones.length) await send("PHONE_SHA256", hashAll(list.phones));
  return id;
}
