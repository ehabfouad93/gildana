import { fetchJson } from "@/lib/http";
import { setting } from "@/lib/settings";

/**
 * OpenAI, used only for a second opinion on interest suggestions. Off unless
 * OPENAI_API_KEY is set in Admin → Platforms. Plain HTTPS — no SDK needed.
 */
export const openaiModel = () => setting("OPENAI_MODEL") || "gpt-6-astra";

export const openaiEnabled = () => !!setting("OPENAI_API_KEY");

type ChatAnswer = { choices?: { message?: { content?: string | null; refusal?: string | null } }[] };

/** One request that must answer with a JSON object; returns the answer text. */
export async function askOpenAI({ system, prompt, json = true }: { system: string; prompt: string; /** false = a free-text answer */ json?: boolean }): Promise<string> {
  const res = await fetchJson<ChatAnswer>("https://api.openai.com/v1/chat/completions", {
    method: "POST", tries: 2, timeoutMs: 180_000,
    headers: { Authorization: `Bearer ${setting("OPENAI_API_KEY")}`, "Content-Type": "application/json" },
    body: JSON.stringify({
      model: openaiModel(),
      ...(json ? { response_format: { type: "json_object" } } : {}),
      messages: [{ role: "system", content: system }, { role: "user", content: prompt }],
    }),
  });
  const msg = res.choices?.[0]?.message;
  if (msg?.refusal) throw new Error(`OpenAI declined to answer: ${msg.refusal}`);
  if (!msg?.content) throw new Error("OpenAI returned an empty answer.");
  return msg.content;
}
