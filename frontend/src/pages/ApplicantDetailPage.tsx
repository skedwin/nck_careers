import { useQuery } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import api, { getApiError, type ApiSuccess, type Applicant } from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';

export default function ApplicantDetailPage() {
  const { id } = useParams();

  const detailQuery = useQuery({
    queryKey: ['applicant', id],
    enabled: !!id,
    queryFn: async () => {
      const response = await api.get<ApiSuccess<Applicant>>(`/applicants/${id}`);
      return response.data.data;
    },
  });

  if (detailQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading applicant…</p>;
  }

  if (detailQuery.isError || !detailQuery.data) {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        {getApiError(detailQuery.error, 'Unable to load applicant.')}
      </div>
    );
  }

  const applicant = detailQuery.data;

  return (
    <div className="space-y-6">
      <div>
        <p className="text-xs uppercase tracking-[0.16em] text-slate-500">
          <Link to="/applicants" className="text-nck-green hover:underline">
            Applicants
          </Link>
        </p>
        <h2 className="font-display text-3xl font-semibold text-nck-slate">{applicant.full_name}</h2>
        <p className="mt-1 text-sm text-slate-600">{applicant.email ?? 'No email on file'}</p>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {[
          ['Phone', applicant.phone],
          ['Registration', applicant.registration_number],
          ['National ID', applicant.national_id],
          ['County', applicant.county],
        ].map(([label, value]) => (
          <div key={label} className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
            <p className="text-xs uppercase tracking-[0.16em] text-slate-500">{label}</p>
            <p className="mt-2 text-sm font-semibold text-nck-slate">{value ?? '—'}</p>
          </div>
        ))}
      </div>

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Applications</h3>
        <table className="mt-3 min-w-full text-left text-sm">
          <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2">Reference</th>
              <th className="px-2 py-2">Position</th>
              <th className="px-2 py-2">Status</th>
              <th className="px-2 py-2">Received</th>
            </tr>
          </thead>
          <tbody>
            {(applicant.applications ?? []).length === 0 && (
              <tr>
                <td className="px-2 py-3 text-slate-500" colSpan={4}>
                  No applications linked.
                </td>
              </tr>
            )}
            {(applicant.applications ?? []).map((app) => (
              <tr key={app.id} className="border-b border-slate-100">
                <td className="px-2 py-2">
                  <Link className="font-semibold text-nck-green hover:underline" to={`/applications/${app.id}`}>
                    {app.application_reference}
                  </Link>
                </td>
                <td className="px-2 py-2">{app.position?.title ?? '—'}</td>
                <td className="px-2 py-2">{humanize(app.status)}</td>
                <td className="px-2 py-2 whitespace-nowrap">{formatEAT(app.received_at)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
