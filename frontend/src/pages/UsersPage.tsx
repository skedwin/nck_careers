import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type LaravelPaginator,
  type User,
} from '../lib/api';

const ROLE_OPTIONS = [
  'System Administrator',
  'Recruitment Administrator',
  'Recruitment Officer',
  'Recruitment Panel Member',
  'Reviewer',
  'Read Only',
  'Auditor',
];

export default function UsersPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);

  const listQuery = useQuery({
    queryKey: ['users', page],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<User>>>('/users', {
        params: { page },
      });
      return response.data.data;
    },
  });

  const roleMutation = useMutation({
    mutationFn: async ({ userId, role }: { userId: number; role: string }) => {
      const response = await api.post(`/users/${userId}/role`, { role });
      return response.data;
    },
    onSuccess: () => {
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ['users'] });
    },
    onError: (err) => setError(getApiError(err)),
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Users</h2>
        <p className="mt-1 text-sm text-slate-600">Staff accounts and role assignments.</p>
      </div>

      {(listQuery.isError || error) && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {error || getApiError(listQuery.error, 'Unable to load users.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2">Name</th>
              <th className="px-2 py-2">Email</th>
              <th className="px-2 py-2">Department</th>
              <th className="px-2 py-2">Active</th>
              <th className="px-2 py-2">Role</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={5}>
                  Loading users…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && items.length === 0 && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={5}>
                  No users found.
                </td>
              </tr>
            )}
            {items.map((user) => {
              const currentRole = user.roles?.[0] ?? '';
              return (
                <tr key={user.id} className="border-b border-slate-100">
                  <td className="px-2 py-2 font-medium text-nck-slate">{user.display_name || user.name}</td>
                  <td className="px-2 py-2">{user.email}</td>
                  <td className="px-2 py-2">{user.department ?? '—'}</td>
                  <td className="px-2 py-2">{user.is_active === false ? 'No' : 'Yes'}</td>
                  <td className="px-2 py-2">
                    <select
                      value={currentRole}
                      disabled={roleMutation.isPending}
                      onChange={(event) =>
                        roleMutation.mutate({ userId: user.id, role: event.target.value })
                      }
                      className="rounded-lg border border-slate-200 px-2 py-1.5 text-sm"
                    >
                      {!ROLE_OPTIONS.includes(currentRole) && currentRole && (
                        <option value={currentRole}>{currentRole}</option>
                      )}
                      {ROLE_OPTIONS.map((role) => (
                        <option key={role} value={role}>
                          {role}
                        </option>
                      ))}
                    </select>
                  </td>
                </tr>
              );
            })}
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
