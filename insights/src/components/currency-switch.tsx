import Link from "next/link";
import { qs, type SP } from "@/lib/page";

export type CurrencyMode = "account" | "reporting";

/** The page's currency choice: ?cur= wins, else the workspace setting. */
export function currencyMode(sp: SP, fallback: string): CurrencyMode {
  const v = typeof sp.cur === "string" ? sp.cur : fallback;
  return v === "reporting" ? "reporting" : "account";
}

/**
 * Switches money on campaign pages between each ad account's real currency
 * (what the platform charges) and the workspace's reporting currency.
 */
export function CurrencySwitch({ sp, mode, account, reporting, rate }: { sp: SP; mode: CurrencyMode; account: string; reporting: string; rate?: string }) {
  if (account === reporting) return null;
  const btn = (m: CurrencyMode, label: string) => (
    <Link href={qs(sp, { cur: m })} className={`rounded-md px-2 py-1 ${mode === m ? "bg-panel font-medium shadow-sm" : "text-muted hover:text-ink"}`} scroll={false}>{label}</Link>
  );
  return (
    <div className="no-print flex flex-wrap items-center gap-2 text-xs">
      <span className="text-muted">Amounts in</span>
      <div className="flex rounded-lg bg-bg p-0.5">
        {btn("account", `${account} · account's real currency`)}
        {btn("reporting", `${reporting} · converted`)}
      </div>
      {mode === "reporting" && rate && <span className="text-muted">{rate}</span>}
    </div>
  );
}
