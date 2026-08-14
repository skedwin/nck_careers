export default function PlaceholderPage({ title, description }: { title: string; description: string }) {
  return (
    <div className="rounded-2xl border border-dashed border-nck-green/25 bg-white/70 p-8">
      <h2 className="font-display text-3xl font-semibold text-nck-slate">{title}</h2>
      <p className="mt-2 max-w-2xl text-sm text-slate-600">{description}</p>
      <p className="mt-6 inline-flex rounded-full bg-nck-greenLight px-3 py-1 text-xs font-semibold uppercase tracking-wide text-nck-green">
        Scheduled for a later phase
      </p>
    </div>
  );
}
