"use server";

import type { Prisma } from "@prisma/client";
import { revalidatePath } from "next/cache";
import { askClaude, claudeEnabled } from "@/lib/claude";
import { db } from "@/lib/db";
import { getLang } from "@/lib/i18n";
import { AI_SYSTEM, advicePrompt, parseAiAdvice } from "@/lib/interest-advice";
import { askOpenAI, openaiEnabled } from "@/lib/openai";
import { interestHistory } from "@/lib/queries";
import { adviceScope } from "@/lib/advice-scope";
import { canFeature, requireModule } from "@/lib/tenancy";

export type AiKind = "claude" | "openai";

/**
 * "Ask Claude" / "Ask OpenAI": sends the account's own all-time history and
 * stores the model's suggestions for the workspace. Runs only on request.
 */
export async function askAiAdviceAction(_: string | null, form: FormData): Promise<string | null> {
  const v = await requireModule("audiences");
  if (!canFeature(v, "audiences.ai")) return "error:AI suggestions are switched off for your account.";
  const kind: AiKind = form.get("kind") === "openai" ? "openai" : "claude";
  if (kind === "claude" ? !claudeEnabled() : !openaiEnabled()) return `error:${kind === "claude" ? "Claude" : "OpenAI"} isn't connected — add its API key in Admin → Platforms.`;
  const { accountIds, campaign, scopeKey } = await adviceScope(v, String(form.get("campaignId") || "") || undefined);
  const client = await db.client.findUniqueOrThrow({ where: { id: v.clientId }, select: { name: true, reportingCurrency: true, companies: { select: { name: true, industry: true } } } });
  const history = await interestHistory({ accountIds, currency: client.reportingCurrency });
  if (!history.sets.some((s) => s.spend > 0 && s.interests.length)) return "error:There is no Meta ad set with interests and spend yet, so there is nothing to analyse.";

  const prompt = advicePrompt(history, {
    campaignId: campaign?.id, language: (await getLang()) === "ar" ? "Arabic" : "English",
    context: `Advertiser: ${client.name}. Brands: ${client.companies.map((c) => `${c.name}${c.industry ? ` (${c.industry})` : ""}`).join(", ") || "—"}.${campaign ? ` Focus on the campaign "${campaign.name}".` : ""}`,
  });
  try {
    let text: string;
    if (kind === "claude") {
      const r = await askClaude({ system: AI_SYSTEM, prompt, effort: "medium", maxTokens: 8000 });
      if (r.refused) return "error:Claude declined to answer this request.";
      text = r.text;
    } else {
      text = await askOpenAI({ system: AI_SYSTEM, prompt });
    }
    const advice = parseAiAdvice(text, history);
    const content = advice as unknown as Prisma.InputJsonValue;
    await db.audienceCache.upsert({
      where: { clientId_kind_scopeKey: { clientId: v.clientId, kind, scopeKey } },
      create: { clientId: v.clientId, kind, scopeKey, content },
      update: { content, createdAt: new Date() },
    });
  } catch (e) {
    return `error:${(e as Error).message.slice(0, 300)}`;
  }
  revalidatePath("/audiences");
  return null;
}
