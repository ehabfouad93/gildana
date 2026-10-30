import type { Platform } from "@prisma/client";
import { demoConnector } from "./demo";
import { meta } from "./meta";
import type { Connector } from "./types";

const REAL: Partial<Record<Platform, Connector>> = { META: meta };

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

export const demoEnabled = () => process.env.DEMO_MODE !== "false";
