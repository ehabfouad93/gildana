import { redirect } from "next/navigation";
import { AskChat } from "@/components/ask-chat";
import { PageHeader } from "@/components/shell";
import { claudeEnabled } from "@/lib/claude";
import { db } from "@/lib/db";
import { homePath } from "@/lib/modules";
import { openaiEnabled } from "@/lib/openai";
import { canFeature, companyWhere, requireWorkspace } from "@/lib/tenancy";

export const metadata = { title: "Ask AI" };

/** Ask questions about your own ad numbers in plain words. */
export default async function Ask() {
  const v = await requireWorkspace();
  if (!canFeature(v, "general.ai_chat")) redirect(homePath(v.modules));
  const [client, companies] = await Promise.all([
    db.client.findUniqueOrThrow({ where: { id: v.clientId }, select: { defaultDays: true } }),
    db.company.findMany({ where: { clientId: v.clientId, ...companyWhere(v) }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
  ]);
  const on = claudeEnabled() || openaiEnabled();
  return (
    <>
      <PageHeader title="Ask AI" sub="Questions about your own numbers, answered from your data" />
      {on ? (
        <AskChat companies={companies} defaultDays={["7", "14", "30", "90"].includes(String(client.defaultDays)) ? String(client.defaultDays) : "30"}
          examples={["Why did my cost per lead change compared with the period before?", "Which campaigns should I give more budget, and which should I stop?", "Which ads are tired and what should replace them?", "ما هي أفضل الحملات أداءً ولماذا؟"]} />
      ) : (
        <div className="card text-sm">No AI is connected yet. {v.isAdmin ? <>Add a Claude or OpenAI API key in <a className="text-accent underline" href="/admin/platforms">Admin → Platforms</a>.</> : "Ask Gildana to connect Claude or OpenAI."}</div>
      )}
    </>
  );
}
