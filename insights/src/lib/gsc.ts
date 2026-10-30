import { decrypt } from "@/lib/crypto";
import { fetchJson } from "@/lib/http";
import type { Range } from "@/lib/dates";

/** Google Search Console (Search Analytics API) for a site's connected property. */
const clientId = () => process.env.GSC_CLIENT_ID || process.env.GOOGLE_ADS_CLIENT_ID || "";
const clientSecret = () => process.env.GSC_CLIENT_SECRET || process.env.GOOGLE_ADS_CLIENT_SECRET || "";
export const gscConfigured = () => !!(clientId() && clientSecret());
export const GSC_SCOPE = "https://www.googleapis.com/auth/webmasters.readonly";

export function gscAuthUrl(state: string, redirectUri: string) {
  return "https://accounts.google.com/o/oauth2/v2/auth?" + new URLSearchParams({ client_id: clientId(), redirect_uri: redirectUri, response_type: "code", scope: GSC_SCOPE, access_type: "offline", prompt: "consent", state });
}

export async function gscToken(params: Record<string, string>): Promise<{ access_token: string; refresh_token?: string }> {
  return fetchJson("https://oauth2.googleapis.com/token", {
    method: "POST", headers: { "content-type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ client_id: clientId(), client_secret: clientSecret(), ...params }),
  });
}

/** Picks the property matching the site: a domain property first, then the URL-prefix forms. */
export async function gscPickProperty(accessToken: string, domain: string): Promise<string> {
  const r: any = await fetchJson("https://www.googleapis.com/webmasters/v3/sites", { headers: { Authorization: `Bearer ${accessToken}` } });
  const props: string[] = (r.siteEntry ?? []).map((s: any) => s.siteUrl);
  const d = domain.replace(/^www\./, "");
  return props.find((p) => p === `sc-domain:${d}`) ?? props.find((p) => p.replace(/^https?:\/\/(www\.)?/, "").replace(/\/$/, "") === d) ?? "";
}

const cache = new Map<string, { at: number; rows: any[] }>();

export async function gscQuery(site: { id: string; gscProperty: string; gscRefreshEnc: string | null }, r: Range, dimension: "query" | "page" | "date" | "country" | "device", rowLimit = 250) {
  if (!site.gscRefreshEnc || !site.gscProperty) return null;
  const key = `${site.id}|${r.from}|${r.to}|${dimension}`;
  const hit = cache.get(key);
  if (hit && Date.now() - hit.at < 10 * 60_000) return hit.rows;
  const { access_token } = await gscToken({ grant_type: "refresh_token", refresh_token: decrypt(site.gscRefreshEnc) });
  const res: any = await fetchJson(`https://www.googleapis.com/webmasters/v3/sites/${encodeURIComponent(site.gscProperty)}/searchAnalytics/query`, {
    method: "POST", headers: { Authorization: `Bearer ${access_token}`, "content-type": "application/json" },
    body: JSON.stringify({ startDate: r.from, endDate: r.to, dimensions: [dimension], rowLimit }),
  });
  const rows = (res.rows ?? []).map((x: any) => ({ key: x.keys[0], clicks: x.clicks, impressions: x.impressions, ctr: x.ctr * 100, position: x.position }));
  cache.set(key, { at: Date.now(), rows });
  return rows;
}
