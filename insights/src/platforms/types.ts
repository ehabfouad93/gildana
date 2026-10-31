import type { Platform } from "@prisma/client";

/**
 * The contract every ad platform implements. Connectors only speak in the
 * platform's own external ids; src/worker/sync.ts maps them onto internal
 * rows. Money is in the account currency, dates are YYYY-MM-DD.
 */

export type TokenSet = {
  accessToken: string;
  refreshToken?: string;
  expiresAt?: Date;
  scopes?: string;
  externalUser?: string;
  label?: string;
};

export type RemoteAccount = { externalId: string; name: string; currency: string; timezone: string; status?: string; parentId?: string };

export type RemoteCampaign = {
  externalId: string; name: string; objective: string; status: string;
  dailyBudget?: number; totalBudget?: number; startDate?: string; endDate?: string; raw?: unknown;
};

export type RemoteTargetingItem = { type: string; value: string; externalId?: string; exclude?: boolean };

export type RemoteAdSet = {
  externalId: string; campaignExternalId: string; name: string; status: string;
  optimizationGoal?: string; bidStrategy?: string; dailyBudget?: number;
  targetingRaw?: unknown; targeting: RemoteTargetingItem[];
};

export type RemoteAd = {
  externalId: string; adSetExternalId: string; campaignExternalId: string; name: string; status: string;
  format?: string; headline?: string; body?: string; cta?: string; thumbUrl?: string; landingUrl?: string;
  previewUrl?: string; previewHtml?: string;
};

export type Metrics = {
  spend?: number; impressions?: number; reach?: number; clicks?: number; linkClicks?: number;
  conversions?: number; leads?: number; purchases?: number; value?: number; videoViews?: number; engagements?: number;
};

export type StatLevel = "ACCOUNT" | "CAMPAIGN" | "ADSET" | "AD";

export type RemoteStat = Metrics & { level: StatLevel; externalId: string; date: string; extra?: Record<string, unknown> };

export type RemoteBreakdown = Metrics & {
  level: StatLevel; externalId: string; date: string; dimension: string; bucket: string;
};

export type RemoteKeyword = Metrics & {
  externalId: string; campaignExternalId: string; adSetExternalId: string; text: string; matchType: string;
  status?: string; qualityScore?: number | null; date: string;
};

export type RemoteSearchTerm = Metrics & {
  campaignExternalId: string; adSetExternalId: string; term: string; keywordText?: string; matchType?: string; date: string;
};

export type RemotePlacement = Metrics & {
  campaignExternalId: string; kind: string; target: string; display?: string; date: string;
};

export type RemoteLead = {
  externalId: string; campaignExternalId?: string; adSetExternalId?: string; adExternalId?: string;
  formName?: string; createdAt: string; fields: Record<string, string>;
};

export type SyncContext = {
  accessToken: string;
  account: { externalId: string; currency: string; timezone: string; parentId?: string };
  /** Interest split tests created from this app; only the demo connector needs them (real platforms return the ad sets themselves). */
  splitTests?: { sourceAdSet: string; arms: { externalId: string; interest: RemoteTargetingItem }[] }[];
};

export type StatsBundle = {
  stats: RemoteStat[];
  breakdowns: RemoteBreakdown[];
  keywords?: RemoteKeyword[];
  searchTerms?: RemoteSearchTerm[];
  placements?: RemotePlacement[];
};

export interface Connector {
  platform: Platform;
  /** Whether the app credentials for this platform are present in the environment. */
  configured(): boolean;
  authUrl(state: string, redirectUri: string, opts?: { write?: boolean }): string;
  exchange(code: string, redirectUri: string): Promise<TokenSet>;
  refresh?(refreshToken: string): Promise<TokenSet>;
  listAccounts(accessToken: string): Promise<RemoteAccount[]>;
  fetchStructure(ctx: SyncContext): Promise<{ campaigns: RemoteCampaign[]; adSets: RemoteAdSet[]; ads: RemoteAd[] }>;
  fetchStats(ctx: SyncContext, from: string, to: string): Promise<StatsBundle>;
  fetchLeads?(ctx: SyncContext, from: string, to: string): Promise<RemoteLead[]>;
}

export class PlatformError extends Error {
  constructor(message: string, public readonly status = 0, public readonly retryable = false) {
    super(message);
  }
}
