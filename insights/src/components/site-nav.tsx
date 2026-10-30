import Link from "next/link";

const TABS = [["", "Setup"], ["/analytics", "Analytics"], ["/heatmaps", "Heatmaps"], ["/recordings", "Recordings"], ["/seo", "SEO"], ["/geo", "GEO · AI search"]] as const;

export function SiteNav({ id, active, name, domain }: { id: string; active: string; name: string; domain: string }) {
  return (
    <div className="mb-5">
      <div className="mb-3 flex items-baseline gap-2">
        <Link href="/sites" className="text-sm text-muted hover:text-ink">Websites</Link><span className="text-muted">/</span>
        <h1 className="text-xl font-semibold">{name}</h1><span className="text-sm text-muted">{domain}</span>
      </div>
      <nav className="no-print flex flex-wrap gap-1 border-b border-line">
        {TABS.map(([p, label]) => (
          <Link key={p} href={`/sites/${id}${p}`} className={`-mb-px border-b-2 px-3 py-2 text-sm ${active === p ? "border-accent font-medium" : "border-transparent text-muted hover:text-ink"}`}>{label}</Link>
        ))}
      </nav>
    </div>
  );
}
