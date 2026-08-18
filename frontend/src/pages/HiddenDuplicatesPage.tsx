import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import api, {
  getApiError,
  type ApiSuccess,
  type Application,
  type HiddenDuplicatesReport,
} from '../lib/api';
import { useAuth } from '../context/AuthContext';
import { formatEAT } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';

function cell(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  return String(value);
}

export default function HiddenDuplicatesPage() {
  const { user } = useAuth();
  const canManageDuplicates =
    (user?.permissions?.includes('applications.update') ?? false) ||
    (user?.permissions?.includes('applications.profile.update') ?? false);
  const queryClient = useQueryClient();
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);

  const reportQuery = useQuery({
    queryKey: ['reports-hidden-duplicates'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<HiddenDuplicatesReport>>('/reports/hidden-duplicates');
      return response.data.data;
    },
  });

  const invalidateAfterUnhide = () => {
    setActionError(null);
    void queryClient.invalidateQueries({ queryKey: ['reports-hidden-duplicates'] });
    void queryClient.invalidateQueries({ queryKey: ['reports-long-listing'] });
    void queryClient.invalidateQueries({ queryKey: ['reports-long-listing-category'] });
    void queryClient.invalidateQueries({ queryKey: ['applications'] });
    void queryClient.invalidateQueries({ queryKey: ['application'] });
  };

  const unhideMutation = useMutation({
    mutationFn: async (applicationId: number) => {
      const response = await api.post<ApiSuccess<Application>>(
        `/applications/${applicationId}/unhide-duplicate`,
      );
      return response.data.data;
    },
    onSuccess: invalidateAfterUnhide,
    onError: (error) => {
      setActionError(getApiError(error, 'Unable to unhide duplicate.'));
    },
  });

  const unhideAllMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<ApiSuccess<{ unhidden: number }>>(
        '/applications/unhide-all-duplicates',
      );
      return response.data;
    },
    onSuccess: (payload) => {
      invalidateAfterUnhide();
      const count = payload.data?.unhidden ?? 0;
      window.alert(
        count === 0
          ? 'No hidden duplicates to restore.'
          : `${count} duplicate${count === 1 ? '' : 's'} restored to long listing.`,
      );
    },
    onError: (error) => {
      setActionError(getApiError(error, 'Unable to unhide all duplicates.'));
    },
  });

  const exportCsv = async () => {
    setExporting(true);
    setExportError(null);
    try {
      await downloadAuthorized(
        '/reports/hidden-duplicates/export',
        `nck_hidden_duplicates_${Date.now()}.xls`,
      );
    } catch (error) {
      setExportError(getApiError(error, 'Excel export failed.'));
    } finally {
      setExporting(false);
    }
  };

  if (reportQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading hidden duplicates…</p>;
  }

  if (reportQuery.isError || !reportQuery.data) {
    return (
      <div className="space-y-3">
        <Link to="/reports" className="text-sm font-semibold text-nck-green hover:underline">
          ← Back to reports
        </Link>
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(reportQuery.error, 'Unable to load hidden duplicates.')}
        </div>
      </div>
    );
  }

  const { rows, total, generated_at: generatedAt } = reportQuery.data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link to="/reports" className="text-sm font-semibold text-nck-green hover:underline">
            ← Back to reports
          </Link>
          <h2 className="mt-3 font-display text-3xl font-semibold text-nck-slate">Hidden duplicates</h2>
          <p className="mt-1 text-sm text-slate-600">
            {total.toLocaleString()} hidden duplicate{total === 1 ? '' : 's'}
            {generatedAt ? ` · as of ${formatEAT(generatedAt)}` : ''}
          </p>
          <p className="mt-1 text-sm text-slate-500">
            These applications were hidden from long listing.
            {canManageDuplicates ? (
              <>
                {' '}
                Use <strong>Unhide</strong> for one, or <strong>Unhide all</strong> to restore every row you can access.
              </>
            ) : null}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canManageDuplicates && (
          <button
            type="button"
            disabled={unhideAllMutation.isPending || total === 0}
            onClick={() => {
              if (
                !window.confirm(
                  `Unhide all ${total.toLocaleString()} hidden duplicate${total === 1 ? '' : 's'} back to long listing?`,
                )
              ) {
                return;
              }
              unhideAllMutation.mutate();
            }}
            className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2.5 text-sm font-semibold text-nck-green hover:bg-nck-green/10 disabled:opacity-60"
          >
            {unhideAllMutation.isPending ? 'Unhiding…' : `Unhide all (${total})`}
          </button>
          )}
          <button
            type="button"
            disabled={exporting || total === 0}
            onClick={() => void exportCsv()}
            className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
          >
            {exporting ? 'Exporting…' : 'Export Excel'}
          </button>
        </div>
      </div>

      {(exportError || actionError) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {exportError || actionError}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white shadow-sm">
        <table className="min-w-[1200px] w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-nck-mist/60 text-[11px] uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-3 py-2">SN.</th>
              <th className="px-3 py-2">Unique Identifier</th>
              <th className="px-3 py-2">Duplicate of</th>
              <th className="px-3 py-2">Category</th>
              <th className="px-3 py-2">Applicant</th>
              <th className="px-3 py-2">Email</th>
              <th className="px-3 py-2">Phone</th>
              <th className="px-3 py-2">Hidden at</th>
              <th className="px-3 py-2">Hidden by</th>
              {canManageDuplicates && <th className="px-3 py-2">Action</th>}
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td className="px-3 py-4 text-slate-500" colSpan={canManageDuplicates ? 10 : 9}>
                  No hidden duplicates yet.
                </td>
              </tr>
            )}
            {rows.map((row) => {
              const isUnhiding =
                unhideMutation.isPending && unhideMutation.variables === row.application_id;
              return (
                <tr key={row.application_id} className="border-b border-slate-100 align-top">
                  <td className="px-3 py-2 tabular-nums text-slate-600">{row.serial_no}</td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    <Link
                      className="font-medium text-nck-green hover:underline"
                      to={`/applications/${row.application_id}`}
                    >
                      {row.application_reference}
                    </Link>
                  </td>
                  <td className="px-3 py-2 whitespace-nowrap">
                    {row.duplicate_of_application_id ? (
                      <Link
                        className="font-medium text-nck-green hover:underline"
                        to={`/applications/${row.duplicate_of_application_id}`}
                      >
                        {cell(row.duplicate_of_reference)}
                      </Link>
                    ) : (
                      cell(row.duplicate_of_reference)
                    )}
                  </td>
                  <td className="px-3 py-2">
                    <p className="font-semibold text-nck-green">{cell(row.position_code)}</p>
                    <p className="text-xs text-slate-500">{cell(row.position_title)}</p>
                  </td>
                  <td className="px-3 py-2 font-medium text-nck-slate">{cell(row.applicant_name)}</td>
                  <td className="px-3 py-2">{cell(row.email)}</td>
                  <td className="px-3 py-2 whitespace-nowrap">{cell(row.phone)}</td>
                  <td className="px-3 py-2 whitespace-nowrap text-slate-600">{formatEAT(row.hidden_at)}</td>
                  <td className="px-3 py-2">{cell(row.hidden_by)}</td>
                  {canManageDuplicates && (
                  <td className="px-3 py-2 whitespace-nowrap">
                    <button
                      type="button"
                      disabled={unhideMutation.isPending}
                      onClick={() => {
                        if (!window.confirm(`Unhide ${row.application_reference} back to long listing?`)) {
                          return;
                        }
                        unhideMutation.mutate(row.application_id);
                      }}
                      className="rounded-lg border border-nck-green/20 bg-nck-greenLight px-3 py-1.5 text-xs font-semibold text-nck-green hover:bg-nck-green/10 disabled:opacity-60"
                    >
                      {isUnhiding ? 'Unhiding…' : 'Unhide'}
                    </button>
                  </td>
                  )}
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
