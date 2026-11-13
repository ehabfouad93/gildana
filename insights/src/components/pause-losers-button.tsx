"use client";

import { useActionState } from "react";
import { pauseLosersAction } from "@/app/actions/split-test";

/** Pauses a test's clearly losing ad sets, after a confirm. Nothing is paused automatically. */
export function PauseLosersButton({ testId, names }: { testId: string; names: string[] }) {
  const [res, action, pending] = useActionState(pauseLosersAction, null);
  if (res?.ok) return <p className="text-xs text-good">✓ {res.message}</p>;
  return (
    <form action={action} onSubmit={(e) => { if (!confirm(`Pause ${names.length} losing ad set${names.length > 1 ? "s" : ""} on Meta?\n\n${names.join(", ")}`)) e.preventDefault(); }} className="flex flex-wrap items-center gap-2">
      <input type="hidden" name="testId" value={testId} />
      <button className="btn-ghost !py-1 text-xs" disabled={pending}>{pending ? "Pausing…" : `Pause ${names.length} losing ad set${names.length > 1 ? "s" : ""}`}</button>
      {res && !res.ok && <span className="text-xs text-bad">{res.message}</span>}
    </form>
  );
}
