import "server-only";
import type { ShareLink } from "@prisma/client";
import { createHmac } from "node:crypto";
import { lastNDays, ymd, type Range } from "@/lib/dates";
import type { ReportSpec } from "@/components/report/body";

/** A rolling link always shows the latest N complete days; a fixed one shows its stored range. */
export function shareRange(s: ShareLink): Range {
  if (s.rangeDays) return lastNDays(s.rangeDays);
  return { from: ymd(s.fromDate!), to: ymd(s.toDate!) };
}

export function shareSpec(s: ShareLink): ReportSpec {
  return { clientId: s.clientId, companyId: s.companyId, platform: s.platform, campaignId: s.campaignId, range: shareRange(s), title: s.title || "Performance report" };
}

/** Cookie value proving the viewer typed the link's password; tied to the current password hash. */
export function unlockProof(s: ShareLink): string {
  return createHmac("sha256", process.env.AUTH_SECRET ?? "").update(`${s.token}:${s.passwordHash ?? ""}`).digest("base64url");
}

export const shareCookie = (token: string) => `gi_share_${token.slice(0, 12)}`;
