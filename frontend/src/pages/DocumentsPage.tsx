import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type ApplicationDocument,
  type LaravelPaginator,
  type MailAttachment,
} from '../lib/api';
import { formatEAT } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';

export default function DocumentsPage() {
  const queryClient = useQueryClient();
  const [tab, setTab] = useState<'documents' | 'attachments'>('documents');
  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [error, setError] = useState<string | null>(null);

  const docsQuery = useQuery({
    queryKey: ['documents', page, search],
    enabled: tab === 'documents',
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<ApplicationDocument>>>('/documents', {
        params: { page, q: search || undefined },
      });
      return response.data.data;
    },
  });

  const attachmentsQuery = useQuery({
    queryKey: ['mail-attachments', page],
    enabled: tab === 'attachments',
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<MailAttachment>>>('/mail-attachments', {
        params: { page },
      });
      return response.data.data;
    },
  });

  const queueMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post('/mailbox/attachments/download', { limit: 50 });
      return response.data;
    },
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['mail-attachments'] });
    },
  });

  const active = tab === 'documents' ? docsQuery : attachmentsQuery;
  const meta = pageMeta(active.data);

  const handleDownload = async (path: string, filename: string) => {
    setError(null);
    try {
      await downloadAuthorized(path, filename);
    } catch (err) {
      setError(getApiError(err, 'Download failed.'));
    }
  };

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">Documents</h2>
          <p className="mt-1 text-sm text-slate-600">
            Private application documents and mailbox attachments with authorized download.
          </p>
        </div>
        {tab === 'attachments' && (
          <button
            type="button"
            disabled={queueMutation.isPending}
            onClick={() => queueMutation.mutate()}
            className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
          >
            {queueMutation.isPending ? 'Queuing…' : 'Queue pending downloads'}
          </button>
        )}
      </div>

      <div className="flex flex-wrap gap-2">
        {(['documents', 'attachments'] as const).map((value) => (
          <button
            key={value}
            type="button"
            onClick={() => {
              setTab(value);
              setPage(1);
              setError(null);
            }}
            className={[
              'rounded-xl px-4 py-2 text-sm font-semibold',
              tab === value
                ? 'bg-nck-green text-white'
                : 'border border-nck-green/20 bg-white text-nck-green',
            ].join(' ')}
          >
            {value === 'documents' ? 'Application documents' : 'Mail attachments'}
          </button>
        ))}
      </div>

      {tab === 'documents' && (
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            setPage(1);
            setSearch(q.trim());
          }}
        >
          <input
            value={q}
            onChange={(event) => setQ(event.target.value)}
            placeholder="Search documents…"
            className="min-w-[220px] flex-1 rounded-xl border border-slate-200 px-3 py-2 text-sm"
          />
          <button
            type="submit"
            className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2 text-sm font-semibold text-nck-green"
          >
            Search
          </button>
        </form>
      )}

      {(active.isError || error || queueMutation.isError) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {error || getApiError(active.error || queueMutation.error, 'Unable to load documents.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        {tab === 'documents' ? (
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-2 py-2">File</th>
                <th className="px-2 py-2">Application</th>
                <th className="px-2 py-2">Applicant</th>
                <th className="px-2 py-2">Type</th>
                <th className="px-2 py-2">Created</th>
                <th className="px-2 py-2">Action</th>
              </tr>
            </thead>
            <tbody>
              {docsQuery.isLoading && (
                <tr>
                  <td className="px-2 py-4 text-slate-500" colSpan={6}>
                    Loading…
                  </td>
                </tr>
              )}
              {!docsQuery.isLoading && asList(docsQuery.data).length === 0 && (
                <tr>
                  <td className="px-2 py-4 text-slate-500" colSpan={6}>
                    No application documents yet.
                  </td>
                </tr>
              )}
              {asList(docsQuery.data).map((doc) => {
                const isCloud = Boolean(doc.external_url) && !doc.path;
                return (
                  <tr key={doc.id} className="border-b border-slate-100">
                    <td className="px-2 py-2">
                      {doc.original_name}
                      {doc.provider ? (
                        <span className="ml-2 text-xs uppercase tracking-wide text-slate-500">
                          {doc.provider.replace('_', ' ')}
                        </span>
                      ) : null}
                    </td>
                    <td className="px-2 py-2">{doc.application?.application_reference ?? doc.application_id}</td>
                    <td className="px-2 py-2">{doc.application?.applicant?.full_name ?? '—'}</td>
                    <td className="px-2 py-2">{doc.document_type}</td>
                    <td className="px-2 py-2 whitespace-nowrap">{formatEAT(doc.created_at)}</td>
                    <td className="px-2 py-2">
                      {isCloud && doc.external_url ? (
                        <a
                          href={doc.external_url}
                          target="_blank"
                          rel="noreferrer"
                          className="font-semibold text-nck-green hover:underline"
                        >
                          Open link
                        </a>
                      ) : (
                        <button
                          type="button"
                          className="font-semibold text-nck-green hover:underline"
                          onClick={() => void handleDownload(`/documents/${doc.id}/download`, doc.original_name)}
                        >
                          Download
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        ) : (
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-2 py-2">File</th>
                <th className="px-2 py-2">Subject</th>
                <th className="px-2 py-2">Status</th>
                <th className="px-2 py-2">Size</th>
                <th className="px-2 py-2">Action</th>
              </tr>
            </thead>
            <tbody>
              {attachmentsQuery.isLoading && (
                <tr>
                  <td className="px-2 py-4 text-slate-500" colSpan={5}>
                    Loading…
                  </td>
                </tr>
              )}
              {!attachmentsQuery.isLoading && asList(attachmentsQuery.data).length === 0 && (
                <tr>
                  <td className="px-2 py-4 text-slate-500" colSpan={5}>
                    No mail attachments yet.
                  </td>
                </tr>
              )}
              {asList(attachmentsQuery.data).map((attachment) => {
                const isLinkOnly =
                  attachment.download_status === 'link_only' ||
                  (Boolean(attachment.external_url) && attachment.download_status !== 'downloaded');
                return (
                  <tr key={attachment.id} className="border-b border-slate-100">
                    <td className="px-2 py-2">
                      {attachment.name}
                      {attachment.provider ? (
                        <span className="ml-2 text-xs uppercase tracking-wide text-slate-500">
                          {attachment.provider.replace('_', ' ')}
                        </span>
                      ) : null}
                    </td>
                    <td className="px-2 py-2">{attachment.mail_message?.subject ?? '—'}</td>
                    <td className="px-2 py-2">{attachment.download_status}</td>
                    <td className="px-2 py-2">
                      {attachment.size ? `${Math.round(attachment.size / 1024)} KB` : '—'}
                    </td>
                    <td className="px-2 py-2">
                      {isLinkOnly && attachment.external_url ? (
                        <a
                          href={attachment.external_url}
                          target="_blank"
                          rel="noreferrer"
                          className="font-semibold text-nck-green hover:underline"
                        >
                          Open link
                        </a>
                      ) : (
                        <button
                          type="button"
                          disabled={attachment.download_status !== 'downloaded'}
                          className="font-semibold text-nck-green hover:underline disabled:opacity-40"
                          onClick={() =>
                            void handleDownload(`/mail-attachments/${attachment.id}/download`, attachment.name)
                          }
                        >
                          Download
                        </button>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        )}

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
