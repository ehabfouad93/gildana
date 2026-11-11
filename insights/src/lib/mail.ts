import nodemailer, { type Transporter } from "nodemailer";
import { db } from "@/lib/db";
import { encrypt } from "@/lib/crypto";
import { setting } from "@/lib/settings";

/**
 * Outgoing email (password resets, invitations). Set up in Admin → Platforms
 * with plain fields — SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS — or with a
 * single SMTP_URL. SMTP_URL=log:// prints messages to the server log instead,
 * for local testing only.
 */
type Config = { kind: "log" } | { kind: "url"; url: string } | { kind: "fields"; host: string; port: number; user: string; pass: string };

function config(): Config | null {
  const url = setting("SMTP_URL");
  if (url.startsWith("log://")) return { kind: "log" };
  const host = setting("SMTP_HOST");
  if (host) return { kind: "fields", host, port: Number(setting("SMTP_PORT")) || 465, user: setting("SMTP_USER"), pass: setting("SMTP_PASS") };
  return url ? { kind: "url", url } : null;
}

export const mailEnabled = () => !!config();

/** The address mail is sent from: MAIL_FROM, else the SMTP username. */
export function fromAddress(): string {
  const user = setting("SMTP_USER");
  return setting("MAIL_FROM") || (user.includes("@") ? `Gildana Insights <${user}>` : "Gildana Insights <noreply@gildana.net>");
}

let transport: { key: string; t: Transporter } | null = null;

function transporter(): Transporter {
  const c = config();
  if (!c) throw new Error("Email is not set up — add the mail server in Admin → Platforms.");
  const key = JSON.stringify(c);
  if (!transport || transport.key !== key) {
    const t = c.kind === "log" ? nodemailer.createTransport({ jsonTransport: true })
      : c.kind === "url" ? nodemailer.createTransport(c.url)
      : nodemailer.createTransport({
          host: c.host, port: c.port,
          secure: c.port === 465, // 465 = SSL from the start; 587/25 upgrade with STARTTLS
          auth: c.user ? { user: c.user, pass: c.pass } : undefined,
          connectionTimeout: 15_000, greetingTimeout: 15_000, socketTimeout: 30_000,
        });
    transport = { key, t };
  }
  return transport.t;
}

/** Remembered so admins can see why mail isn't arriving (shown in Admin → Platforms). */
async function recordResult(error: string) {
  const value = error ? `${new Date().toISOString().slice(0, 16).replace("T", " ")} UTC — ${error.slice(0, 400)}` : "";
  try {
    if (value) await db.appSetting.upsert({ where: { key: "MAIL_LAST_ERROR" }, create: { key: "MAIL_LAST_ERROR", valueEnc: encrypt(value) }, update: { valueEnc: encrypt(value) } });
    else await db.appSetting.deleteMany({ where: { key: "MAIL_LAST_ERROR" } });
  } catch { /* never let bookkeeping hide the real result */ }
}

export async function sendMail(m: { to: string; subject: string; text: string; html?: string }) {
  try {
    const info = await transporter().sendMail({ from: fromAddress(), ...m });
    if (config()?.kind === "log") console.log("[mail]", m.to, m.subject, "\n" + m.text);
    await recordResult("");
    return info;
  } catch (e) {
    const msg = explainMailError(e as Error & { code?: string; responseCode?: number });
    console.error("[mail]", m.to, msg);
    await recordResult(msg);
    throw new Error(msg);
  }
}

/** Turns SMTP errors into something a person can act on. */
export function explainMailError(e: { message: string; code?: string; responseCode?: number }): string {
  const raw = e.message ?? String(e);
  if (e.code === "EAUTH" || e.responseCode === 535) return `The mail server rejected the username or password (${raw}). Use the full email address as the username and the mailbox password.`;
  if (e.code === "ESOCKET" || e.code === "ECONNECTION" || e.code === "ETIMEDOUT" || /ETIMEDOUT|ECONNREFUSED|ENOTFOUND|timeout/i.test(raw)) return `Couldn't reach the mail server (${raw}). Check the server name and port (465 for SSL, 587 for STARTTLS).`;
  if (/certificate|self.signed|altnames/i.test(raw)) return `The mail server's certificate doesn't match its name (${raw}). Use the host name your email provider gives for SMTP, e.g. your hosting server name.`;
  if (e.responseCode === 550 || e.responseCode === 553 || /sender|from address/i.test(raw)) return `The mail server refused the sender address (${raw}). “Send emails as” must be the same mailbox as the username, or one it may send for.`;
  return raw;
}
