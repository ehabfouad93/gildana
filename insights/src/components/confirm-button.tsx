"use client";

/** A submit button that asks first (for removals). Works inside server-rendered forms. */
export function ConfirmButton({ message, children, className, name, value }: { message: string; children: React.ReactNode; className?: string; name?: string; value?: string }) {
  return <button name={name} value={value} className={className} onClick={(e) => { if (!confirm(message)) e.preventDefault(); }}>{children}</button>;
}
