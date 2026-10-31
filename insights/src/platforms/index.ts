import type { Platform } from "@prisma/client";
import { demoConnector } from "./demo";
import { google } from "./google";
import { linkedin } from "./linkedin";
import { meta } from "./meta";
import { snapchat } from "./snapchat";
import { tiktok } from "./tiktok";
import type { Connector } from "./types";
import { setting } from "@/lib/settings";

const REAL: Partial<Record<Platform, Connector>> = { META: meta, GOOGLE: google, LINKEDIN: linkedin, SNAPCHAT: snapchat, TIKTOK: tiktok };

export function registerConnector(c: Connector) {
  REAL[c.platform] = c;
}

/** The connector for a connection: demo connections always use generated data. */
export function connectorFor(platform: Platform, isDemo: boolean): Connector {
  if (isDemo) return demoConnector(platform);
  const c = REAL[platform];
  if (!c) throw new Error(`${platform} connector is not available yet`);
  return c;
}

/** Real connector if its app credentials are configured, else null. */
export function liveConnector(platform: Platform): Connector | null {
  const c = REAL[platform];
  return c && c.configured() ? c : null;
}

export const demoEnabled = () => setting("DEMO_MODE") !== "false";
