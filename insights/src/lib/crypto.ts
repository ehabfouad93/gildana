import { createCipheriv, createDecipheriv, randomBytes } from "node:crypto";

/**
 * AES-256-GCM for OAuth tokens at rest. Output is "v1:<iv>:<tag>:<data>" in
 * base64 so a future key rotation can tell old and new ciphertexts apart.
 */
function key(): Buffer {
  const raw = process.env.ENCRYPTION_KEY ?? "";
  const k = Buffer.from(raw, "base64");
  if (k.length !== 32) throw new Error("ENCRYPTION_KEY must be 32 bytes, base64-encoded");
  return k;
}

export function encrypt(plain: string): string {
  const iv = randomBytes(12);
  const c = createCipheriv("aes-256-gcm", key(), iv);
  const data = Buffer.concat([c.update(plain, "utf8"), c.final()]);
  return ["v1", iv.toString("base64"), c.getAuthTag().toString("base64"), data.toString("base64")].join(":");
}

export function decrypt(blob: string | null | undefined): string {
  if (!blob) return "";
  const [v, iv, tag, data] = blob.split(":");
  if (v !== "v1" || !iv || !tag || !data) throw new Error("Unrecognised ciphertext");
  const d = createDecipheriv("aes-256-gcm", key(), Buffer.from(iv, "base64"));
  d.setAuthTag(Buffer.from(tag, "base64"));
  return Buffer.concat([d.update(Buffer.from(data, "base64")), d.final()]).toString("utf8");
}

export function randomToken(bytes = 24): string {
  return randomBytes(bytes).toString("base64url");
}
