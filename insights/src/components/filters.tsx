import { Suspense } from "react";
import type { Range } from "@/lib/dates";
import type { Dict } from "@/lib/i18n";
import { FilterBar, type PickAccount } from "./filter-bar";
import { LiveBadge } from "./live-badge";

/**
 * Report filters (company, platform, ad accounts, date range). `extra` and
 * `days` are accepted for older call sites; the bar keeps every other
 * parameter already in the URL (tab, metric, path…).
 */
export function Filters({ t, companies, range, accounts, showPlatform = true, freshness }: {
  t: Dict; companies: { id: string; name: string }[]; companyId?: string; platform?: string; range: Range; days?: string;
  extra?: Record<string, string>; showPlatform?: boolean; accounts?: PickAccount[];
  freshness?: { updatedAt: string | null; syncing: boolean };
}) {
  return (
    <Suspense>
      {freshness && <LiveBadge updatedAt={freshness.updatedAt} syncing={freshness.syncing} />}
      <FilterBar labels={{ allCompanies: t.allCompanies, allPlatforms: t.allPlatforms, apply: t.apply }} companies={companies} accounts={accounts} showPlatform={showPlatform} range={range} />
    </Suspense>
  );
}
