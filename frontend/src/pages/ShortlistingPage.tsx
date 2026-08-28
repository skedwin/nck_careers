import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { useMemo, useState } from 'react';
import api, {
  getApiError,
  type ApiSuccess,
  type ApplicationSummary,
  type ShortlistingGrouped,
  type ShortlistingPositionSummary,
} from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';

type PositionFilter = 'all' | 'unassigned' | number;

function CandidateTable({
  items,
  onShortlist,
  shortlistPending,
}: {
  items: ApplicationSummary[];
  onShortlist: (applicationId: number) => void;
  shortlistPending: boolean;
}) {
  if (items.length === 0) {
    return <p className="px-2 py-4 text-sm text-slate-500">No candidates for this position.</p>;
  }

  return (
    <div className="overflow-x-auto">
      <table className="min-w-full text-left text-sm">
        <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th className="px-2 py-2">Reference</th>
            <th className="px-2 py-2">Applicant</th>
            <th className="px-2 py-2">Screening</th>
            <th className="px-2 py-2">Status</th>
            <th className="px-2 py-2">Received</th>
            <th className="px-2 py-2">Action</th>
          </tr>
        </thead>
        <tbody>
          {items.map((item) => (
            <tr key={item.id} className="border-b border-slate-100">
              <td className="px-2 py-2">
                <Link className="font-semibold text-nck-green hover:underline" to={`/applications/${item.id}`}>
                  {item.application_reference}
                </Link>
              </td>
              <td className="px-2 py-2">{item.applicant?.full_name ?? '—'}</td>
              <td className="px-2 py-2">{humanize(item.screening_status)}</td>
              <td className="px-2 py-2">{humanize(item.status)}</td>
              <td className="px-2 py-2 whitespace-nowrap">{formatEAT(item.received_at)}</td>
              <td className="px-2 py-2">
                <button
                  type="button"
                  disabled={item.status === 'shortlisted' || shortlistPending}
                  onClick={() => onShortlist(item.id)}
                  className="rounded-lg bg-nck-green px-3 py-1.5 text-xs font-semibold uppercase tracking-wide text-white disabled:opacity-50"
                >
                  {item.status === 'shortlisted' ? 'Shortlisted' : 'Shortlist'}
                </button>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function ShortlistingPage() {
  const queryClient = useQueryClient();
  const [selectedPositionId, setSelectedPositionId] = useState<PositionFilter>('all');
  const [actionError, setActionError] = useState<string | null>(null);
  const [exporting, setExporting] = useState<'excel' | 'pdf' | null>(null);
  const [exportError, setExportError] = useState<string | null>(null);

  const groupedQuery = useQuery({
    queryKey: ['shortlisting-grouped'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<ShortlistingGrouped>>('/shortlisting/grouped');
      return response.data.data;
    },
  });

  const summaryQuery = useQuery({
    queryKey: ['shortlisting-summary'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<{ positions: ShortlistingPositionSummary[]; total: number; generated_at?: string }>>(
        '/shortlisting/summary',
      );
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
      void queryClient.invalidateQueries({ queryKey: ['shortlisting-grouped'] });
      void queryClient.invalidateQueries({ queryKey: ['shortlisting-summary'] });
      void queryClient.invalidateQueries({ queryKey: ['shortlisting'] });
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
    },
    onError: (error) => setActionError(getApiError(error)),
  });

  const positions = groupedQuery.data?.positions ?? [];
  const summaryPositions = summaryQuery.data?.positions ?? [];
  const total = groupedQuery.data?.total ?? 0;

  const visibleGroups = useMemo(() => {
    if (selectedPositionId === 'all') return positions;
    if (selectedPositionId === 'unassigned') {
      return positions.filter((group) => group.position_id === null);
    }
    return positions.filter((group) => group.position_id === selectedPositionId);
  }, [positions, selectedPositionId]);

  const positionFilterKey = (positionId: number | null): PositionFilter =>
    positionId === null ? 'unassigned' : positionId;

  const exportParams =
    typeof selectedPositionId === 'number' ? `?position_id=${selectedPositionId}` : '';

  const exportReport = async (format: 'excel' | 'pdf') => {
    setExporting(format);
    setExportError(null);
    try {
      const stamp = Date.now();
      const suffix =
        selectedPositionId === 'all'
          ? 'by_position'
          : selectedPositionId === 'unassigned'
            ? 'unassigned'
            : `position_${selectedPositionId}`;
      if (format === 'excel') {
        await downloadAuthorized(
          `/shortlisting/export/excel${exportParams}`,
          `nck_shortlisting_${suffix}_${stamp}.xls`,
        );
      } else {
        await downloadAuthorized(
          `/shortlisting/export/pdf${exportParams}`,
          `nck_shortlisting_${suffix}_${stamp}.pdf`,
        );
      }
    } catch (error) {
      setExportError(getApiError(error, `${format === 'excel' ? 'Excel' : 'PDF'} export failed.`));
    } finally {
      setExporting(null);
    }
  };

  const isLoading = groupedQuery.isLoading || summaryQuery.isLoading;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">Shortlisting</h2>
          <p className="mt-1 text-sm text-slate-600">
            Candidates organised by position — {total.toLocaleString()} total
            {groupedQuery.data?.generated_at ? ` · as of ${formatEAT(groupedQuery.data.generated_at)}` : ''}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            disabled={exporting !== null || total === 0}
            onClick={() => void exportReport('excel')}
            className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
          >
            {exporting === 'excel' ? 'Exporting…' : selectedPositionId === 'all' ? 'Export Excel (by position)' : 'Export Excel'}
          </button>
          <button
            type="button"
            disabled={exporting !== null || total === 0}
            onClick={() => void exportReport('pdf')}
            className="rounded-xl border border-nck-green/25 bg-white px-4 py-2.5 text-sm font-semibold text-nck-green hover:bg-nck-greenLight disabled:opacity-60"
          >
            {exporting === 'pdf' ? 'Exporting…' : 'Export PDF'}
          </button>
        </div>
      </div>

      {(exportError || groupedQuery.isError || summaryQuery.isError || actionError) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {exportError ||
            actionError ||
            getApiError(groupedQuery.error ?? summaryQuery.error, 'Unable to load shortlisting data.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-nck-mist/60 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-4 py-3">Code</th>
              <th className="px-4 py-3">Position</th>
              <th className="px-4 py-3 text-right">Candidates</th>
              <th className="px-4 py-3 text-right">Shortlisted</th>
              <th className="px-4 py-3 text-right">In queue</th>
              <th className="px-4 py-3" />
            </tr>
          </thead>
          <tbody>
            {isLoading && (
              <tr>
                <td className="px-4 py-4 text-slate-500" colSpan={6}>
                  Loading positions…
                </td>
              </tr>
            )}
            {!isLoading && summaryPositions.length === 0 && (
              <tr>
                <td className="px-4 py-4 text-slate-600" colSpan={6}>
                  <p>No shortlist candidates yet.</p>
                  <p className="mt-2 text-xs text-slate-500">
                    This queue only shows applicants who passed screening or are already shortlisted.
                  </p>
                </td>
              </tr>
            )}
            {!isLoading &&
              summaryPositions.map((position) => (
                <tr key={position.position_id ?? 'unassigned'} className="border-b border-slate-100 hover:bg-nck-mist/40">
                  <td className="px-4 py-3 font-semibold text-nck-green">
                    {position.reference_code ?? 'UNASSIGNED'}
                  </td>
                  <td className="px-4 py-3 font-medium text-nck-slate">{position.title}</td>
                  <td className="px-4 py-3 text-right tabular-nums font-semibold text-nck-green">
                    {position.total.toLocaleString()}
                  </td>
                  <td className="px-4 py-3 text-right tabular-nums">{position.shortlisted.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right tabular-nums">{position.queue.toLocaleString()}</td>
                  <td className="px-4 py-3 text-right">
                    <button
                      type="button"
                      onClick={() => setSelectedPositionId(positionFilterKey(position.position_id))}
                      className="inline-flex rounded-xl border border-nck-green/20 px-3 py-1.5 text-sm font-semibold text-nck-green hover:bg-nck-greenLight"
                    >
                      View
                    </button>
                  </td>
                </tr>
              ))}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap gap-2">
        <button
          type="button"
          onClick={() => setSelectedPositionId('all')}
          className={`rounded-full px-4 py-2 text-sm font-semibold ${
            selectedPositionId === 'all'
              ? 'bg-nck-green text-white'
              : 'border border-slate-200 bg-white text-slate-700 hover:bg-nck-mist'
          }`}
        >
          All positions ({total.toLocaleString()})
        </button>
        {summaryPositions.map((position) => {
          const filterKey = positionFilterKey(position.position_id);
          return (
          <button
            key={position.position_id ?? 'unassigned'}
            type="button"
            onClick={() => setSelectedPositionId(filterKey)}
            className={`rounded-full px-4 py-2 text-sm font-semibold ${
              selectedPositionId === filterKey
                ? 'bg-nck-green text-white'
                : 'border border-slate-200 bg-white text-slate-700 hover:bg-nck-mist'
            }`}
          >
            {position.reference_code ?? 'UNASSIGNED'} · {position.total}
          </button>
          );
        })}
      </div>

      {isLoading && <p className="text-sm text-slate-600">Loading candidates…</p>}

      {!isLoading && visibleGroups.length === 0 && total === 0 && (
        <div className="rounded-2xl border border-nck-green/10 bg-white p-5 text-sm text-slate-600 shadow-sm">
          No shortlist candidates yet. Import or mark applicants as shortlisted to populate this page.
        </div>
      )}

      {visibleGroups.map((group) => (
        <section
          key={group.position_id ?? 'unassigned'}
          className="overflow-hidden rounded-2xl border border-nck-green/10 bg-white shadow-sm"
        >
          <div className="border-b border-slate-200 bg-nck-mist/50 px-5 py-4">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-nck-green">
              {group.reference_code ?? 'UNASSIGNED'}
            </p>
            <div className="mt-1 flex flex-wrap items-end justify-between gap-3">
              <h3 className="font-display text-2xl font-semibold text-nck-slate">{group.title}</h3>
              <p className="text-sm text-slate-600">
                {group.total.toLocaleString()} candidate{group.total === 1 ? '' : 's'}
              </p>
            </div>
          </div>
          <div className="p-5">
            <CandidateTable
              items={group.candidates}
              onShortlist={(id) => shortlistMutation.mutate(id)}
              shortlistPending={shortlistMutation.isPending}
            />
          </div>
        </section>
      ))}
    </div>
  );
}
