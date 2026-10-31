import nodemailer, { type Transporter } from "nodemailer";

/**
 * Outgoing email (password resets, scheduled reports). Configured with
 * SMTP_URL, e.g. smtp://user:pass@smtp.hostinger.com:587. SMTP_URL=log://
 * prints messages to the server log instead — for local testing only.
 */
export const mailEnabled = () => !!process.env.SMTP_URL;

let transport: Transporter | null = null;

function transporter(): Transporter {
  if (transport) return transport;
  const url = process.env.SMTP_URL ?? "";
  transport = url.startsWith("log://") ? nodemailer.createTransport({ jsonTransport: true }) : nodemailer.createTransport(url);
  return transport;
}

export async function sendMail(m: { to: string; subject: string; text: string; html?: string }) {
  if (!mailEnabled()) throw new Error("Email is not configured (SMTP_URL)");
  const info = await transporter().sendMail({ from: process.env.MAIL_FROM || "Gildana Insights <noreply@gildana.net>", ...m });
  if (process.env.SMTP_URL?.startsWith("log://")) console.log("[mail]", m.to, m.subject, "\n" + m.text);
  return info;
}
