import { STAGE_LABEL, type Stage } from "@/modules/customers/profile";

/** Small shared pieces for the Customers pages. */

const STAGE_TONE: Record<string, string> = {
  visitor: "bg-slate-100 text-slate-600", engaged: "bg-sky-50 text-sky-700", lead: "bg-blue-50 text-blue-700", qualified: "bg-indigo-50 text-indigo-700",
  meeting_booked: "bg-violet-50 text-violet-700", meeting_attended: "bg-purple-50 text-purple-700", reservation: "bg-amber-50 text-amber-700",
  purchase: "bg-emerald-50 text-emerald-700", owner: "bg-emerald-100 text-emerald-800", lost: "bg-rose-50 text-rose-700", cold: "bg-slate-100 text-slate-500",
};

export function StagePill({ stage }: { stage: string }) {
  return <span className={`pill ${STAGE_TONE[stage] ?? "bg-slate-100"}`}>{STAGE_LABEL[stage as Stage] ?? stage}</span>;
}

export function ScoreBar({ value, label }: { value: number; label?: string }) {
  const tone = value >= 70 ? "bg-good" : value >= 40 ? "bg-amber-500" : "bg-slate-400";
  return (
    <span className="inline-flex items-center gap-2" title={label}>
      <span className="h-1.5 w-14 overflow-hidden rounded-full bg-line"><span className={`block h-full ${tone}`} style={{ width: `${Math.max(2, value)}%` }} /></span>
      <span className="w-6 text-end tabular-nums text-xs">{value}</span>
    </span>
  );
}

export const SOURCE_ICON: Record<string, string> = { ads: "📣", website: "🌐", crm: "🗂", whatsapp: "💬", api: "⚙", manual: "✎" };

export const when = (d: Date | string) => new Date(d).toISOString().slice(0, 16).replace("T", " ");
