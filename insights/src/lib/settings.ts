import { db } from "@/lib/db";
import { decrypt, encrypt } from "@/lib/crypto";

/**
 * App settings: platform app keys, sign-up, email, AI… Edited in Admin →
 * Platforms and stored encrypted in AppSetting; anything not set there falls
 * back to the environment variable of the same name, so .env keeps working.
 *
 * Reads are synchronous from a per-process cache. Every entry point that
 * needs settings (OAuth routes, pages, the worker) awaits loadSettings()
 * first; the cache refreshes at most every 15 seconds.
 */
let cache = new Map<string, string>();
let loadedAt = 0;

export async function loadSettings(force = false): Promise<void> {
  if (!force && Date.now() - loadedAt < 15_000) return;
  try {
    const rows = await db.appSetting.findMany();
    const next = new Map<string, string>();
    for (const r of rows) {
      try { next.set(r.key, decrypt(r.valueEnc)); } catch { /* key rotated: ignore the row */ }
    }
    cache = next;
    loadedAt = Date.now();
  } catch {
    // Database not ready (first boot, migrations pending): fall back to the environment.
  }
}

export function setting(key: string): string {
  const v = cache.get(key);
  return v !== undefined && v !== "" ? v : process.env[key] ?? "";
}

/** Where a value currently comes from — shown next to each field in the admin. */
export function settingSource(key: string): "db" | "env" | "" {
  if (cache.get(key)) return "db";
  return process.env[key] ? "env" : "";
}

export async function saveSettings(values: Record<string, string | undefined>) {
  for (const [key, raw] of Object.entries(values)) {
    if (raw === undefined) continue;
    const value = raw.trim();
    if (!value) await db.appSetting.deleteMany({ where: { key } });
    else await db.appSetting.upsert({ where: { key }, create: { key, valueEnc: encrypt(value) }, update: { valueEnc: encrypt(value) } });
  }
  await loadSettings(true);
}

export const flag = (key: string, dflt: boolean) => {
  const v = setting(key).toLowerCase();
  return v ? v === "true" || v === "1" || v === "on" : dflt;
};
