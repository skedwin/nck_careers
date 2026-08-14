import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useState } from 'react';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type ApplicationSummary,
  type LaravelPaginator,
} from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';

export default function ShortlistingPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [actionError, setActionError] = useState<string | null>(null);

  const listQuery = useQuery({
    queryKey: ['shortlisting', page],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<ApplicationSummary>>>('/shortlisting', {
        params: { page },
      });
      return response.data.data;
    },
  });

  const shortlistMutation = useMutation({
    mutationFn: async (applicationId: number) => {
      const response = await api.post(`/shortlisting/${applicationId}`, {});
      return response.data;
    },
    onSuccess: () => {
      setActionError(null);
      void queryClient.invalidateQueries({ queryKey: ['shortlisting'] });
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
    },
    onError: (error) => setActionError(getApiError(error)),
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Shortlisting</h2>
        <p className="mt-1 text-sm text-slate-600">
          Candidates who passed screening or are already shortlisted.
        </p>
      </div>

      {(listQuery.isError || actionError) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {actionError || getApiError(listQuery.error, 'Unable to load shortlisting queue.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2">Reference</th>
              <th className="px-2 py-2">Applicant</th>
              <th className="px-2 py-2">Position</th>
              <th className="px-2 py-2">Screening</th>
              <th className="px-2 py-2">Status</th>
              <th className="px-2 py-2">Received</th>
              <th className="px-2 py-2">Action</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={7}>
                  Loading…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && items.length === 0 && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={7}>
                  No shortlist candidates yet.
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
                <td className="px-2 py-2">{item.applicant?.full_name ?? '—'}</td>
                <td className="px-2 py-2">{item.position?.title ?? '—'}</td>
                <td className="px-2 py-2">{humanize(item.screening_status)}</td>
                <td className="px-2 py-2">{humanize(item.status)}</td>
                <td className="px-2 py-2 whitespace-nowrap">{formatEAT(item.received_at)}</td>
                <td className="px-2 py-2">
                  <button
                    type="button"
                    disabled={item.status === 'shortlisted' || shortlistMutation.isPending}
                    onClick={() => shortlistMutation.mutate(item.id)}
                    className="rounded-lg bg-nck-green px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-white disabled:opacity-50"
                  >
                    {item.status === 'shortlisted' ? 'Shortlisted' : 'Shortlist'}
                  </button>
                </td>
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
