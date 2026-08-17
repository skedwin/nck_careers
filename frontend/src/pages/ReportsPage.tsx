import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api, {
  getApiError,
  type ApiSuccess,
  type LongListingCategorySummary,
  type LongListingIndex,
  type ReportSummary,
} from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';

function StatCard({ label, value }: { label: string; value: number | string }) {
  return (
    <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
      <p className="text-xs uppercase tracking-[0.16em] text-slate-500">{label}</p>
      <p className="mt-3 font-display text-4xl font-semibold text-nck-green">{value}</p>
    </div>
  );
}

export default function ReportsPage() {
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const summaryQuery = useQuery({
    queryKey: ['reports-summary'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<ReportSummary>>('/reports/summary');
      return response.data.data;
    },
  });

  const longListingQuery = useQuery({
    queryKey: ['reports-long-listing'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LongListingIndex>>('/reports/long-listing', {
        params: { include_unassigned: 1 },
      });
      return response.data.data;
    },
  });

  const categories = useMemo(() => {
    const report = longListingQuery.data;
    if (!report) return [] as LongListingCategorySummary[];
    const list = [...report.categories];
    if (report.unassigned) {
      list.push(report.unassigned);
    }
    return list;
  }, [longListingQuery.data]);

  const exportAll = async () => {
    setExporting(true);
    setExportError(null);
    try {
      await downloadAuthorized(
        '/reports/long-listing/export?include_unassigned=1',
        `nck_long_listing_all_${Date.now()}.xls`,
      );
    } catch (error) {
      setExportError(getApiError(error, 'Excel export failed.'));
    } finally {
      setExporting(false);
    }
  };

  if (summaryQuery.isLoading || longListingQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading reports…</p>;
  }

  if (summaryQuery.isError || !summaryQuery.data) {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        {getApiError(summaryQuery.error, 'Unable to load report summary.')}
      </div>
    );
  }

  const data = summaryQuery.data;
  const statusEntries = Object.entries(data.counts_by_status ?? {});
  const listing = longListingQuery.data;

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">Reports</h2>
          <p className="mt-1 text-sm text-slate-600">
            Summary and long listing by application category as of {formatEAT(data.generated_at)}.
          </p>
        </div>
        <button
          type="button"
          disabled={exporting}
          onClick={() => void exportAll()}
          className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
        >
          {exporting ? 'Exporting…' : 'Export Excel'}
        </button>
      </div>

      {exportError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {exportError}
        </div>
      )}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="This week" value={data.applications_this_week ?? 0} />
        <StatCard label="This month" value={data.applications_this_month ?? 0} />
        <StatCard label="Mail messages" value={data.mailbox?.messages_total ?? 0} />
        <StatCard label="Pending mail apps" value={data.mailbox?.messages_pending_application ?? 0} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="space-y-4">
          <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
            <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">By status</h3>
            <ul className="mt-3 space-y-2 text-sm">
              {statusEntries.length === 0 && <li className="text-slate-500">No applications yet.</li>}
              {statusEntries.map(([status, total]) => (
                <li key={status} className="flex justify-between rounded-xl bg-nck-mist px-3 py-2">
                  <span className="capitalize text-nck-slate">{humanize(status)}</span>
                  <span className="font-semibold text-nck-green">{total}</span>
                </li>
              ))}
            </ul>
          </div>

          <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
            <div className="flex items-start justify-between gap-3">
              <div>
                <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
                  Duplicates
                </h3>
                <p className="mt-1 text-xs text-slate-500">Same email address, within each position.</p>
              </div>
              <Link
                to="/reports/email-duplicates"
                className="shrink-0 text-sm font-semibold text-nck-green hover:underline"
              >
                Open report
              </Link>
            </div>
            <p className="mt-3 font-display text-3xl font-semibold text-nck-green">
              {(data.email_duplicates?.total ?? 0).toLocaleString()}
            </p>
            <p className="mt-1 text-xs text-slate-500">
              {(data.email_duplicates?.groups ?? 0).toLocaleString()} email
              {(data.email_duplicates?.groups ?? 0) === 1 ? '' : 's'} with more than one application
            </p>
            <ul className="mt-3 space-y-2 text-sm">
              {(data.email_duplicates?.categories ?? []).length === 0 && (
                <li className="text-slate-500">No same-email duplicates.</li>
              )}
              {(data.email_duplicates?.categories ?? []).map((row) => (
                <li
                  key={row.key}
                  className="flex justify-between gap-3 rounded-xl bg-nck-mist px-3 py-2"
                >
                  <span className="text-nck-slate">
                    {row.reference_code ? `${row.reference_code} · ` : ''}
                    {row.title}
                  </span>
                  <Link
                    to={
                      row.position_id != null
                        ? `/reports/email-duplicates?position_id=${row.position_id}`
                        : '/reports/email-duplicates'
                    }
                    className="shrink-0 font-semibold text-amber-800 hover:underline"
                    title="Open same-email duplicates for this category"
                  >
                    {row.duplicate_applicants.toLocaleString()}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        </div>

        <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">By category</h3>
          <ul className="mt-3 space-y-2 text-sm">
            {(data.counts_by_position ?? []).length === 0 && (
              <li className="text-slate-500">No position breakdown yet.</li>
            )}
            {(data.counts_by_position ?? []).map((row) => (
              <li
                key={`${row.position_id ?? 'none'}-${row.reference_code ?? ''}`}
                className="flex justify-between gap-3 rounded-xl bg-nck-mist px-3 py-2"
              >
                <span className="text-nck-slate">
                  {row.reference_code ? `${row.reference_code} · ` : ''}
                  {row.title ?? 'Unassigned'}
                </span>
                <span className="shrink-0 font-semibold text-nck-green">{row.total}</span>
              </li>
            ))}
          </ul>
        </div>
      </div>

      <section className="space-y-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h3 className="font-display text-2xl font-semibold text-nck-slate">Long listing</h3>
            <p className="mt-1 text-sm text-slate-600">
              Open a category to view applicants with search and pagination. Generated{' '}
              {formatEAT(listing?.generated_at)}.
            </p>
          </div>
          <Link
            to="/reports/hidden-duplicates"
            className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-100"
          >
            Hidden duplicates report
          </Link>
        </div>

        {longListingQuery.isError && (
          <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
            {getApiError(longListingQuery.error, 'Unable to load long listing.')}
          </div>
        )}

        <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white shadow-sm">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 bg-nck-mist/60 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-4 py-3">Code</th>
                <th className="px-4 py-3">Category / Position</th>
                <th className="px-4 py-3">Vacancies</th>
                <th className="px-4 py-3 text-right">Applicants</th>
                <th className="px-4 py-3 text-right">Duplicates</th>
                <th className="px-4 py-3" />
              </tr>
            </thead>
            <tbody>
              {categories.length === 0 && (
                <tr>
                  <td className="px-4 py-4 text-slate-500" colSpan={6}>
                    No categories found.
                  </td>
                </tr>
              )}
              {categories.map((category) => {
                const key = category.key || (category.position_id != null ? String(category.position_id) : 'unassigned');
                const duplicates = category.duplicate_applicants ?? 0;
                return (
                  <tr key={key} className="border-b border-slate-100 hover:bg-nck-mist/40">
                    <td className="px-4 py-3 font-semibold text-nck-green">
                      {category.reference_code ?? 'UNASSIGNED'}
                    </td>
                    <td className="px-4 py-3 font-medium text-nck-slate">{category.title}</td>
                    <td className="px-4 py-3 tabular-nums text-slate-600">
                      {category.vacancies != null ? category.vacancies : '—'}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums font-semibold text-nck-green">
                      {category.total_applicants.toLocaleString()}
                    </td>
                    <td className="px-4 py-3 text-right tabular-nums">
                      {duplicates > 0 ? (
                        <Link
                          to={`/reports/long-listing/${encodeURIComponent(key)}?duplicates=duplicates`}
                          className="font-semibold text-amber-800 hover:underline"
                          title="Open duplicates only"
                        >
                          {duplicates.toLocaleString()}
                        </Link>
                      ) : (
                        <span className="text-slate-500">0</span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Link
                        to={`/reports/long-listing/${encodeURIComponent(key)}`}
                        className="inline-flex rounded-xl border border-nck-green/20 px-3 py-1.5 text-sm font-semibold text-nck-green hover:bg-nck-greenLight"
                      >
                        Open
                      </Link>
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}
