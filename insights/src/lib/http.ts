import { PlatformError } from "@/platforms/types";

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

      const retryable = res.status === 429 || res.status >= 500;
      const msg = apiMessage(data) || `HTTP ${res.status}`;
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

function apiMessage(d: unknown): string {
  if (!d || typeof d !== "object") return "";
  const o = d as Record<string, any>;
  return o.error?.message ?? o.error_description ?? o.message ?? (typeof o.error === "string" ? o.error : "") ?? "";
}

const backoff = (n: number) => 500 * 2 ** n + Math.floor(Math.random() * 250);
export const sleep = (ms: number) => new Promise((r) => setTimeout(r, ms));
