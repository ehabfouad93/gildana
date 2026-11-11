"use client";

import { useFormStatus } from "react-dom";

/** A submit button that shows `pending` text while its form's action runs. */
export function SubmitButton({ children, pending, className = "btn" }: { children: React.ReactNode; pending?: string; className?: string }) {
  const s = useFormStatus();
  return <button className={className} disabled={s.pending}>{s.pending ? pending ?? "…" : children}</button>;
}
