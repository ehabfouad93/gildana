import Link from "next/link";
import { AuthCard } from "@/components/auth-card";
import { peekResetToken } from "@/lib/password-reset";
import { ResetForm } from "./form";

export const metadata = { title: "Choose a new password" };
export const dynamic = "force-dynamic";

export default async function ResetPassword({ searchParams }: { searchParams: Promise<{ token?: string }> }) {
  const { token = "" } = await searchParams;
  const valid = token ? await peekResetToken(token) : null;
  return (
    <AuthCard title={valid?.purpose === "invite" ? "Welcome to Gildana Insights" : "Choose a new password"} sub={valid ? (valid.purpose === "invite" ? `Set a password for ${valid.user.email} to get started.` : `For ${valid.user.email}`) : undefined}>
      {valid ? <ResetForm token={token} /> : (
        <p className="text-sm text-muted">This link has expired or was already used. <Link href="/forgot-password" className="text-accent hover:underline">Ask for a new one</Link>.</p>
      )}
    </AuthCard>
  );
}
