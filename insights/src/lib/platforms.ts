import type { Platform } from "@prisma/client";

export const PLATFORMS: Platform[] = ["META", "GOOGLE", "LINKEDIN", "SNAPCHAT", "TIKTOK"];

export const PLATFORM_META: Record<Platform, { label: string; color: string; adSetLabel: string; slug: string }> = {
  META:     { label: "Meta",       color: "#2563eb", adSetLabel: "Ad sets",   slug: "meta" },
  GOOGLE:   { label: "Google Ads", color: "#16a34a", adSetLabel: "Ad groups", slug: "google" },
  LINKEDIN: { label: "LinkedIn",   color: "#0e7490", adSetLabel: "Campaigns", slug: "linkedin" },
  SNAPCHAT: { label: "Snapchat",   color: "#ca8a04", adSetLabel: "Ad squads", slug: "snapchat" },
  TIKTOK:   { label: "TikTok",     color: "#db2777", adSetLabel: "Ad groups", slug: "tiktok" },
};

export function platformFromSlug(s: string): Platform | null {
  const hit = PLATFORMS.find((p) => PLATFORM_META[p].slug === s.toLowerCase());
  return hit ?? null;
}
