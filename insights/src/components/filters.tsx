import { PLATFORMS, PLATFORM_META } from "@/lib/platforms";
import type { Dict } from "@/lib/i18n";
import type { Range } from "@/lib/dates";

const PRESETS = [7, 14, 30, 90, 180, 365];

/** Plain GET form — filters live in the URL, so every view is linkable and shareable. */
export function Filters({ t, companies, companyId, platform, range, days, extra = {}, showPlatform = true }: {
  t: Dict; companies: { id: string; name: string }[]; companyId?: string; platform?: string; range: Range; days: string;
  extra?: Record<string, string>; showPlatform?: boolean;
}) {
  return (
    <form className="no-print flex flex-wrap items-end gap-2" method="get">
      {Object.entries(extra).map(([k, v]) => <input key={k} type="hidden" name={k} value={v} />)}
      {companies.length > 1 && (
        <select name="company" defaultValue={companyId ?? ""} className="input w-auto">
          <option value="">{t.allCompanies}</option>
          {companies.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      )}
      {showPlatform && (
        <select name="platform" defaultValue={platform ? PLATFORM_META[platform as keyof typeof PLATFORM_META]?.slug : ""} className="input w-auto">
          <option value="">{t.allPlatforms}</option>
          {PLATFORMS.map((p) => <option key={p} value={PLATFORM_META[p].slug}>{PLATFORM_META[p].label}</option>)}
        </select>
      )}
      <select name="days" defaultValue={days || "30"} className="input w-auto">
        {PRESETS.map((d) => <option key={d} value={d}>{t.last} {d} {t.days}</option>)}
        {days === "" && <option value="">{range.from} → {range.to}</option>}
      </select>
      <button className="btn">{t.apply}</button>
    </form>
  );
}
