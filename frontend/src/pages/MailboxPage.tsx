import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import api, { type ApiSuccess } from '../lib/api';
import { useAuth } from '../context/AuthContext';

type SyncRun = {
  id: number;
  uuid: string;
  sync_type: string;
  status: string;
  trigger: string;
  started_at?: string | null;
  finished_at?: string | null;
  messages_discovered: number;
  messages_imported: number;
  messages_skipped: number;
  messages_failed: number;
  pages_processed: number;
  error_summary?: string | null;
  has_resume_cursor?: boolean;
  inbox_total_estimate?: number | null;
  progress_percent?: number | null;
};

type MailboxStatus = {
  mailbox: string;
  mock_mode: boolean;
  credentials_configured: boolean;
  graph_base_url: string;
  read_only: boolean;
  last_check: Record<string, unknown> | null;
  sync?: {
    is_paused: boolean;
    initial_sync_completed: boolean;
    has_delta_link: boolean;
    last_successful_sync_at: string | null;
    messages_imported_total: number;
    inbox_total_estimate: number | null;
    progress_percent: number | null;
    can_continue: boolean;
    resumable_run: SyncRun | null;
    active_run: SyncRun | null;
    latest_run: SyncRun | null;
    progress_run: SyncRun | null;
    attachments?: {
      messages_with_attachments: number;
      messages_done: number;
      messages_pending: number;
      messages_queued: number;
      messages_partial: number;
      messages_failed: number;
      files_downloaded: number;
      files_failed: number;
      files_skipped: number;
      percent: number;
      storage_disk: string;
      storage_path: string;
    };
  };
};

function StatusPill({ ok, label }: { ok: boolean; label: string }) {
  return (
    <span
      className={[
        'inline-flex rounded-full px-3 py-1 text-xs font-semibold uppercase tracking-wide',
        ok ? 'bg-nck-greenLight text-nck-green' : 'bg-amber-50 text-amber-800',
      ].join(' ')}
    >
      {label}
    </span>
  );
}

function ProgressBar({ value, label }: { value: number; label: string }) {
  const clamped = Math.max(0, Math.min(100, value));
  return (
    <div>
      <div className="mb-2 flex items-center justify-between text-sm">
        <span className="font-medium text-nck-slate">{label}</span>
        <span className="tabular-nums text-slate-600">{clamped.toFixed(1)}%</span>
      </div>
      <div className="h-3 overflow-hidden rounded-full bg-slate-100" role="progressbar" aria-valuenow={clamped} aria-valuemin={0} aria-valuemax={100}>
        <div className="h-full rounded-full bg-nck-green transition-all duration-500" style={{ width: `${clamped}%` }} />
      </div>
    </div>
  );
}

export default function MailboxPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const canSync = user?.permissions?.includes('mailbox.sync') ?? false;

  const statusQuery = useQuery({
    queryKey: ['mailbox-status'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<MailboxStatus>>('/mailbox/status');
      return response.data.data;
    },
    refetchInterval: (query) => {
      const active = query.state.data?.sync?.active_run;
      const att = query.state.data?.sync?.attachments;
      const attachmentsBusy =
        !!att && ((att.messages_queued ?? 0) > 0 || (att.messages_pending ?? 0) > 0 || (att.messages_failed ?? 0) > 0);
      if (active && ['pending', 'running'].includes(active.status)) return 2500;
      if (attachmentsBusy && (att?.messages_queued ?? 0) > 0) return 4000;
      return false;
    },
  });

  const logsQuery = useQuery({
    queryKey: ['mailbox-logs'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<{ items: SyncRun[]; meta: { total: number } }>>('/mailbox/logs?per_page=20');
      return response.data.data;
    },
    enabled: canSync,
    refetchInterval: statusQuery.data?.sync?.active_run ? 2500 : false,
  });

  const invalidate = () => {
    void queryClient.invalidateQueries({ queryKey: ['mailbox-status'] });
    void queryClient.invalidateQueries({ queryKey: ['mailbox-logs'] });
  };

  const testMutation = useMutation({
    mutationFn: async () => api.post('/mailbox/test-connection'),
    onSuccess: invalidate,
  });

  const syncAllMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<ApiSuccess<SyncRun>>('/mailbox/sync', { type: 'initial' });
      return response.data.data;
    },
    onSuccess: invalidate,
  });

  const continueMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<ApiSuccess<SyncRun>>('/mailbox/sync/continue');
      return response.data.data;
    },
    onSuccess: invalidate,
  });

  const pauseMutation = useMutation({
    mutationFn: async () => api.post('/mailbox/sync/pause'),
    onSuccess: invalidate,
  });

  const resumePauseMutation = useMutation({
    mutationFn: async () => api.post('/mailbox/sync/resume'),
    onSuccess: invalidate,
  });

  const downloadAttachmentsMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<ApiSuccess<{ queued: number }>>('/mailbox/attachments/download', { limit: 100 });
      return response.data;
    },
    onSuccess: invalidate,
  });

  const data = statusQuery.data;
  const sync = data?.sync;
  const run = sync?.progress_run ?? sync?.active_run ?? sync?.latest_run;
  const isRunning = !!sync?.active_run && ['pending', 'running'].includes(sync.active_run.status);
  const mutationError = (error: unknown) =>
    (error as { response?: { data?: { message?: string } } })?.response?.data?.message;

  const overallProgress =
    run?.progress_percent ??
    sync?.progress_percent ??
    (sync?.inbox_total_estimate
      ? Math.min(100, ((sync.messages_imported_total ?? 0) / sync.inbox_total_estimate) * 100)
      : 0);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">Mailbox sync</h2>
          <p className="mt-1 max-w-2xl text-sm text-slate-600">
            Import all historical messages from careers@nckenya.go.ke. Graph timeouts are retried automatically;
            use Continue sync to resume after a failure without starting over.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <button
            type="button"
            disabled={!canSync || testMutation.isPending}
            onClick={() => testMutation.mutate()}
            className="rounded-xl border border-nck-green/20 bg-white px-4 py-2.5 text-sm font-semibold text-nck-green hover:bg-nck-greenLight disabled:opacity-60"
          >
            Test connection
          </button>
          {sync?.is_paused ? (
            <button
              type="button"
              disabled={!canSync || resumePauseMutation.isPending}
              onClick={() => resumePauseMutation.mutate()}
              className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-900 disabled:opacity-60"
            >
              Unpause
            </button>
          ) : (
            <button
              type="button"
              disabled={!canSync || pauseMutation.isPending || !isRunning}
              onClick={() => pauseMutation.mutate()}
              className="rounded-xl border border-slate-200 bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 disabled:opacity-60"
            >
              Pause
            </button>
          )}
          {sync?.can_continue && (
            <button
              type="button"
              disabled={!canSync || continueMutation.isPending || isRunning}
              onClick={() => continueMutation.mutate()}
              className="rounded-xl border border-nck-gold/40 bg-[#fbf7ef] px-4 py-2.5 text-sm font-semibold text-[#7a5b16] disabled:opacity-60"
            >
              {continueMutation.isPending ? 'Resuming…' : 'Continue sync'}
            </button>
          )}
          <button
            type="button"
            disabled={!canSync || downloadAttachmentsMutation.isPending}
            onClick={() => downloadAttachmentsMutation.mutate()}
            className="rounded-xl border border-nck-green/20 bg-white px-4 py-2.5 text-sm font-semibold text-nck-green hover:bg-nck-greenLight disabled:opacity-60"
          >
            {downloadAttachmentsMutation.isPending ? 'Queuing…' : 'Download all attachments'}
          </button>
          <button
            type="button"
            disabled={!canSync || syncAllMutation.isPending || isRunning || !!sync?.is_paused}
            onClick={() => {
              if (window.confirm('Start a full historical sync of all mailbox messages?')) {
                syncAllMutation.mutate();
              }
            }}
            className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
          >
            {syncAllMutation.isPending ? 'Starting…' : isRunning ? 'Syncing…' : 'Sync all data'}
          </button>
        </div>
      </div>

      {!canSync && (
        <div className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900" role="status">
          Your account ({user?.email}) does not have the <strong>mailbox.sync</strong> permission.
          Sign out and sign in again with Microsoft — the first NCK admin is upgraded to System Administrator.
          Current roles: {(user?.roles ?? []).join(', ') || 'none'}.
        </div>
      )}

      {statusQuery.isLoading && <p className="text-sm text-slate-600">Loading mailbox status…</p>}
      {statusQuery.isError && (
        <p className="text-sm text-red-600">Unable to load mailbox status. Confirm you have access.</p>
      )}

      {data && (
        <>
          <div className="rounded-2xl border border-nck-green/10 bg-white p-6 shadow-sm">
            <div className="mb-4 flex flex-wrap items-center gap-2">
              <StatusPill ok={!data.mock_mode} label={data.mock_mode ? 'Mock' : 'Live'} />
              <StatusPill ok={!sync?.is_paused} label={sync?.is_paused ? 'Paused' : 'Ready'} />
              <StatusPill ok={isRunning} label={isRunning ? 'In progress' : run?.status ?? 'Idle'} />
              {sync?.can_continue && <StatusPill ok={false} label="Resumable cursor saved" />}
            </div>

            <ProgressBar
              value={overallProgress}
              label={
                sync?.inbox_total_estimate
                  ? `Mailbox import progress (${sync.messages_imported_total.toLocaleString()} / ${sync.inbox_total_estimate.toLocaleString()})`
                  : `Mailbox import progress (${sync?.messages_imported_total?.toLocaleString() ?? 0} imported)`
              }
            />

            <div className="mt-5 grid gap-3 text-sm text-nck-slate sm:grid-cols-2 lg:grid-cols-4">
              <div className="rounded-xl bg-nck-mist p-3">
                <p className="text-xs uppercase tracking-wide text-slate-500">Discovered</p>
                <p className="mt-1 font-display text-2xl font-semibold">{run?.messages_discovered ?? 0}</p>
              </div>
              <div className="rounded-xl bg-nck-mist p-3">
                <p className="text-xs uppercase tracking-wide text-slate-500">Imported</p>
                <p className="mt-1 font-display text-2xl font-semibold">{run?.messages_imported ?? 0}</p>
              </div>
              <div className="rounded-xl bg-nck-mist p-3">
                <p className="text-xs uppercase tracking-wide text-slate-500">Skipped</p>
                <p className="mt-1 font-display text-2xl font-semibold">{run?.messages_skipped ?? 0}</p>
              </div>
              <div className="rounded-xl bg-nck-mist p-3">
                <p className="text-xs uppercase tracking-wide text-slate-500">Pages</p>
                <p className="mt-1 font-display text-2xl font-semibold">{run?.pages_processed ?? 0}</p>
              </div>
            </div>

            {run?.error_summary && (
              <div className="mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
                <p className="font-semibold">Sync error</p>
                <p className="mt-1 break-words">{run.error_summary}</p>
                {sync?.can_continue && (
                  <p className="mt-2">A resume cursor was saved. Click <strong>Continue sync</strong> to keep importing.</p>
                )}
              </div>
            )}

            <p className="mt-4 text-xs text-slate-500">
              Keep a queue worker running while syncing:
              <code className="ml-1 rounded bg-slate-100 px-1.5 py-0.5">php artisan queue:work --queue=mail-sync,mail-import,default</code>
            </p>
          </div>

          {sync?.attachments && (
            <div className="rounded-2xl border border-nck-green/10 bg-white p-6 shadow-sm">
              <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Attachment downloads</h3>
              <p className="mt-1 text-sm text-slate-600">
                Files are stored privately on disk at{' '}
                <code className="rounded bg-slate-100 px-1.5 py-0.5 text-xs">
                  backend/storage/app/private/mail-attachments/
                </code>
              </p>
              <div className="mt-4">
                <ProgressBar
                  value={sync.attachments.percent}
                  label={`Attachment progress (${sync.attachments.messages_done.toLocaleString()} / ${sync.attachments.messages_with_attachments.toLocaleString()} messages)`}
                />
              </div>
              <div className="mt-5 grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div className="rounded-xl bg-nck-mist p-3">
                  <p className="text-xs uppercase tracking-wide text-slate-500">Files downloaded</p>
                  <p className="mt-1 font-display text-2xl font-semibold text-nck-green">
                    {sync.attachments.files_downloaded.toLocaleString()}
                  </p>
                </div>
                <div className="rounded-xl bg-nck-mist p-3">
                  <p className="text-xs uppercase tracking-wide text-slate-500">Queued / pending</p>
                  <p className="mt-1 font-display text-2xl font-semibold">
                    {(sync.attachments.messages_queued + sync.attachments.messages_pending).toLocaleString()}
                  </p>
                </div>
                <div className="rounded-xl bg-nck-mist p-3">
                  <p className="text-xs uppercase tracking-wide text-slate-500">Partial</p>
                  <p className="mt-1 font-display text-2xl font-semibold">{sync.attachments.messages_partial.toLocaleString()}</p>
                </div>
                <div className="rounded-xl bg-nck-mist p-3">
                  <p className="text-xs uppercase tracking-wide text-slate-500">Failed</p>
                  <p className="mt-1 font-display text-2xl font-semibold text-amber-800">
                    {sync.attachments.messages_failed.toLocaleString()}
                  </p>
                </div>
              </div>
              {(downloadAttachmentsMutation.data || downloadAttachmentsMutation.isError) && (
                <p className="mt-3 text-sm text-slate-600">
                  {downloadAttachmentsMutation.isError
                    ? 'Could not queue downloads.'
                    : `Queued ${downloadAttachmentsMutation.data?.data?.queued ?? 0} message(s) for attachment download.`}
                </p>
              )}
            </div>
          )}

          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <div className="rounded-2xl border border-nck-green/10 bg-white p-5">
              <p className="text-xs uppercase tracking-[0.16em] text-slate-500">Mailbox</p>
              <p className="mt-2 break-all text-sm font-semibold text-nck-slate">{data.mailbox}</p>
            </div>
            <div className="rounded-2xl border border-nck-green/10 bg-white p-5">
              <p className="text-xs uppercase tracking-[0.16em] text-slate-500">Inbox estimate</p>
              <p className="mt-2 font-display text-3xl font-semibold text-nck-green">
                {sync?.inbox_total_estimate?.toLocaleString() ?? '—'}
              </p>
            </div>
            <div className="rounded-2xl border border-nck-green/10 bg-white p-5">
              <p className="text-xs uppercase tracking-[0.16em] text-slate-500">Imported total</p>
              <p className="mt-2 font-display text-3xl font-semibold text-nck-green">
                {sync?.messages_imported_total?.toLocaleString() ?? 0}
              </p>
            </div>
            <div className="rounded-2xl border border-nck-green/10 bg-white p-5">
              <p className="text-xs uppercase tracking-[0.16em] text-slate-500">Last success</p>
              <p className="mt-2 text-sm font-semibold text-nck-slate">{sync?.last_successful_sync_at ?? '—'}</p>
            </div>
          </div>

          <div className="rounded-2xl border border-nck-green/10 bg-white p-5">
            <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Sync logs</h3>
            <div className="mt-3 overflow-x-auto">
              <table className="min-w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-2 py-2">ID</th>
                    <th className="px-2 py-2">Type</th>
                    <th className="px-2 py-2">Status</th>
                    <th className="px-2 py-2">Discovered</th>
                    <th className="px-2 py-2">Imported</th>
                    <th className="px-2 py-2">Skipped</th>
                    <th className="px-2 py-2">Pages</th>
                    <th className="px-2 py-2">Resume</th>
                  </tr>
                </thead>
                <tbody>
                  {(logsQuery.data?.items ?? []).map((item) => (
                    <tr key={item.id} className="border-b border-slate-100">
                      <td className="px-2 py-2">{item.id}</td>
                      <td className="px-2 py-2">{item.sync_type}</td>
                      <td className="px-2 py-2">{item.status}</td>
                      <td className="px-2 py-2">{item.messages_discovered}</td>
                      <td className="px-2 py-2">{item.messages_imported}</td>
                      <td className="px-2 py-2">{item.messages_skipped}</td>
                      <td className="px-2 py-2">{item.pages_processed}</td>
                      <td className="px-2 py-2">{item.has_resume_cursor ? 'Yes' : '—'}</td>
                    </tr>
                  ))}
                  {(logsQuery.data?.items?.length ?? 0) === 0 && (
                    <tr>
                      <td className="px-2 py-4 text-slate-500" colSpan={8}>
                        No sync logs yet. Click Sync all data to begin.
                      </td>
                    </tr>
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </>
      )}

      {(mutationError(testMutation.error) ||
        mutationError(syncAllMutation.error) ||
        mutationError(continueMutation.error)) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {mutationError(testMutation.error) ||
            mutationError(syncAllMutation.error) ||
            mutationError(continueMutation.error)}
        </div>
      )}
    </div>
  );
}
