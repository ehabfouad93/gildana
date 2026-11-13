import "server-only";
import { db } from "@/lib/db";

/**
 * Client approval before a launch. With "approval required" on in the
 * workspace settings, the create actions only run for an approval the
 * client accepted (and that hasn't been launched yet).
 */
export async function approvalGate(clientId: string, approvalId: string | undefined | null, kind: string): Promise<string | null> {
  const client = await db.client.findUnique({ where: { id: clientId }, select: { requireApproval: true } });
  if (!approvalId) return client?.requireApproval ? "This workspace needs the client's approval before anything is launched. Use “Send for approval” and launch it from To-do → Approvals once it is accepted." : null;
  const a = await db.approval.findFirst({ where: { id: approvalId, clientId, kind } });
  if (!a) return "Approval not found.";
  if (a.status !== "approved") return "The client has not approved this yet.";
  if (a.launchedAt) return "This approval was already launched.";
  return null;
}

export const money0 = (n: number, currency: string) => new Intl.NumberFormat("en-US", { style: "currency", currency, maximumFractionDigits: 0 }).format(n);
