import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import api, {
  asList,
  getApiError,
  pageMeta,
  type ApiSuccess,
  type LaravelPaginator,
  type Position,
} from '../lib/api';

const emptyForm = {
  title: '',
  reference_code: '',
  department: '',
  description: '',
};

export default function PositionsPage() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [form, setForm] = useState(emptyForm);

  const listQuery = useQuery({
    queryKey: ['positions', page],
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LaravelPaginator<Position>>>('/positions', {
        params: { page, per_page: 20 },
      });
      return response.data.data;
    },
  });

  const createMutation = useMutation({
    mutationFn: async () => {
      const response = await api.post<ApiSuccess<Position>>('/positions', form);
      return response.data.data;
    },
    onSuccess: () => {
      setForm(emptyForm);
      void queryClient.invalidateQueries({ queryKey: ['positions'] });
    },
  });

  const items = asList(listQuery.data);
  const meta = pageMeta(listQuery.data);

  const onSubmit = (event: FormEvent) => {
    event.preventDefault();
    createMutation.mutate();
  };

  return (
    <div className="space-y-6">
      <div>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">Positions</h2>
        <p className="mt-1 text-sm text-slate-600">Vacancy configuration and eligibility criteria.</p>
      </div>

      <form
        onSubmit={onSubmit}
        className="grid gap-3 rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm md:grid-cols-2"
      >
        <h3 className="md:col-span-2 text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
          Create position
        </h3>
        <input
          required
          value={form.title}
          onChange={(event) => setForm((f) => ({ ...f, title: event.target.value }))}
          placeholder="Title"
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        />
        <input
          required
          value={form.reference_code}
          onChange={(event) => setForm((f) => ({ ...f, reference_code: event.target.value }))}
          placeholder="Reference code"
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        />
        <input
          value={form.department}
          onChange={(event) => setForm((f) => ({ ...f, department: event.target.value }))}
          placeholder="Department"
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm"
        />
        <textarea
          value={form.description}
          onChange={(event) => setForm((f) => ({ ...f, description: event.target.value }))}
          placeholder="Description"
          rows={2}
          className="rounded-xl border border-slate-200 px-3 py-2 text-sm md:col-span-2"
        />
        <div className="md:col-span-2 flex flex-wrap items-center gap-3">
          <button
            type="submit"
            disabled={createMutation.isPending}
            className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
          >
            {createMutation.isPending ? 'Saving…' : 'Create position'}
          </button>
          {createMutation.isError && (
            <p className="text-sm text-red-600" role="alert">
              {getApiError(createMutation.error)}
            </p>
          )}
          {createMutation.isSuccess && <p className="text-sm text-nck-green">Position created.</p>}
        </div>
      </form>

      {listQuery.isError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(listQuery.error, 'Unable to load positions.')}
        </div>
      )}

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <table className="min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2">Ref</th>
              <th className="px-2 py-2">Title</th>
              <th className="px-2 py-2">Grade</th>
              <th className="px-2 py-2">Department</th>
              <th className="px-2 py-2">Vacancies</th>
              <th className="px-2 py-2">Status</th>
              <th className="px-2 py-2">Criteria</th>
            </tr>
          </thead>
          <tbody>
            {listQuery.isLoading && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={6}>
                  Loading positions…
                </td>
              </tr>
            )}
            {!listQuery.isLoading && items.length === 0 && (
              <tr>
                <td className="px-2 py-4 text-slate-500" colSpan={6}>
                  No positions yet.
                </td>
              </tr>
            )}
            {items.map((item) => (
              <tr key={item.id} className="border-b border-slate-100">
                <td className="px-2 py-2 font-semibold text-nck-green">{item.reference_code}</td>
                <td className="px-2 py-2 font-medium text-nck-slate">{item.title}</td>
                <td className="px-2 py-2">{item.grade ?? '—'}</td>
                <td className="px-2 py-2">{item.department ?? '—'}</td>
                <td className="px-2 py-2 tabular-nums">{item.vacancies ?? 1}</td>
                <td className="px-2 py-2">{item.status}</td>
                <td className="px-2 py-2">{item.criteria?.length ?? item.criteria_count ?? 0}</td>
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
