import Anthropic from "@anthropic-ai/sdk";
import { setting } from "@/lib/settings";

/**
 * Claude for the GEO module: content reviews and AI-visibility checks.
 * Off unless ANTHROPIC_API_KEY (or another SDK credential) is configured.
 */
export const model = () => setting("CLAUDE_MODEL") || "claude-opus-5-5";

export const claudeEnabled = () => !!(setting("ANTHROPIC_API_KEY") || process.env.ANTHROPIC_AUTH_TOKEN);

let client: { key: string; c: Anthropic } | null = null;
/** One client per API key, so a key changed in Admin → Platforms takes effect without a restart. */
const anthropic = () => {
  const key = setting("ANTHROPIC_API_KEY");
  if (!client || client.key !== key) client = { key, c: key ? new Anthropic({ apiKey: key }) : new Anthropic() };
  return client.c;
};

type Ask = { system: string; prompt: string; webSearch?: boolean; effort?: "low" | "medium" | "high"; maxTokens?: number };

/** One request; returns the answer text and any URLs the web search cited. */
export async function askClaude({ system, prompt, webSearch = false, effort = "medium", maxTokens = 16000 }: Ask): Promise<{ text: string; sources: string[]; refused: boolean }> {
  const params = {
    model: model(),
    max_tokens: maxTokens,
    system,
    thinking: { type: "adaptive" },
    output_config: { effort },
    // A safety-classifier refusal is re-run on Anthropic's recommended fallback model instead of failing.
    betas: ["server-side-fallback-2026-07-01"],
    fallbacks: "default",
    ...(webSearch ? { tools: [{ type: "web_search_20260209", name: "web_search", max_uses: 5 }] } : {}),
    messages: [{ role: "user", content: prompt }],
  };
  // The installed SDK's types may predate `fallbacks`; the request shape follows the API reference.
  const res = (await anthropic().beta.messages.create(params as unknown as Anthropic.Beta.Messages.MessageCreateParamsNonStreaming)) as Anthropic.Beta.Messages.BetaMessage;
  if (res.stop_reason === "refusal") return { text: "", sources: [], refused: true };

  let text = "";
  const sources = new Set<string>();
  for (const block of res.content) {
    if (block.type === "text") {
      text += block.text;
      for (const c of (block as { citations?: { url?: string }[] | null }).citations ?? []) if (c.url) sources.add(c.url);
    }
    if (block.type === "web_search_tool_result" && Array.isArray(block.content)) {
      for (const r of block.content) if (r.type === "web_search_result") sources.add(r.url);
    }
  }
  return { text: text.trim(), sources: [...sources], refused: false };
}
