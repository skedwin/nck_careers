import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import api, {
  getApiError,
  type ApiSuccess,
  type EmailDuplicatesReport,
} from '../lib/api';
import { formatEAT } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';

function cell(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  return String(value);
}

export default function EmailDuplicatesPage() {
  const [searchParams] = useSearchParams();
  const positionId = searchParams.get('position_id');
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const reportQuery = useQuery({
    queryKey: ['reports-email-duplicates', positionId],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<EmailDuplicatesReport>>('/reports/email-duplicates', {
        params: positionId ? { position_id: positionId } : undefined,
      });
      return response.data.data;
    },
  });

  const exportCsv = async () => {
    setExporting(true);
    setExportError(null);
    try {
      const qs = positionId ? `?position_id=${encodeURIComponent(positionId)}` : '';
      await downloadAuthorized(
        `/reports/email-duplicates/export${qs}`,
        `nck_email_duplicates_${Date.now()}.xls`,
      );
    } catch (error) {
      setExportError(getApiError(error, 'Excel export failed.'));
    } finally {
      setExporting(false);
    }
  };

  if (reportQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading same-email duplicates…</p>;
  }

  if (reportQuery.isError || !reportQuery.data) {
    return (
      <div className="space-y-3">
        <Link to="/reports" className="text-sm font-semibold text-nck-green hover:underline">
          ← Back to reports
        </Link>
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(reportQuery.error, 'Unable to load same-email duplicates.')}
        </div>
      </div>
    );
  }

  const { rows, total, groups, generated_at: generatedAt } = reportQuery.data;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link to="/reports" className="text-sm font-semibold text-nck-green hover:underline">
            ← Back to reports
          </Link>
          <h2 className="mt-3 font-display text-3xl font-semibold text-nck-slate">Duplicates</h2>
          <p className="mt-1 text-sm text-slate-600">
            {total.toLocaleString()} duplicate{total === 1 ? '' : 's'} from {groups.toLocaleString()}{' '}
            shared email{groups === 1 ? '' : 's'}
            {generatedAt ? ` · as of ${formatEAT(generatedAt)}` : ''}
          </p>
          <p className="mt-1 text-sm text-slate-500">
            Only applications that share the same email within a position. The earliest application is
            the Unique Identifier; later ones are listed here.
          </p>
        </div>
        <button
          type="button"
          disabled={exporting || total === 0}
          onClick={() => void exportCsv()}
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
              <th className="px-3 py-2">ID No</th>
              <th className="px-3 py-2">Received</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td className="px-3 py-4 text-slate-500" colSpan={9}>
                  No same-email duplicates.
                </td>
              </tr>
            )}
            {rows.map((row) => (
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
                <td className="px-3 py-2 whitespace-nowrap">{cell(row.national_id)}</td>
                <td className="px-3 py-2 whitespace-nowrap text-slate-600">{formatEAT(row.received_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
