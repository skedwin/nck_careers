import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useAuth } from '../context/AuthContext';
import api, { getApiError, type ApiSuccess } from '../lib/api';

export default function SettingsPage() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const canManage = user?.permissions?.includes('settings.manage') ?? false;
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  const [message, setMessage] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  const settingsQuery = useQuery({
    queryKey: ['settings'],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<Record<string, string | number | boolean | null>>>('/settings');
      return response.data.data;
    },
  });

  useEffect(() => {
    if (!settingsQuery.data) return;
    const next: Record<string, string> = {};
    for (const [key, value] of Object.entries(settingsQuery.data)) {
      next[key] = value == null ? '' : String(value);
    }
    setDrafts(next);
  }, [settingsQuery.data]);

  const updateMutation = useMutation({
    mutationFn: async ({ key, value }: { key: string; value: string }) => {
      const response = await api.put('/settings', { key, value });
      return response.data;
    },
    onSuccess: (_data, variables) => {
      setError(null);
      setMessage(`Saved ${variables.key}.`);
      void queryClient.invalidateQueries({ queryKey: ['settings'] });
    },
    onError: (err) => {
      setMessage(null);
      setError(getApiError(err));
    },
  });

  if (settingsQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading settings…</p>;
  }

  if (settingsQuery.isError) {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        {getApiError(settingsQuery.error, 'Unable to load settings.')}
      </div>
    );
  }

  const entries = Object.entries(drafts);

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Settings</h2>
        <p className="mt-1 text-sm text-slate-600">
          Non-secret system configuration
          {canManage ? '' : ' (read-only for your role)'}.
        </p>
      </div>

      {(error || message) && (
        <div
          className={[
            'rounded-xl px-4 py-3 text-sm',
            error ? 'border border-red-200 bg-red-50 text-red-700' : 'border border-nck-green/20 bg-nck-greenLight text-nck-green',
          ].join(' ')}
          role={error ? 'alert' : 'status'}
        >
          {error || message}
        </div>
      )}

      <div className="space-y-3 rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        {entries.length === 0 && <p className="text-sm text-slate-500">No settings available.</p>}
        {entries.map(([key, value]) => (
          <div key={key} className="grid gap-2 border-b border-slate-100 py-3 last:border-0 md:grid-cols-[240px_1fr_auto] md:items-center">
            <label className="text-sm font-semibold text-nck-slate" htmlFor={`setting-${key}`}>
              {key}
            </label>
            <input
              id={`setting-${key}`}
              type={value === 'true' || value === 'false' ? 'checkbox' : 'text'}
              checked={value === 'true' || value === 'false' ? value === 'true' : undefined}
              value={value === 'true' || value === 'false' ? undefined : value}
              disabled={!canManage || updateMutation.isPending}
              onChange={(event) =>
                setDrafts((current) => ({
                  ...current,
                  [key]:
                    event.target.type === 'checkbox'
                      ? event.target.checked
                        ? 'true'
                        : 'false'
                      : event.target.value,
                }))
              }
              className={
                value === 'true' || value === 'false'
                  ? 'h-4 w-4 rounded border-slate-300 text-nck-green'
                  : 'rounded-xl border border-slate-200 px-3 py-2 text-sm disabled:bg-slate-50'
              }
            />
            {canManage && (
              <button
                type="button"
                disabled={updateMutation.isPending}
                onClick={() => updateMutation.mutate({ key, value })}
                className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-3 py-2 text-sm font-semibold text-nck-green disabled:opacity-60"
              >
                Save
              </button>
            )}
          </div>
        ))}
      </div>
    </div>
  );
}
