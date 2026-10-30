/** Small, safe markdown renderer for AI-written reviews: headings, lists, bold, code, paragraphs. Everything is escaped first. */
const esc = (s: string) => s.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
const inline = (s: string) => esc(s).replace(/\*\*(.+?)\*\*/g, "<b>$1</b>").replace(/`([^`]+)`/g, "<code class=\"rounded bg-bg px-1\">$1</code>");

export function Markdown({ text }: { text: string }) {
  const html: string[] = [];
  let list: "ul" | "ol" | null = null;
  let code = false;
  const close = () => { if (list) { html.push(`</${list}>`); list = null; } };
  for (const line of text.split("\n")) {
    if (line.startsWith("```")) { close(); html.push(code ? "</pre>" : '<pre class="my-2 overflow-x-auto rounded-lg bg-bg p-3 text-xs">'); code = !code; continue; }
    if (code) { html.push(esc(line) + "\n"); continue; }
    const h = line.match(/^(#{1,4})\s+(.*)/);
    const ul = line.match(/^\s*[-*]\s+(.*)/);
    const ol = line.match(/^\s*\d+[.)]\s+(.*)/);
    if (h) { close(); html.push(`<h${Math.min(4, h[1].length + 1)} class="mt-4 mb-1 font-semibold">${inline(h[2])}</h${Math.min(4, h[1].length + 1)}>`); }
    else if (ul || ol) {
      const kind = ul ? "ul" : "ol";
      if (list !== kind) { close(); html.push(`<${kind} class="${kind === "ul" ? "list-disc" : "list-decimal"} ms-5 space-y-1">`); list = kind; }
      html.push(`<li>${inline((ul ?? ol)![1])}</li>`);
    } else if (line.trim()) { close(); html.push(`<p class="my-2">${inline(line)}</p>`); }
    else close();
  }
  close();
  if (code) html.push("</pre>");
  return <div className="text-sm leading-relaxed" dangerouslySetInnerHTML={{ __html: html.join("") }} />;
}
