import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type AuditLog,
  type LaravelPaginator,
} from '../lib/api';
import { formatEAT } from '../lib/dates';

export default function AuditLogsPage() {
  const [page, setPage] = useState(1);

  const listQuery = useQuery({
    queryKey: ['audit-logs', page],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<AuditLog>>>('/audit-logs', {
        params: { page },
      });
      return response.data.data;
    },
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Audit Logs</h2>
        <p className="mt-1 text-sm text-slate-600">Immutable operational and security audit trail.</p>
      </div>

      {listQuery.isError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(listQuery.error, 'Unable to load audit logs.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2">When</th>
              <th className="px-2 py-2">User</th>
              <th className="px-2 py-2">Action</th>
              <th className="px-2 py-2">Entity</th>
              <th className="px-2 py-2">IP</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={5}>
                  Loading audit logs…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && items.length === 0 && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={5}>
                  No audit events yet.
                </td>
              </tr>
            )}
            {items.map((log) => (
              <tr key={log.id} className="border-b border-slate-100">
                <td className="px-2 py-2 whitespace-nowrap">{formatEAT(log.created_at)}</td>
                <td className="px-2 py-2">{log.user?.name || log.user?.display_name || 'System'}</td>
                <td className="px-2 py-2 font-medium text-nck-slate">{log.action}</td>
                <td className="px-2 py-2">
                  {log.entity_type ? `${log.entity_type}${log.entity_id != null ? ` #${log.entity_id}` : ''}` : '—'}
                </td>
                <td className="px-2 py-2">{log.ip_address ?? '—'}</td>
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
