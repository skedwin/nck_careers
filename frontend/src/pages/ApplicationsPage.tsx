import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useMemo, useState } from 'react';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type ApplicationSummary,
  type LaravelPaginator,
} from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';
import { useAuth } from '../context/AuthContext';

const STATUSES = [
  '',
  'received',
  'under_review',
  'eligible',
  'not_eligible',
  'needs_review',
  'shortlisted',
  'rejected',
];

const SOURCES = [
  { value: '', label: 'All sources' },
  { value: 'email', label: 'Email' },
  { value: 'myjobs', label: 'MyJobs applications' },
  { value: 'careerjet', label: 'Careerjet' },
];

export default function ApplicationsPage() {
  const { user } = useAuth();
  const canConvert = user?.permissions?.includes('applications.create') ?? false;
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [source, setSource] = useState('');

  const queryKey = useMemo(
    () => ['applications', page, search, status, source] as const,
    [page, search, status, source],
  );

  const listQuery = useQuery({
    queryKey,
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<ApplicationSummary>>>('/applications', {
        params: {
          page,
          q: search || undefined,
          status: status || undefined,
          source: source || undefined,
        },
      });
      return response.data.data;
    },
  });

  const convertMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<ApiSuccess<{ created: number; skipped: number; failed: number }>>(
        '/applications/convert-from-mailbox',
        { limit: 100 },
      );
      return response.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
    },
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">Applications</h2>
          <p className="mt-1 max-w-2xl text-sm text-slate-600">
            Review mailbox and MyJobs applications. Use the source filter to show MyJobs applications only.
          </p>
        </div>
        {canConvert && (
        <button
          type="button"
          disabled={convertMutation.isPending}
          onClick={() => convertMutation.mutate()}
          className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
        >
          {convertMutation.isPending ? 'Converting…' : 'Convert from mailbox'}
        </button>
        )}
      </div>

      <div className="flex flex-wrap gap-3 rounded-2xl border border-nck-green/10 bg-white p-4">
        <form
          className="flex flex-1 flex-wrap gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            setPage(1);
            setSearch(q.trim());
          }}
        >
          <input
            value={q}
            onChange={(event) => setQ(event.target.value)}
            placeholder="Search reference, subject, applicant…"
            className="min-w-[220px] flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm"
          />
          <button
            type="submit"
            className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2 text-sm font-semibold text-nck-green"
          >
            Search
          </button>
        </form>
        <select
          value={status}
          onChange={(event) => {
            setPage(1);
            setStatus(event.target.value);
          }}
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        >
          {STATUSES.map((value) => (
            <option key={value || 'all'} value={value}>
              {value ? humanize(value) : 'All statuses'}
            </option>
          ))}
        </select>
        <select
          value={source}
          onChange={(event) => {
            setPage(1);
            setSource(event.target.value);
          }}
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        >
          {SOURCES.map((option) => (
            <option key={option.value || 'all'} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
      </div>

      {convertMutation.isSuccess && (
        <div className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-3 text-sm text-nck-green" role="status">
          Converted {convertMutation.data.data.created}, skipped {convertMutation.data.data.skipped}, failed{' '}
          {convertMutation.data.data.failed}.
        </div>
      )}
      {convertMutation.isError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(convertMutation.error)}
        </div>
      )}
      {listQuery.isError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(listQuery.error, 'Unable to load applications.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2">Reference</th>
              <th className="px-2 py-2">Applicant</th>
              <th className="px-2 py-2">Position</th>
              <th className="px-2 py-2">Source</th>
              <th className="px-2 py-2">Status</th>
              <th className="px-2 py-2">Screening</th>
              <th className="px-2 py-2">Received</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={7}>
                  Loading applications…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && items.length === 0 && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={7}>
                  No applications found. Use Convert from mailbox after syncing mail, or import MyJobs
                  applications.
                </td>
              </tr>
            )}
            {items.map((item) => (
              <tr key={item.id} className="border-b border-slate-100">
                <td className="px-2 py-2">
                  <Link className="font-semibold text-nck-green hover:underline" to={`/applications/${item.id}`}>
                    {item.application_reference}
                  </Link>
                </td>
                <td className="px-2 py-2">
                  <div className="font-medium text-nck-slate">{item.applicant?.full_name ?? '—'}</div>
                  <div className="text-xs text-slate-500">{item.applicant?.email ?? ''}</div>
                </td>
                <td className="px-2 py-2">{item.position?.title ?? '—'}</td>
                <td className="px-2 py-2">
                  {item.source === 'myjobs'
                    ? 'MyJobs'
                    : item.source
                      ? humanize(item.source)
                      : '—'}
                </td>
                <td className="px-2 py-2">{humanize(item.status)}</td>
                <td className="px-2 py-2">{humanize(item.screening_status)}</td>
                <td className="px-2 py-2 whitespace-nowrap">{formatEAT(item.received_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>

        <div className="mt-4 flex items-center justify-between text-sm text-slate-600">
          <span>
            Page {meta.current_page} of {meta.last_page} · {meta.total} total
          </span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={meta.current_page <= 1}
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              className="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-50"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={meta.current_page >= meta.last_page}
              onClick={() => setPage((p) => p + 1)}
              className="rounded-lg border border-slate-200 px-3 py-1.5 disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      </div>
    </div>
  );
}
