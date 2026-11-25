/** Shown at once when a menu item is clicked, while the page's numbers load. */
export default function Loading() {
  const bar = "animate-pulse rounded-lg bg-line/60";
  return (
    <div aria-busy="true" aria-label="Loading">
      <div className="mb-5 flex items-end justify-between gap-3">
        <div className="space-y-2"><div className={`${bar} h-6 w-48`} /><div className={`${bar} h-4 w-72`} /></div>
        <div className={`${bar} h-9 w-64`} />
      </div>
      <div className="mb-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-6">{Array.from({ length: 6 }, (_, i) => <div key={i} className={`${bar} h-20`} />)}</div>
      <div className={`${bar} mb-4 h-64`} />
      <div className={`${bar} h-48`} />
    </div>
  );
}
