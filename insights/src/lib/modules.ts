/** Dashboard sections a client user can be given. Owners and Gildana staff always have all. */
export const MODULES = [
  { key: "overview", label: "Overview", path: "/overview" },
  { key: "actions", label: "To-do (audit, rules, changes)", path: "/actions" },
  { key: "spend", label: "Spend", path: "/spend" },
  { key: "performance", label: "Performance", path: "/performance" },
  { key: "campaigns", label: "Campaigns", path: "/campaigns" },
  { key: "creatives", label: "Creatives", path: "/creatives" },
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

/**
 * Sub-features inside the sections, which an owner can switch off for a team
 * member (stored as a switched-off list, so new features start on).
 */
export const FEATURES = [
  { key: "campaigns.adsets", module: "campaigns", label: "Ad sets tab" },
  { key: "campaigns.ads", module: "campaigns", label: "Ads & creatives tab" },
  { key: "campaigns.breakdowns", module: "campaigns", label: "Breakdowns tab (age, gender, country, placement…)" },
  { key: "campaigns.targeting", module: "campaigns", label: "Targeting & audiences tab" },
  { key: "campaigns.leads", module: "campaigns", label: "Leads tab (lead details)" },
  { key: "campaigns.keywords", module: "campaigns", label: "Keywords tab" },
  { key: "campaigns.search_terms", module: "campaigns", label: "Search terms tab" },
  { key: "campaigns.placements", module: "campaigns", label: "Placements tab" },
  { key: "campaigns.share", module: "campaigns", label: "Share report button" },
  { key: "performance.breakdown", module: "performance", label: "Audience breakdown" },
  { key: "audiences.results", module: "audiences", label: "Exact results" },
  { key: "audiences.suggestions", module: "audiences", label: "Suggestions from history" },
  { key: "audiences.ai", module: "audiences", label: "AI suggestions (Claude / OpenAI)" },
  { key: "audiences.create_test", module: "audiences", label: "Create split tests and split-test campaigns" },
  { key: "audiences.pause_losers", module: "audiences", label: "Pause losing ad sets" },
  { key: "actions.apply", module: "actions", label: "Apply and undo changes in ad accounts" },
  { key: "actions.rules", module: "actions", label: "Create and edit automation rules" },
  { key: "campaigns.tags", module: "campaigns", label: "Tags and funnel stages" },
  { key: "general.sync_breakdowns", module: "general", label: "“Sync breakdowns now” button" },
] as const;

export type FeatureKey = (typeof FEATURES)[number]["key"];
export const FEATURE_KEYS: string[] = FEATURES.map((f) => f.key);

/** Whether a feature is on, given the user's switched-off list. */
export function hasFeature(off: string[], f: FeatureKey): boolean {
  return !off.includes(f);
}
