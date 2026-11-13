"use client";

import { useActionState } from "react";
import { askAiAdviceAction } from "@/app/actions/audiences";

/** "Ask Claude" / "Ask OpenAI" — runs on click only, and shows the provider's error if it fails. */
export function AiAdviceButton({ kind, campaignId, has }: { kind: "claude" | "openai"; campaignId?: string; has: boolean }) {
  const [msg, action, pending] = useActionState(askAiAdviceAction, null);
  const name = kind === "claude" ? "Claude" : "OpenAI";
  return (
    <form action={action} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="kind" value={kind} />
      {campaignId && <input type="hidden" name="campaignId" value={campaignId} />}
      <button className={has ? "btn-ghost !py-1" : "btn"} disabled={pending}>{pending ? `${name} is reading your history…` : has ? "↻ Ask again" : `Ask ${name}`}</button>
      {msg?.startsWith("error:") && <span className="text-xs text-bad">{msg.slice(6)}</span>}
    </form>
  );
}
