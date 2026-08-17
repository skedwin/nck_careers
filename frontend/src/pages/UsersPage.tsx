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
  'Report Viewer',
  'Auditor',
];

type PasswordTarget = {
  id: number;
  name: string;
  email: string;
};

export default function UsersPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [error, setError] = useState<string | null>(null);
  const [passwordUser, setPasswordUser] = useState<PasswordTarget | null>(null);
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [generatedPassword, setGeneratedPassword] = useState<string | null>(null);

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

  const passwordMutation = useMutation({
    mutationFn: async (payload: { userId: number; generate?: boolean; password?: string }) => {
      const response = await api.post<ApiSuccess<{ id: number; email: string; password: string | null }>>(
        `/users/${payload.userId}/password`,
        payload.generate
          ? { generate: true }
          : { password: payload.password, password_confirmation: payload.password },
      );
      return response.data;
    },
    onSuccess: (data) => {
      setError(null);
      void queryClient.invalidateQueries({ queryKey: ['users'] });
      if (data.data.password) {
        setGeneratedPassword(data.data.password);
        setPassword('');
        setPasswordConfirmation('');
      } else {
        setPasswordUser(null);
        setPassword('');
        setPasswordConfirmation('');
        setGeneratedPassword(null);
      }
    },
    onError: (err) => setError(getApiError(err)),
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  const closePasswordModal = () => {
    setPasswordUser(null);
    setPassword('');
    setPasswordConfirmation('');
    setGeneratedPassword(null);
  };

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Users</h2>
        <p className="mt-1 text-sm text-slate-600">Staff accounts, roles, and password login.</p>
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
              <th className="px-2 py-2">Password</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={6}>
                  Loading users…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && items.length === 0 && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={6}>
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
                  <td className="px-2 py-2">
                    <button
                      type="button"
                      className="rounded-lg border border-nck-green/20 px-3 py-1.5 text-xs font-semibold text-nck-green hover:bg-nck-greenLight"
                      onClick={() => {
                        setError(null);
                        setGeneratedPassword(null);
                        setPassword('');
                        setPasswordConfirmation('');
                        setPasswordUser({
                          id: user.id,
                          name: user.display_name || user.name,
                          email: user.email,
                        });
                      }}
                    >
                      {user.has_password ? 'Change' : 'Set'}
                    </button>
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

      {passwordUser && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <div className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h3 className="font-display text-xl font-semibold text-nck-slate">Set password</h3>
            <p className="mt-1 text-sm text-slate-600">
              {passwordUser.name} · {passwordUser.email}
            </p>
            <p className="mt-2 text-xs text-slate-500">Minimum 10 characters. They will need to sign in again.</p>

            {generatedPassword ? (
              <div className="mt-4 rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-3">
                <p className="text-xs font-semibold uppercase tracking-wide text-nck-green">Generated password</p>
                <p className="mt-2 break-all font-mono text-sm font-semibold text-nck-slate">{generatedPassword}</p>
                <p className="mt-2 text-xs text-slate-600">Save this now. It will not be shown again.</p>
              </div>
            ) : (
              <form
                className="mt-4 space-y-3"
                onSubmit={(event) => {
                  event.preventDefault();
                  if (password !== passwordConfirmation) {
                    setError('Password confirmation does not match.');
                    return;
                  }
                  passwordMutation.mutate({ userId: passwordUser.id, password });
                }}
              >
                <label className="block space-y-1 text-sm">
                  <span className="text-slate-600">New password</span>
                  <input
                    type="password"
                    value={password}
                    onChange={(event) => setPassword(event.target.value)}
                    className="w-full rounded-xl border border-slate-200 px-3 py-2"
                    minLength={10}
                    required
                    autoComplete="new-password"
                  />
                </label>
                <label className="block space-y-1 text-sm">
                  <span className="text-slate-600">Confirm password</span>
                  <input
                    type="password"
                    value={passwordConfirmation}
                    onChange={(event) => setPasswordConfirmation(event.target.value)}
                    className="w-full rounded-xl border border-slate-200 px-3 py-2"
                    minLength={10}
                    required
                    autoComplete="new-password"
                  />
                </label>
                <div className="flex flex-wrap justify-end gap-2 pt-2">
                  <button
                    type="button"
                    className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-600"
                    onClick={closePasswordModal}
                  >
                    Cancel
                  </button>
                  <button
                    type="button"
                    disabled={passwordMutation.isPending}
                    className="rounded-xl border border-nck-green/20 px-4 py-2 text-sm font-semibold text-nck-green disabled:opacity-60"
                    onClick={() => passwordMutation.mutate({ userId: passwordUser.id, generate: true })}
                  >
                    Generate
                  </button>
                  <button
                    type="submit"
                    disabled={passwordMutation.isPending}
                    className="rounded-xl bg-nck-green px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
                  >
                    {passwordMutation.isPending ? 'Saving…' : 'Save password'}
                  </button>
                </div>
              </form>
            )}

            {generatedPassword && (
              <div className="mt-4 flex justify-end">
                <button
                  type="button"
                  className="rounded-xl bg-nck-green px-4 py-2 text-sm font-semibold text-white"
                  onClick={closePasswordModal}
                >
                  Done
                </button>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
