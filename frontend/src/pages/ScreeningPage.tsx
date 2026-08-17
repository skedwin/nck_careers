import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type Application,
  type ApplicationSummary,
  type LaravelPaginator,
  type ScreeningResult,
} from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';

type DraftResult = {
  criteria_code: string;
  label: string;
  result: 'pass' | 'fail' | 'unknown';
  evidence: string;
};

export default function ScreeningPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [selectedId, setSelectedId] = useState<number | null>(null);
  const [drafts, setDrafts] = useState<DraftResult[]>([]);

  const listQuery = useQuery({
    queryKey: ['screening', page],
    queryFn: async () => {
      const response = await api.get<
        ApiSuccess<LaravelPaginator<ApplicationSummary & { screening_results?: ScreeningResult[] }>>
      >('/screening', { params: { page } });
      return response.data.data;
    },
  });

  const detailQuery = useQuery({
    queryKey: ['application', selectedId],
    enabled: selectedId != null,
    queryFn: async () => {
      const response = await api.get<ApiSuccess<Application>>(`/applications/${selectedId}`);
      return response.data.data;
    },
  });

  const buildDrafts = (application: Application): DraftResult[] => {
    const existing = application.screening_results ?? [];
    if (existing.length > 0) {
      return existing.map((row) => ({
        criteria_code: row.criteria_code,
        label: row.label,
        result: (['pass', 'fail', 'unknown'].includes(row.result) ? row.result : 'unknown') as DraftResult['result'],
        evidence: row.evidence ?? '',
      }));
    }
    return (application.position?.criteria ?? []).map((criterion) => ({
      criteria_code: criterion.code,
      label: criterion.label,
      result: 'unknown' as const,
      evidence: '',
    }));
  };

  useEffect(() => {
    if (detailQuery.data) {
      setDrafts(buildDrafts(detailQuery.data));
    }
  }, [detailQuery.data]);

  const saveMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/screening/${selectedId}`, { results: drafts });
      return response.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['screening'] });
      void queryClient.invalidateQueries({ queryKey: ['application', selectedId] });
    },
  });

  const autoMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post(`/screening/${selectedId}/auto`);
      return response.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['screening'] });
      void queryClient.invalidateQueries({ queryKey: ['application', selectedId] });
    },
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Screening</h2>
        <p className="mt-1 text-sm text-slate-600">
          Applications needing screening. Set pass/fail/unknown per criterion or run auto-screen.
        </p>
      </div>

      {listQuery.isError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(listQuery.error, 'Unable to load screening queue.')}
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[1.1fr_1fr]">
        <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-2 py-2">Reference</th>
                <th className="px-2 py-2">Applicant</th>
                <th className="px-2 py-2">Status</th>
                <th className="px-2 py-2">AI</th>
                <th className="px-2 py-2">Received</th>
              </tr>
            </thead>
            <tbody>
              {listQuery.isLoading && (
                <tr>
                  <td className="px-2 py-4 text-slate-500" colSpan={5}>
                    Loading…
                  </td>
                </tr>
              )}
              {!listQuery.isLoading && items.length === 0 && (
                <tr>
                  <td className="px-2 py-4 text-slate-500" colSpan={5}>
                    No applications currently need screening.
                  </td>
                </tr>
              )}
              {items.map((item) => (
                <tr
                  key={item.id}
                  className={[
                    'border-b border-slate-100 cursor-pointer',
                    selectedId === item.id ? 'bg-nck-greenLight/60' : 'hover:bg-nck-mist',
                  ].join(' ')}
                  onClick={() => setSelectedId(item.id)}
                >
                  <td className="px-2 py-2 font-semibold text-nck-green">{item.application_reference}</td>
                  <td className="px-2 py-2">{item.applicant?.full_name ?? '—'}</td>
                  <td className="px-2 py-2">{humanize(item.screening_status)}</td>
                  <td className="px-2 py-2">{item.ai_extraction_status ? humanize(item.ai_extraction_status) : '—'}</td>
                  <td className="px-2 py-2 whitespace-nowrap">{formatEAT(item.received_at)}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <div className="mt-4 flex items-center justify-between text-sm text-slate-600">
            <span>
              Page {meta.current_page} of {meta.last_page}
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

        <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
          {!selectedId && <p className="text-sm text-slate-500">Select an application to screen.</p>}
          {selectedId && detailQuery.isLoading && <p className="text-sm text-slate-500">Loading criteria…</p>}
          {selectedId && detailQuery.data && (
            <div className="space-y-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 className="font-display text-2xl font-semibold text-nck-slate">
                    <Link className="hover:text-nck-green" to={`/applications/${selectedId}`}>
                      {detailQuery.data.application_reference}
                    </Link>
                  </h3>
                  <p className="text-sm text-slate-600">{detailQuery.data.applicant?.full_name}</p>
                </div>
                <button
                  type="button"
                  disabled={autoMutation.isPending}
                  onClick={() => autoMutation.mutate()}
                  className="rounded-xl border border-nck-gold/40 bg-[#fbf7ef] px-3 py-2 text-sm font-semibold text-[#7a5b16] disabled:opacity-60"
                >
                  {autoMutation.isPending ? 'Running…' : 'Auto-screen'}
                </button>
              </div>

              {drafts.length === 0 && (
                <p className="text-sm text-amber-800">
                  No criteria found. Assign a position with criteria, or run auto-screen.
                </p>
              )}

              <div className="space-y-3">
                {drafts.map((draft, index) => (
                  <div key={draft.criteria_code} className="rounded-xl bg-nck-mist p-3">
                    <p className="text-sm font-semibold text-nck-slate">{draft.label}</p>
                    <div className="mt-2 flex flex-wrap gap-2">
                      {(['pass', 'fail', 'unknown'] as const).map((result) => (
                        <button
                          key={result}
                          type="button"
                          onClick={() =>
                            setDrafts((rows) =>
                              rows.map((row, i) => (i === index ? { ...row, result } : row)),
                            )
                          }
                          className={[
                            'rounded-lg px-3 py-1.5 text-xs font-semibold uppercase tracking-wide',
                            draft.result === result
                              ? 'bg-nck-green text-white'
                              : 'border border-slate-200 bg-white text-slate-700',
                          ].join(' ')}
                        >
                          {result}
                        </button>
                      ))}
                    </div>
                    <input
                      value={draft.evidence}
                      onChange={(event) =>
                        setDrafts((rows) =>
                          rows.map((row, i) =>
                            i === index ? { ...row, evidence: event.target.value } : row,
                          ),
                        )
                      }
                      placeholder="Evidence note"
                      className="mt-2 w-full rounded-lg border border-slate-200 px-3 py-1.5 text-sm"
                    />
                  </div>
                ))}
              </div>

              <button
                type="button"
                disabled={saveMutation.isPending || drafts.length === 0}
                onClick={() => saveMutation.mutate()}
                className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
              >
                {saveMutation.isPending ? 'Saving…' : 'Save results'}
              </button>

              {(saveMutation.isError || autoMutation.isError) && (
                <p className="text-sm text-red-600" role="alert">
                  {getApiError(saveMutation.error || autoMutation.error)}
                </p>
              )}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
