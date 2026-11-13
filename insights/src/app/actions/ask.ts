"use server";

import { askClaude, claudeEnabled } from "@/lib/claude";
import { ASK_SYSTEM, dataPack } from "@/lib/ask-data";
import { db } from "@/lib/db";
import { rangeFromParams } from "@/lib/dates";
import { askOpenAI, openaiEnabled } from "@/lib/openai";
import { canFeature, requireWorkspace, scopedAccountIds } from "@/lib/tenancy";

export type Turn = { role: "user" | "assistant"; text: string };
export type AskResult = { ok: boolean; text: string; by?: string };

/**
 * One question about the asker's own numbers. The data sent to the AI is
 * built here, for the accounts this person may see — the model never reads
 * the database itself.
 */
export async function askDataAction(question: string, history: Turn[], filters: { days?: string; from?: string; to?: string; company?: string }): Promise<AskResult> {
  const v = await requireWorkspace();
  if (!canFeature(v, "general.ai_chat")) return { ok: false, text: "Asking AI is switched off for your account." };
  const q = String(question ?? "").trim().slice(0, 1000);
  if (!q) return { ok: false, text: "Type a question." };
  const useClaude = claudeEnabled();
  if (!useClaude && !openaiEnabled()) return { ok: false, text: "No AI is connected. A Gildana admin can add a Claude or OpenAI key in Admin → Platforms." };

  const client = await db.client.findUniqueOrThrow({ where: { id: v.clientId }, select: { name: true, reportingCurrency: true, defaultDays: true } });
  const company = filters.company && (!v.access || v.companyIds.includes(filters.company)) ? filters.company : undefined;
  const companyName = company ? (await db.company.findFirst({ where: { id: company, clientId: v.clientId }, select: { name: true } }))?.name : undefined;
  const accountIds = await scopedAccountIds(v.clientId, { access: v.access, companyId: companyName ? company : undefined });
  const range = rangeFromParams({ days: filters.days, from: filters.from, to: filters.to }, client.defaultDays);
  const data = await dataPack({ accountIds, currency: client.reportingCurrency }, range, { workspace: client.name, filters: companyName ? `company: ${companyName}` : "all companies" });

  const past = (Array.isArray(history) ? history : []).slice(-6).map((t) => `${t.role === "user" ? "Question" : "Answer"}: ${String(t.text).slice(0, 1500)}`).join("\n\n");
  const prompt = `data:\n${JSON.stringify(data)}\n\n${past ? `Earlier in this conversation:\n${past}\n\n` : ""}Question: ${q}`;
  try {
    if (useClaude) {
      const r = await askClaude({ system: ASK_SYSTEM, prompt, effort: "medium", maxTokens: 4000 });
      return r.refused ? { ok: false, text: "Claude declined to answer this question." } : { ok: true, text: r.text, by: "Claude" };
    }
    return { ok: true, text: await askOpenAI({ system: ASK_SYSTEM, prompt, json: false }), by: "OpenAI" };
  } catch (e) {
    return { ok: false, text: `The AI could not answer: ${(e as Error).message.slice(0, 300)}` };
  }
}
