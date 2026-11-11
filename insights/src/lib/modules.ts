/** Dashboard sections a client user can be given. Owners and Gildana staff always have all. */
export const MODULES = [
  { key: "overview", label: "Overview", path: "/overview" },
  { key: "spend", label: "Spend", path: "/spend" },
  { key: "performance", label: "Performance", path: "/performance" },
  { key: "campaigns", label: "Campaigns", path: "/campaigns" },
  { key: "audiences", label: "Audiences", path: "/audiences" },
  { key: "google", label: "Google Search", path: "/google" },
  { key: "sites", label: "Websites (analytics, heatmaps, SEO, GEO)", path: "/sites" },
  { key: "reports", label: "Reports & share links", path: "/reports" },
  { key: "connections", label: "Ad accounts", path: "/connections" },
  { key: "companies", label: "Companies & budgets", path: "/companies" },
] as const;

export type ModuleKey = (typeof MODULES)[number]["key"];
export const MODULE_KEYS: string[] = MODULES.map((m) => m.key);

/** Whether a module list (empty = everything) includes `m`. */
export function hasModule(modules: string[], m: ModuleKey): boolean {
  return !modules.length || modules.includes(m);
}

/** Where to send someone: the first section they may open. */
export function homePath(modules: string[]): string {
  return MODULES.find((m) => hasModule(modules, m.key))?.path ?? "/account";
}
