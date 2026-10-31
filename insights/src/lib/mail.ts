import nodemailer, { type Transporter } from "nodemailer";
import { setting } from "@/lib/settings";

/**
 * Outgoing email (password resets, scheduled reports). Configured with
 * SMTP_URL, e.g. smtp://user:pass@smtp.hostinger.com:587. SMTP_URL=log://
 * prints messages to the server log instead — for local testing only.
 */
export const mailEnabled = () => !!setting("SMTP_URL");

let transport: { url: string; t: Transporter } | null = null;

function transporter(): Transporter {
  const url = setting("SMTP_URL");
  if (!transport || transport.url !== url) {
    transport = { url, t: url.startsWith("log://") ? nodemailer.createTransport({ jsonTransport: true }) : nodemailer.createTransport(url) };
  }
  return transport.t;
}

export async function sendMail(m: { to: string; subject: string; text: string; html?: string }) {
  if (!mailEnabled()) throw new Error("Email is not configured (SMTP_URL)");
  const info = await transporter().sendMail({ from: setting("MAIL_FROM") || "Gildana Insights <noreply@gildana.net>", ...m });
  if (setting("SMTP_URL").startsWith("log://")) console.log("[mail]", m.to, m.subject, "\n" + m.text);
  return info;
}
