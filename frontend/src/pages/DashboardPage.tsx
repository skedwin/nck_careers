import { useQuery } from '@tanstack/react-query';
import api, { type ApiSuccess, type DashboardStats } from '../lib/api';
import { formatEAT } from '../lib/dates';

function StatCard({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
      <p className="text-xs uppercase tracking-[0.16em] text-slate-500">{label}</p>
      <p className="mt-3 font-display text-4xl font-semibold text-nck-green">{value}</p>
    </div>
  );
}

export default function DashboardPage() {
  const { data, isLoading, isError } = useQuery({
    queryKey: ['dashboard'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<DashboardStats>>('/dashboard');
      return response.data.data;
    },
  });

  if (isLoading) {
    return <p className="text-sm text-slate-600">Loading dashboard…</p>;
  }

  if (isError || !data) {
    return <p className="text-sm text-red-600">Unable to load dashboard metrics.</p>;
  }

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Dashboard</h2>
        <p className="mt-1 text-sm text-slate-600">
          Live recruitment metrics from applications, screening, and mailbox sync.
        </p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Total applications" value={data.total_applications} />
        <StatCard label="Received today" value={data.applications_today} />
        <StatCard label="This week" value={data.applications_this_week} />
        <StatCard label="This month" value={data.applications_this_month} />
        <StatCard label="Eligible" value={data.eligible} />
        <StatCard label="Needs review" value={data.needs_review} />
        <StatCard label="Shortlisted" value={data.shortlisted} />
        <StatCard label="Pending AI" value={data.pending_ai_processing} />
      </div>

      <div className="rounded-2xl border border-nck-green/10 bg-white p-5">
        <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Mailbox sync</h3>
        <p className="mt-2 text-base text-nck-slate">
          Status: <span className="font-semibold">{data.mailbox_sync.status}</span>
        </p>
        <p className="mt-1 text-sm text-slate-500">
          Last successful sync: {formatEAT(data.mailbox_sync.last_successful_sync_at)}
        </p>
      </div>
    </div>
  );
}
