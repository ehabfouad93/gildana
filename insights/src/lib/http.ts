import { PlatformError, RateLimitError } from "@/platforms/types";

type Opts = { method?: string; headers?: Record<string, string>; body?: string | URLSearchParams; tries?: number; timeoutMs?: number };

/**
 * JSON over HTTP with retry. Retries network errors, 429 and 5xx with
 * exponential backoff, honouring Retry-After. Anything else is thrown as a
 * PlatformError carrying the API's own message.
 */
export async function fetchJson<T = unknown>(url: string, o: Opts = {}): Promise<T> {
  const tries = o.tries ?? 4;
  let lastErr: unknown;
  for (let attempt = 0; attempt < tries; attempt++) {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), o.timeoutMs ?? 60_000);
    try {
      const res = await fetch(url, { method: o.method ?? "GET", headers: o.headers, body: o.body, signal: ctrl.signal });
      const text = await res.text();
      let data: unknown = null;
      try { data = text ? JSON.parse(text) : null; } catch { data = { raw: text.slice(0, 500) }; }
      if (res.ok) return data as T;

      const msg = apiMessage(data) || `HTTP ${res.status}`;
      // Throttling (Meta answers it with 400 + a code): don't hammer — tell the worker how long to wait.
      const throttle = throttleWait(data, res.headers, res.status);
      if (throttle !== null) throw new RateLimitError(msg, throttle);
      const retryable = res.status === 429 || res.status >= 500;
      if (!retryable || attempt === tries - 1) throw new PlatformError(msg, res.status, retryable);
      const ra = Number(res.headers.get("retry-after"));
      await sleep(Number.isFinite(ra) && ra > 0 ? Math.min(ra, 60) * 1000 : backoff(attempt));
    } catch (e) {
      if (e instanceof PlatformError) throw e;
      lastErr = e;
      if (attempt === tries - 1) break;
      await sleep(backoff(attempt));
    } finally {
      clearTimeout(timer);
    }
  }
  throw new PlatformError(`Network error: ${(lastErr as Error)?.message ?? "unknown"}`, 0, true);
}

/** Meta's throttling error codes: app, user, page, account and business-use-case limits. */
const META_THROTTLE = new Set([4, 17, 32, 613, 80000, 80001, 80002, 80003, 80004, 80005, 80006, 80008, 80009, 80014]);

/**
 * How long to back off when a response is a rate limit, else null. Uses
 * Meta's own estimate (x-business-use-case-usage / x-ad-account-usage) when
 * it gives one; at least 5 minutes, at most an hour.
 */
export function throttleWait(data: unknown, headers: Headers, _status?: number): number | null {
  const code = Number((data as any)?.error?.code);
  const text = String((data as any)?.error?.message ?? "");
  const limited = META_THROTTLE.has(code) || /too many calls|request limit reached/i.test(text);
  if (!limited) return null;
  let minutes = 0;
  try {
    const buc = JSON.parse(headers.get("x-business-use-case-usage") ?? "{}") as Record<string, { estimated_time_to_regain_access?: number }[]>;
    for (const list of Object.values(buc)) for (const u of list ?? []) minutes = Math.max(minutes, Number(u.estimated_time_to_regain_access) || 0);
  } catch { /* no estimate */ }
  try {
    const acc = JSON.parse(headers.get("x-ad-account-usage") ?? "{}") as { reset_time_duration?: number };
    minutes = Math.max(minutes, Math.ceil((Number(acc.reset_time_duration) || 0) / 60));
  } catch { /* no estimate */ }
  const ra = Number(headers.get("retry-after"));
  if (Number.isFinite(ra) && ra > 0) minutes = Math.max(minutes, Math.ceil(ra / 60));
  return Math.min(60, Math.max(5, minutes)) * 60_000;
}

function apiMessage(d: unknown): string {
  if (!d || typeof d !== "object") return "";
  const o = d as Record<string, any>;
  return o.error?.message ?? o.error_description ?? o.message ?? (typeof o.error === "string" ? o.error : "") ?? "";
}

const backoff = (n: number) => 500 * 2 ** n + Math.floor(Math.random() * 250);
export const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));
