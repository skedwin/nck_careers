import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useSearchParams } from 'react-router-dom';
import api, { getApiError, type ApiSuccess, type MyJobsListing } from '../lib/api';
import { formatEAT } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';
import { useAuth } from '../context/AuthContext';

const EXISTENCE_FILTERS = [
  { value: '', label: 'All MyJobs applicants' },
  { value: 'both', label: 'In MyJobs and email lists' },
  { value: 'myjobs_only', label: 'MyJobs portal only' },
  { value: 'in_system', label: 'Already in system' },
  { value: 'missing', label: 'Not in system' },
  { value: 'email', label: 'Matched by email' },
  { value: 'name', label: 'Matched by name' },
  { value: 'name_only', label: 'Name match only' },
  { value: 'email_only', label: 'Email match only' },
] as const;

function cell(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  return String(value);
}

export default function MyJobsPage() {
  const { user } = useAuth();
  const canImport =
    (user?.permissions?.includes('applications.create') ?? false) ||
    (user?.permissions?.includes('applications.update') ?? false);
  const queryClient = useQueryClient();
  const [searchParams, setSearchParams] = useSearchParams();
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [file, setFile] = useState('');
  const [existence, setExistence] = useState(searchParams.get('existence') ?? '');
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const queryKey = useMemo(
    () => ['myjobs', page, search, file, existence] as const,
    [page, search, file, existence],
  );

  const listQuery = useQuery({
    queryKey,
    queryFn: async () => {
      const response = await api.get<ApiSuccess<MyJobsListing>>('/myjobs', {
        params: {
          page,
          per_page: 25,
          q: search || undefined,
          file: file || undefined,
          existence: existence || undefined,
        },
      });
      return response.data.data;
    },
  });

  const exportCsv = async () => {
    setExporting(true);
    setExportError(null);
    try {
      const params = new URLSearchParams();
      if (search) params.set('q', search);
      if (file) params.set('file', file);
      if (existence) params.set('existence', existence);
      const qs = params.toString();
      await downloadAuthorized(`/myjobs/export${qs ? `?${qs}` : ''}`, `nck_myjobs_${Date.now()}.xls`);
    } catch (error) {
      setExportError(getApiError(error, 'Excel export failed.'));
    } finally {
      setExporting(false);
    }
  };

  const importMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<
        ApiSuccess<{ created: number; enriched: number; skipped: number; failed: number; dry_run: boolean }>
      >('/myjobs/import');
      return response.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['myjobs'] });
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-long-listing'] });
    },
  });

  const linkMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<
        ApiSuccess<{
          zips_extracted: number;
          packs: number;
          linked: number;
          documents: number;
          unmatched: number;
          ambiguous: number;
          skipped: number;
        }>
      >('/myjobs/link-attachments');
      return response.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
      void queryClient.invalidateQueries({ queryKey: ['documents'] });
    },
  });

  const data = listQuery.data;
  const rows = data?.rows ?? [];
  const meta = data?.meta;
  const totals = data?.totals;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">MyJobs</h2>
          <p className="mt-1 text-sm text-slate-600">
            Applicants submitted through My Jobs In Kenya. Import creates MyJobs applications and fills
            profiles from the spreadsheet. Use the filters to see people who also applied by email, or who
            appear only on the MyJobs portal.
            {data?.generated_at ? ` As of ${formatEAT(data.generated_at)}.` : ''}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          {canImport && (
            <button
              type="button"
              disabled={importMutation.isPending}
              onClick={() => importMutation.mutate()}
              className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2.5 text-sm font-semibold text-nck-green disabled:opacity-60"
            >
              {importMutation.isPending ? 'Importing…' : 'Import as applications'}
            </button>
          )}
          {canImport && (
            <button
              type="button"
              disabled={linkMutation.isPending}
              onClick={() => linkMutation.mutate()}
              className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2.5 text-sm font-semibold text-nck-green disabled:opacity-60"
            >
              {linkMutation.isPending ? 'Linking…' : 'Link attachments'}
            </button>
          )}
          <button
            type="button"
            disabled={exporting || !data}
            onClick={() => void exportCsv()}
            className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
          >
            {exporting ? 'Exporting…' : 'Export Excel'}
          </button>
        </div>
      </div>

      {totals && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <button
            type="button"
            onClick={() => {
              setExistence('');
              setPage(1);
              setSearchParams({});
            }}
            className="rounded-2xl border border-nck-green/10 bg-white p-4 text-left shadow-sm hover:border-nck-green/30"
          >
            <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Listed on MyJobs</p>
            <p className="mt-2 font-display text-3xl font-semibold text-nck-green">
              {totals.listed.toLocaleString()}
            </p>
          </button>
          <button
            type="button"
            onClick={() => {
              setExistence('both');
              setPage(1);
              setSearchParams({ existence: 'both' });
            }}
            className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-left shadow-sm hover:border-amber-400"
          >
            <p className="text-xs uppercase tracking-[0.14em] text-amber-800">In MyJobs and email</p>
            <p className="mt-2 font-display text-3xl font-semibold text-amber-900">
              {(totals.also_in_mailbox ?? 0).toLocaleString()}
            </p>
          </button>
          <button
            type="button"
            onClick={() => {
              setExistence('myjobs_only');
              setPage(1);
              setSearchParams({ existence: 'myjobs_only' });
            }}
            className="rounded-2xl border border-nck-green/10 bg-white p-4 text-left shadow-sm hover:border-nck-green/30"
          >
            <p className="text-xs uppercase tracking-[0.14em] text-slate-500">MyJobs portal only</p>
            <p className="mt-2 font-display text-3xl font-semibold text-nck-green">
              {(totals.myjobs_only ?? 0).toLocaleString()}
            </p>
          </button>
          <div className="rounded-2xl border border-nck-green/10 bg-white p-4 shadow-sm">
            <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Not yet in system</p>
            <p className="mt-2 font-display text-3xl font-semibold text-amber-800">
              {totals.missing.toLocaleString()}
            </p>
          </div>
        </div>
      )}

      <form
        className="flex flex-wrap gap-3 rounded-2xl border border-nck-green/10 bg-white p-4"
        onSubmit={(event) => {
          event.preventDefault();
          setPage(1);
          setSearch(q.trim());
        }}
      >
        <input
          value={q}
          onChange={(event) => setQ(event.target.value)}
          placeholder="Search name, email, phone…"
          className="min-w-[220px] flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm"
        />
        <select
          value={file}
          onChange={(event) => {
            setFile(event.target.value);
            setPage(1);
          }}
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        >
          <option value="">All MyJobs files</option>
          {(data?.files ?? []).map((item) => (
            <option key={item.file} value={item.file}>
              {item.position_code ? `${item.position_code} · ` : ''}
              {item.file.replace(/\.xlsx$/i, '')} ({item.listed})
            </option>
          ))}
        </select>
        <select
          value={existence}
          onChange={(event) => {
            const value = event.target.value;
            setExistence(value);
            setPage(1);
            if (value) {
              setSearchParams({ existence: value });
            } else {
              setSearchParams({});
            }
          }}
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        >
          {EXISTENCE_FILTERS.map((option) => (
            <option key={option.value || 'all'} value={option.value}>
              {option.label}
            </option>
          ))}
        </select>
        <button
          type="submit"
          className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2 text-sm font-semibold text-nck-green"
        >
          Search
        </button>
      </form>

      {(importMutation.isSuccess || importMutation.isError) && (
        <div
          className={`rounded-xl border px-4 py-3 text-sm ${
            importMutation.isError
              ? 'border-red-200 bg-red-50 text-red-700'
              : 'border-nck-green/20 bg-nck-greenLight text-nck-green'
          }`}
          role={importMutation.isError ? 'alert' : 'status'}
        >
          {importMutation.isError
            ? getApiError(importMutation.error, 'MyJobs import failed.')
            : `Imported MyJobs applications: created ${importMutation.data.data.created}, enriched ${importMutation.data.data.enriched}, skipped ${importMutation.data.data.skipped}, failed ${importMutation.data.data.failed}.`}
        </div>
      )}

      {(linkMutation.isSuccess || linkMutation.isError) && (
        <div
          className={`rounded-xl px-4 py-3 text-sm ${
            linkMutation.isError
              ? 'border border-red-200 bg-red-50 text-red-700'
              : 'border border-nck-green/20 bg-nck-greenLight text-nck-green'
          }`}
          role={linkMutation.isError ? 'alert' : 'status'}
        >
          {linkMutation.isError
            ? getApiError(linkMutation.error, 'Linking MyJobs attachments failed.')
            : `Linked MyJobs attachments: ${linkMutation.data.data.linked} applications, ${linkMutation.data.data.documents} files, ${linkMutation.data.data.unmatched} unmatched, ${linkMutation.data.data.ambiguous} ambiguous.`}
        </div>
      )}

      {(listQuery.isError || exportError) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {exportError || getApiError(listQuery.error, 'Unable to load MyJobs list.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white shadow-sm">
        <table className="min-w-[1200px] w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-nck-mist/60 text-[11px] uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-3 py-2">SN.</th>
              <th className="px-3 py-2">MyJobs applicant</th>
              <th className="px-3 py-2">Email / Phone</th>
              <th className="px-3 py-2">Score</th>
              <th className="px-3 py-2">MyJobs list</th>
              <th className="px-3 py-2">Channel</th>
              <th className="px-3 py-2">Email application</th>
              <th className="px-3 py-2">MyJobs application</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-3 py-4 text-slate-500" colSpan={8}>
                  Loading MyJobs applicants…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && rows.length === 0 && (
              <tr>
                <td className="px-3 py-4 text-slate-500" colSpan={8}>
                  No MyJobs rows match these filters.
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr key={`${row.file}-${row.serial_no}-${row.email ?? row.name}`} className="border-b border-slate-100 align-top">
                <td className="px-3 py-2 tabular-nums text-slate-600">{row.serial_no}</td>
                <td className="px-3 py-2">
                  <p className="font-medium text-nck-slate">{cell(row.name)}</p>
                  <p className="text-xs text-slate-500">{cell(row.education)}</p>
                  {(row.gender || row.age) && (
                    <p className="text-xs text-slate-500">
                      {[row.gender, row.age].filter(Boolean).join(' · ')}
                    </p>
                  )}
                </td>
                <td className="px-3 py-2">
                  <p>{cell(row.email)}</p>
                  <p className="text-xs text-slate-500">{cell(row.phone)}</p>
                </td>
                <td className="px-3 py-2 tabular-nums font-semibold text-nck-slate">{cell(row.score)}</td>
                <td className="px-3 py-2">
                  <p className="font-semibold text-nck-green">{cell(row.mapped_position_code)}</p>
                  <p className="text-xs text-slate-500">{row.file.replace(/\.xlsx$/i, '')}</p>
                </td>
                <td className="px-3 py-2">
                  {row.also_in_mailbox ? (
                    <span className="inline-flex rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-900">
                      MyJobs + email
                    </span>
                  ) : (
                    <span className="inline-flex rounded-full bg-nck-greenLight px-2 py-0.5 text-xs font-semibold text-nck-green">
                      MyJobs only
                    </span>
                  )}
                </td>
                <td className="px-3 py-2">
                  {(row.mailbox_applications ?? []).length === 0 && <span className="text-slate-500">—</span>}
                  {(row.mailbox_applications ?? []).map((application) => (
                    <p key={application.application_id} className="text-xs">
                      <Link
                        className="font-semibold text-nck-green hover:underline"
                        to={`/applications/${application.application_id}`}
                      >
                        {application.application_reference}
                      </Link>
                    </p>
                  ))}
                </td>
                <td className="px-3 py-2">
                  {(row.myjobs_applications ?? []).length === 0 && <span className="text-slate-500">—</span>}
                  {(row.myjobs_applications ?? []).map((application) => (
                    <p key={application.application_id} className="text-xs">
                      <Link
                        className="font-semibold text-nck-green hover:underline"
                        to={`/applications/${application.application_id}`}
                      >
                        {application.application_reference}
                      </Link>
                    </p>
                  ))}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {meta && meta.last_page > 1 && (
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
          <p className="text-slate-600">
            Showing {meta.from ?? 0}–{meta.to ?? 0} of {meta.total.toLocaleString()}
          </p>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((current) => Math.max(1, current - 1))}
              className="rounded-xl border border-nck-green/20 px-3 py-1.5 font-semibold text-nck-green disabled:opacity-40"
            >
              Previous
            </button>
            <button
              type="button"
              disabled={page >= meta.last_page}
              onClick={() => setPage((current) => current + 1)}
              className="rounded-xl border border-nck-green/20 px-3 py-1.5 font-semibold text-nck-green disabled:opacity-40"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
