"use server";

import { AuthError } from "next-auth";
import { cookies } from "next/headers";
import { redirect } from "next/navigation";
import { signIn, signOut } from "@/auth";
import { WORKSPACE_COOKIE } from "@/lib/tenancy";

export async function loginAction(_: string | null, form: FormData): Promise<string | null> {
  try {
    await signIn("credentials", { email: form.get("email"), password: form.get("password"), redirectTo: "/" });
  } catch (e) {
    if (e instanceof AuthError) return "Wrong email or password.";
    throw e; // the redirect after a successful sign-in is thrown too
  }
  return null;
}

export async function logoutAction() {
  (await cookies()).delete(WORKSPACE_COOKIE);
  await signOut({ redirectTo: "/login" });
}

export async function setLangAction(form: FormData) {
  const lang = form.get("lang") === "ar" ? "ar" : "en";
  (await cookies()).set("lang", lang, { path: "/", maxAge: 60 * 60 * 24 * 365, sameSite: "lax" });
  redirect(String(form.get("back") || "/"));
}
