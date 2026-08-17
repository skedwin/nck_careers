import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from 'react-router-dom';
import { useEffect, useState } from 'react';
import api, {
  getApiError,
  type ApiSuccess,
  type Application,
  type ApplicationProfile,
} from '../lib/api';
import { formatEAT, humanize } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';
import { useAuth } from '../context/AuthContext';
import { KENYA_COUNTIES } from '../lib/counties';

const ACADEMIC_LEVELS = [
  { value: 'phd', label: 'PhD' },
  { value: 'masters', label: 'Masters' },
  { value: 'bachelors', label: 'Bachelors' },
  { value: 'higher_diploma', label: 'Higher Diploma' },
  { value: 'diploma', label: 'Diploma' },
  { value: 'certificate', label: 'Certificate' },
  { value: 'kcse', label: 'KCSE' },
] as const;

function formatNature(profile?: ApplicationProfile | null): string {
  if (!profile) return '';
  const code = profile.nature_of_application;
  const label =
    code === 'one' ? 'One' : code === 'pieces' ? 'In pieces' : code ? humanize(code) : '';
  if (label && profile.nature_of_application_detail) {
    return `${label} — ${profile.nature_of_application_detail}`;
  }
  return label || profile.nature_of_application_detail || '';
}

function formatQualification(profile?: ApplicationProfile | null): string {
  if (!profile) return '';
  const code = profile.highest_qualification;
  const match = ACADEMIC_LEVELS.find((item) => item.value === code);
  return match?.label || (code ? humanize(code) : '');
}

const STATUS_ACTIONS = [
  'received',
  'under_review',
  'eligible',
  'not_eligible',
  'needs_review',
  'shortlisted',
  'rejected',
] as const;

function formatYesNoCourse(value?: string | null): string {
  if (!value) return '';
  const lower = value.trim().toLowerCase();
  if (lower === 'yes' || lower === 'y') return 'Yes';
  if (lower === 'no' || lower === 'n' || lower === 'none' || lower === 'n/a' || lower === 'nil') {
    return 'No';
  }
  // Legacy free-text course names count as Yes
  return 'Yes';
}

function courseFormValue(value?: string | null): string {
  if (!value) return '';
  return formatYesNoCourse(value);
}

type ProfileFormState = {
  highest_qualification: string;
  management_course: string;
  leadership_course: string;
  professional_membership: string;
  professional_qualifications: string;
  computer_proficiency: string;
  experience_years: string;
  nature_of_application: string;
  gender: string;
  county: string;
  is_pwd: string;
  pwd_details: string;
  phone: string;
  national_id: string;
  notes: string;
};

function profileFormFromApp(app: Application): ProfileFormState {
  return {
    highest_qualification: app.profile?.highest_qualification ?? '',
    management_course: courseFormValue(app.profile?.management_course),
    leadership_course: courseFormValue(app.profile?.leadership_course),
    professional_membership: app.profile?.professional_membership ?? '',
    professional_qualifications: app.profile?.professional_qualifications ?? '',
    computer_proficiency: courseFormValue(app.profile?.computer_proficiency),
    experience_years:
      app.profile?.experience_years != null ? String(app.profile.experience_years) : '',
    nature_of_application: app.profile?.nature_of_application ?? '',
    gender: app.applicant?.gender ?? '',
    county: app.applicant?.county ?? '',
    is_pwd: app.applicant?.is_pwd == null ? '' : app.applicant.is_pwd ? 'yes' : 'no',
    pwd_details: app.applicant?.pwd_details ?? '',
    phone: app.applicant?.phone ?? '',
    national_id: app.applicant?.national_id ?? '',
    notes: app.notes ?? '',
  };
}

const DUPLICATE_MATCH_FILTERS = [
  { value: '', label: 'All match types' },
  { value: 'all_criteria', label: 'Same applicant + email + ID No' },
  { value: 'email', label: 'Same email' },
  { value: 'national_id', label: 'Same ID No' },
  { value: 'applicant', label: 'Same applicant' },
] as const;

function relatedMatchesFilter(
  matchText: string | null | undefined,
  filter: string,
): boolean {
  if (!filter) return true;
  const hay = (matchText ?? '').toLowerCase();
  if (filter === 'all_criteria') {
    return (
      hay.includes('same applicant') &&
      hay.includes('same email') &&
      hay.includes('same id no')
    );
  }
  const needle =
    filter === 'email'
      ? 'same email'
      : filter === 'national_id'
        ? 'same id no'
        : filter === 'applicant'
          ? 'same applicant'
          : '';
  return needle !== '' && hay.includes(needle);
}

export default function ApplicationDetailPage() {
  const { id } = useParams();
  const { user } = useAuth();
  const canUpdate = user?.permissions?.includes('applications.update') ?? false;
  const canEditProfile =
    canUpdate || (user?.permissions?.includes('applications.profile.update') ?? false);
  const queryClient = useQueryClient();
  const [note, setNote] = useState('');
  const [downloadError, setDownloadError] = useState<string | null>(null);
  const [editingProfile, setEditingProfile] = useState(false);
  const [profileForm, setProfileForm] = useState<ProfileFormState | null>(null);
  const [editingRemarks, setEditingRemarks] = useState(false);
  const [remarksDraft, setRemarksDraft] = useState('');
  const [duplicateMatchFilter, setDuplicateMatchFilter] = useState('');
  const [showHiddenDuplicates, setShowHiddenDuplicates] = useState(false);

  const detailQuery = useQuery({
    queryKey: ['application', id],
    enabled: !!id,
    queryFn: async () => {
      const response = await api.get<ApiSuccess<Application>>(`/applications/${id}`);
      return response.data.data;
    },
  });

  useEffect(() => {
    if (detailQuery.data && !editingProfile) {
      setProfileForm(profileFormFromApp(detailQuery.data));
    }
    if (detailQuery.data && !editingRemarks) {
      setRemarksDraft(detailQuery.data.notes ?? '');
    }
  }, [detailQuery.data, editingProfile, editingRemarks]);

  useEffect(() => {
    setDuplicateMatchFilter('');
  }, [id]);

  const statusMutation = useMutation({
    mutationFn: async (status: string) => {
      const response = await api.post<ApiSuccess<Application>>(`/applications/${id}/status`, {
        status,
        note: note || undefined,
      });
      return response.data.data;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['application', id], data);
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
      setNote('');
    },
  });

  const remarksMutation = useMutation({
    mutationFn: async (notes: string) => {
      const response = await api.put<ApiSuccess<Application>>(`/applications/${id}/profile`, {
        notes: notes.trim() === '' ? null : notes,
      });
      return response.data.data;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['application', id], data);
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-long-listing-category'] });
      setEditingRemarks(false);
      setRemarksDraft(data.notes ?? '');
    },
  });

  const profileMutation = useMutation({
    mutationFn: async (form: ProfileFormState) => {
      const payload = {
        highest_qualification: form.highest_qualification || null,
        management_course: form.management_course || null,
        leadership_course: form.leadership_course || null,
        professional_membership: form.professional_membership || null,
        professional_qualifications: form.professional_qualifications || null,
        computer_proficiency: form.computer_proficiency || null,
        experience_years: form.experience_years === '' ? null : Number(form.experience_years),
        nature_of_application: form.nature_of_application || null,
        gender: form.gender || null,
        county: form.county || null,
        is_pwd: form.is_pwd === '' ? null : form.is_pwd === 'yes',
        pwd_details: form.pwd_details || null,
        phone: form.phone || null,
        national_id: form.national_id || null,
        notes: form.notes || null,
      };
      const response = await api.put<ApiSuccess<Application>>(
        `/applications/${id}/profile`,
        payload,
      );
      return response.data.data;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['application', id], data);
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-long-listing'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-long-listing-category'] });
      setEditingProfile(false);
      setProfileForm(profileFormFromApp(data));
    },
  });

  const hideDuplicateMutation = useMutation({
    mutationFn: async (payload: {
      application_id?: number;
      application_ids?: number[];
      all_related?: boolean;
      unhide?: boolean;
    }) => {
      const { unhide, ...body } = payload;
      const path = unhide
        ? `/applications/${id}/unhide-duplicate`
        : `/applications/${id}/hide-duplicate`;
      const response = await api.post<ApiSuccess<Application>>(path, body);
      return response.data.data;
    },
    onSuccess: (data) => {
      queryClient.setQueryData(['application', id], data);
      void queryClient.invalidateQueries({ queryKey: ['applications'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-long-listing'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-long-listing-category'] });
      void queryClient.invalidateQueries({ queryKey: ['reports-hidden-duplicates'] });
    },
  });

  const app = detailQuery.data;

  if (detailQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading application…</p>;
  }

  if (detailQuery.isError || !app) {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        {getApiError(detailQuery.error, 'Unable to load application.')}
      </div>
    );
  }

  const form = profileForm ?? profileFormFromApp(app);
  const relatedDuplicates = (app.duplicates?.related ?? []).filter((row) => {
    // Hidden duplicates belong on the Hidden duplicates report, not this view.
    if (row.is_hidden) return false;
    if (!duplicateMatchFilter) return true;
    // Keep the current app and Unique Identifier visible for context.
    if (row.application_id === app.id || row.is_primary) return true;
    return relatedMatchesFilter(row.match, duplicateMatchFilter);
  });
  const hideableDuplicates = relatedDuplicates.filter((row) => !row.is_primary);
  const hiddenDuplicates = (app.duplicates?.related ?? []).filter((row) => row.is_hidden);
  const hiddenInGroupCount = hiddenDuplicates.length;

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="text-xs uppercase tracking-[0.16em] text-slate-500">
            <Link to="/applications" className="text-nck-green hover:underline">
              Applications
            </Link>
          </p>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">{app.application_reference}</h2>
          <p className="mt-1 text-sm text-slate-600">{app.subject ?? 'No subject'}</p>
        </div>
        <div className="text-right text-sm">
          <p className="font-semibold text-nck-slate">{humanize(app.status)}</p>
          <p className="text-slate-500">Screening: {humanize(app.screening_status)}</p>
          <p className="mt-1 text-slate-500">Received {formatEAT(app.received_at)}</p>
          {app.duplicates && relatedDuplicates.length > 0 && (
            <p className={`mt-2 text-xs font-semibold uppercase tracking-wide ${app.duplicates.is_duplicate ? 'text-amber-800' : 'text-emerald-800'}`}>
              {app.duplicates.is_duplicate
                ? `Duplicate — ${app.duplicates.primary_reference ?? '—'}`
                : 'Unique Identifier'}
              {` · group of ${relatedDuplicates.length}`}
              {hiddenInGroupCount > 0 ? ` · ${hiddenInGroupCount} hidden` : ''}
            </p>
          )}
        </div>
      </div>

      {app.duplicates?.related.some((row) => row.application_id === app.id && row.is_hidden) && (
        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-5 shadow-sm">
          <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
            Hidden duplicate
          </h3>
          <p className="mt-2 text-sm text-slate-700">
            This application is hidden from long listing
            {app.duplicates.primary_reference
              ? ` (Duplicate — ${app.duplicates.primary_reference})`
              : ''}
            .
          </p>
          <div className="mt-3 flex flex-wrap gap-2">
            {canUpdate && (
              <button
                type="button"
                disabled={hideDuplicateMutation.isPending}
                onClick={() => hideDuplicateMutation.mutate({ application_id: app.id, unhide: true })}
                className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-3 py-2 text-sm font-semibold text-nck-green disabled:opacity-60"
              >
                Unhide this application
              </button>
            )}
            <Link
              to="/reports/hidden-duplicates"
              className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            >
              Open hidden duplicates report
            </Link>
          </div>
        </div>
      )}

      {app.duplicates && relatedDuplicates.length > 0 && (
        <div
          className={`rounded-2xl border p-5 shadow-sm ${
            app.duplicates.is_duplicate
              ? 'border-amber-200 bg-amber-50/60'
              : 'border-emerald-200 bg-emerald-50/40'
          }`}
        >
          <div className="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
                Duplicate applications
              </h3>
              <p className="mt-2 text-sm text-slate-700">
                {app.duplicates.is_duplicate ? (
                  <>
                    This application is a <strong>Duplicate — {app.duplicates.primary_reference}</strong>
                    {app.duplicates.match ? ` (${app.duplicates.match})` : ''}.
                  </>
                ) : (
                  <>
                    This application is the kept <strong>Unique Identifier</strong> for this group
                    {app.duplicates.match ? ` (${app.duplicates.match})` : ''}.
                  </>
                )}{' '}
                Check <strong>Hide</strong> to remove a duplicate from long listing; view them later under{' '}
                <Link to="/reports/hidden-duplicates" className="font-semibold text-nck-green hover:underline">
                  Hidden duplicates
                </Link>
                .
              </p>
            </div>
            {canUpdate && hideableDuplicates.length > 0 && (
              <button
                type="button"
                disabled={hideDuplicateMutation.isPending}
                onClick={() => {
                  if (
                    !window.confirm(
                      `Hide ${hideableDuplicates.length} duplicate${hideableDuplicates.length === 1 ? '' : 's'} and keep the Unique Identifier?`,
                    )
                  ) {
                    return;
                  }
                  if (duplicateMatchFilter) {
                    hideDuplicateMutation.mutate({
                      application_ids: hideableDuplicates.map((row) => row.application_id),
                    });
                  } else {
                    hideDuplicateMutation.mutate({ all_related: true });
                  }
                }}
                className="rounded-xl border border-amber-300 bg-white px-3 py-2 text-sm font-semibold text-amber-900 hover:bg-amber-50 disabled:opacity-60"
              >
                {hideDuplicateMutation.isPending
                  ? 'Hiding…'
                  : `Select & hide all duplicates (${hideableDuplicates.length})`}
              </button>
            )}
          </div>
          {hideDuplicateMutation.isError && (
            <p className="mt-3 text-sm text-red-600" role="alert">
              {getApiError(hideDuplicateMutation.error)}
            </p>
          )}
          <div className="mt-4 flex flex-wrap items-end gap-3">
            <label className="w-full space-y-1 sm:w-72">
              <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                Match
              </span>
              <select
                value={duplicateMatchFilter}
                onChange={(event) => setDuplicateMatchFilter(event.target.value)}
                className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm"
              >
                {DUPLICATE_MATCH_FILTERS.map((option) => (
                  <option key={option.value || 'all-match'} value={option.value}>
                    {option.label}
                  </option>
                ))}
              </select>
            </label>
            <p className="pb-2 text-xs text-slate-500">
              Showing {relatedDuplicates.length} visible
              {hiddenInGroupCount > 0 ? ` · ${hiddenInGroupCount} hidden` : ''}
              {hideableDuplicates.length > 0
                ? ` · ${hideableDuplicates.length} can be hidden (Unique Identifier kept)`
                : ''}
            </p>
            {canUpdate && hiddenInGroupCount > 0 && (
              <button
                type="button"
                onClick={() => setShowHiddenDuplicates((open) => !open)}
                className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
              >
                {showHiddenDuplicates ? 'Hide unhide list' : `Show hidden (${hiddenInGroupCount}) to unhide`}
              </button>
            )}
          </div>
          {showHiddenDuplicates && hiddenInGroupCount > 0 && (
            <div className="mt-3 overflow-x-auto rounded-xl border border-slate-200 bg-slate-50">
              <table className="min-w-full text-left text-sm">
                <thead className="border-b border-slate-200 text-[11px] uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-3 py-2">Unique Identifier</th>
                    <th className="px-3 py-2">Applicant</th>
                    <th className="px-3 py-2">Email</th>
                    <th className="px-3 py-2">Phone</th>
                    <th className="px-3 py-2">Match</th>
                    {canUpdate && <th className="px-3 py-2">Action</th>}
                  </tr>
                </thead>
                <tbody>
                  {hiddenDuplicates.map((row) => (
                    <tr key={row.application_id} className="border-b border-slate-200/80">
                      <td className="px-3 py-2 whitespace-nowrap">
                        <Link
                          className="font-medium text-nck-green hover:underline"
                          to={`/applications/${row.application_id}`}
                        >
                          {row.application_reference}
                        </Link>
                        <p className="text-[11px] text-amber-800">
                          Duplicate — {app.duplicates?.primary_reference ?? '—'}
                        </p>
                      </td>
                      <td className="px-3 py-2">{row.applicant_name ?? '—'}</td>
                      <td className="px-3 py-2">{row.email ?? '—'}</td>
                      <td className="px-3 py-2 whitespace-nowrap">{row.phone ?? '—'}</td>
                      <td className="px-3 py-2 text-slate-600">{row.match ?? '—'}</td>
                      {canUpdate && (
                        <td className="px-3 py-2 whitespace-nowrap">
                          <button
                            type="button"
                            disabled={hideDuplicateMutation.isPending}
                            onClick={() =>
                              hideDuplicateMutation.mutate({
                                application_id: row.application_id,
                                unhide: true,
                              })
                            }
                            className="rounded-lg border border-nck-green/20 bg-nck-greenLight px-3 py-1.5 text-xs font-semibold text-nck-green disabled:opacity-60"
                          >
                            Unhide
                          </button>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
              <p className="px-3 py-2 text-xs text-slate-500">
                Or manage all in the{' '}
                <Link to="/reports/hidden-duplicates" className="font-semibold text-nck-green hover:underline">
                  Hidden duplicates report
                </Link>
                .
              </p>
            </div>
          )}
          <div className="mt-3 overflow-x-auto rounded-xl border border-white/60 bg-white">
            <table className="min-w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-[11px] uppercase tracking-wide text-slate-500">
                <tr>
                  {canUpdate && <th className="px-3 py-2">Hide</th>}
                  <th className="px-3 py-2">Role</th>
                  <th className="px-3 py-2">Unique Identifier</th>
                  <th className="px-3 py-2">Applicant</th>
                  <th className="px-3 py-2">Email</th>
                  <th className="px-3 py-2">Phone</th>
                  <th className="px-3 py-2">ID No</th>
                  <th className="px-3 py-2">Received</th>
                  <th className="px-3 py-2">Match</th>
                </tr>
              </thead>
              <tbody>
                {relatedDuplicates.length === 0 && (
                  <tr>
                    <td className="px-3 py-4 text-slate-500" colSpan={canUpdate ? 9 : 8}>
                      No duplicates match this filter.
                    </td>
                  </tr>
                )}
                {relatedDuplicates.map((row) => {
                  const isCurrent = row.application_id === app.id;
                  return (
                    <tr
                      key={row.application_id}
                      className={`border-b border-slate-100 ${isCurrent ? 'bg-nck-mist/50' : ''}`}
                    >
                      {canUpdate && (
                        <td className="px-3 py-2">
                          {row.is_primary ? (
                            <span className="text-xs text-slate-400">—</span>
                          ) : (
                            <label className="inline-flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-slate-600">
                              <input
                                type="checkbox"
                                checked={false}
                                disabled={hideDuplicateMutation.isPending}
                                onChange={(event) => {
                                  if (event.target.checked) {
                                    hideDuplicateMutation.mutate({ application_id: row.application_id });
                                  }
                                }}
                              />
                              Hide
                            </label>
                          )}
                        </td>
                      )}
                      <td className="px-3 py-2 whitespace-nowrap">
                        <span
                          className={`text-xs font-semibold uppercase tracking-wide ${
                            row.is_primary ? 'text-emerald-800' : 'text-amber-800'
                          }`}
                        >
                          {row.is_primary ? 'Unique Identifier' : 'Duplicate'}
                        </span>
                        {isCurrent && (
                          <span className="ml-2 text-[11px] font-semibold text-slate-500">(this)</span>
                        )}
                      </td>
                      <td className="px-3 py-2 whitespace-nowrap">
                        {isCurrent ? (
                          <span className="font-medium text-nck-slate">{row.application_reference}</span>
                        ) : (
                          <Link
                            className="font-medium text-nck-green hover:underline"
                            to={`/applications/${row.application_id}`}
                          >
                            {row.application_reference}
                          </Link>
                        )}
                        {!row.is_primary && app.duplicates?.primary_reference && (
                          <p className="text-[11px] text-amber-800">
                            Duplicate — {app.duplicates.primary_reference}
                          </p>
                        )}
                      </td>
                      <td className="px-3 py-2">{row.applicant_name ?? '—'}</td>
                      <td className="px-3 py-2">{row.email ?? '—'}</td>
                      <td className="px-3 py-2 whitespace-nowrap">{row.phone ?? '—'}</td>
                      <td className="px-3 py-2 whitespace-nowrap">{row.national_id ?? '—'}</td>
                      <td className="px-3 py-2 whitespace-nowrap text-slate-600">
                        {formatEAT(row.received_at)}
                      </td>
                      <td className="px-3 py-2 text-slate-600">{row.match ?? '—'}</td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Applicant</h3>
          {app.applicant ? (
            <div className="mt-3 space-y-1 text-sm">
              <p className="font-display text-2xl font-semibold text-nck-slate">
                <Link className="hover:text-nck-green" to={`/applicants/${app.applicant.id}`}>
                  {app.applicant.full_name}
                </Link>
              </p>
              <p>{app.applicant.email ?? '—'}</p>
              <p>Phone: {app.applicant.phone ?? '—'}</p>
              <p>National ID: {app.applicant.national_id ?? '—'}</p>
              <p>Gender: {app.applicant.gender ?? '—'}</p>
              <p>County: {app.applicant.county ?? '—'}</p>
              <p>
                PWD:{' '}
                {app.applicant.is_pwd == null
                  ? '—'
                  : app.applicant.is_pwd
                    ? `Yes${app.applicant.pwd_details ? ` — ${app.applicant.pwd_details}` : ''}`
                    : 'No'}
              </p>
            </div>
          ) : (
            <p className="mt-3 text-sm text-slate-500">No applicant linked.</p>
          )}
        </div>

        <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Position</h3>
          <p className="mt-3 font-display text-2xl font-semibold text-nck-slate">
            {app.position?.title ?? 'Unassigned'}
          </p>
          <p className="text-sm text-slate-500">{app.position?.reference_code ?? '—'}</p>
        </div>
      </div>

      {canUpdate && (
      <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Update status</h3>
        <textarea
          value={note}
          onChange={(event) => setNote(event.target.value)}
          placeholder="Optional note for the status change"
          className="mt-3 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          rows={2}
        />
        <div className="mt-3 flex flex-wrap gap-2">
          {STATUS_ACTIONS.map((status) => (
            <button
              key={status}
              type="button"
              disabled={statusMutation.isPending || app.status === status}
              onClick={() => statusMutation.mutate(status)}
              className="rounded-xl border border-nck-green/20 bg-white px-3 py-2 text-xs font-semibold uppercase tracking-wide text-nck-green hover:bg-nck-greenLight disabled:opacity-50"
            >
              {humanize(status)}
            </button>
          ))}
        </div>
        {statusMutation.isError && (
          <p className="mt-3 text-sm text-red-600" role="alert">
            {getApiError(statusMutation.error)}
          </p>
        )}
      </div>
      )}

      <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
            Extracted profile
          </h3>
          {canEditProfile && !editingProfile ? (
            <button
              type="button"
              className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-3 py-1.5 text-sm font-semibold text-nck-green"
              onClick={() => {
                setProfileForm(profileFormFromApp(app));
                setEditingProfile(true);
              }}
            >
              Edit profile
            </button>
          ) : canEditProfile && editingProfile ? (
            <div className="flex gap-2">
              <button
                type="button"
                className="rounded-xl border border-slate-200 px-3 py-1.5 text-sm text-slate-600"
                disabled={profileMutation.isPending}
                onClick={() => {
                  setEditingProfile(false);
                  setProfileForm(profileFormFromApp(app));
                }}
              >
                Cancel
              </button>
              <button
                type="button"
                className="rounded-xl bg-nck-green px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
                disabled={profileMutation.isPending}
                onClick={() => profileMutation.mutate(form)}
              >
                {profileMutation.isPending ? 'Saving…' : 'Save'}
              </button>
            </div>
          ) : null}
        </div>

        {profileMutation.isError && (
          <p className="mt-3 text-sm text-red-600" role="alert">
            {getApiError(profileMutation.error)}
          </p>
        )}

        {canEditProfile && editingProfile ? (
          <div className="mt-4 grid gap-3 text-sm sm:grid-cols-2">
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Academic qualification</span>
              <select
                value={form.highest_qualification}
                onChange={(e) => setProfileForm({ ...form, highest_qualification: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                {ACADEMIC_LEVELS.map((level) => (
                  <option key={level.value} value={level.value}>
                    {level.label}
                  </option>
                ))}
              </select>
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Years of experience</span>
              <input
                type="number"
                min={0}
                max={50}
                step={1}
                value={form.experience_years}
                onChange={(e) => setProfileForm({ ...form, experience_years: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                placeholder="e.g. 15"
              />
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Management course</span>
              <select
                value={form.management_course}
                onChange={(e) => setProfileForm({ ...form, management_course: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                <option value="Yes">Yes</option>
                <option value="No">No</option>
              </select>
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Leadership course</span>
              <select
                value={form.leadership_course}
                onChange={(e) => setProfileForm({ ...form, leadership_course: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                <option value="Yes">Yes</option>
                <option value="No">No</option>
              </select>
            </label>
            <label className="space-y-1 sm:col-span-2">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Professional membership</span>
              <input
                value={form.professional_membership}
                onChange={(e) => setProfileForm({ ...form, professional_membership: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                placeholder="As stated by applicant, e.g. NCK, LSK"
              />
            </label>
            <label className="space-y-1 sm:col-span-2">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Professional qualifications</span>
              <input
                value={form.professional_qualifications}
                onChange={(e) =>
                  setProfileForm({ ...form, professional_qualifications: e.target.value })
                }
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              />
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Gender</span>
              <select
                value={form.gender}
                onChange={(e) => setProfileForm({ ...form, gender: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
              </select>
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">PWD (Yes/No)</span>
              <select
                value={form.is_pwd}
                onChange={(e) => setProfileForm({ ...form, is_pwd: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                <option value="yes">Yes</option>
                <option value="no">No</option>
              </select>
            </label>
            <label className="space-y-1 sm:col-span-2">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">PWD details</span>
              <input
                value={form.pwd_details}
                onChange={(e) => setProfileForm({ ...form, pwd_details: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                placeholder="Disability indicated / certificate note"
              />
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">County of origin</span>
              <select
                value={form.county}
                onChange={(e) => setProfileForm({ ...form, county: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                {KENYA_COUNTIES.map((county) => (
                  <option key={county} value={county}>
                    {county}
                  </option>
                ))}
                {form.county &&
                  !KENYA_COUNTIES.includes(form.county as (typeof KENYA_COUNTIES)[number]) && (
                    <option value={form.county}>{form.county} (current)</option>
                  )}
              </select>
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Received as one</span>
              <select
                value={form.nature_of_application}
                onChange={(e) => setProfileForm({ ...form, nature_of_application: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                <option value="one">Yes (one)</option>
                <option value="pieces">No (pieces)</option>
              </select>
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">Phone</span>
              <input
                value={form.phone}
                onChange={(e) => setProfileForm({ ...form, phone: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              />
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">National ID</span>
              <input
                value={form.national_id}
                onChange={(e) => setProfileForm({ ...form, national_id: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              />
            </label>
            <label className="space-y-1">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">
                Proficiency in computer studies
              </span>
              <select
                value={form.computer_proficiency}
                onChange={(e) => setProfileForm({ ...form, computer_proficiency: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
              >
                <option value="">—</option>
                <option value="Yes">Yes</option>
                <option value="No">No</option>
              </select>
            </label>
            <label className="space-y-1 sm:col-span-2">
              <span className="text-xs uppercase tracking-[0.12em] text-slate-500">
                Remarks / Comments
              </span>
              <textarea
                value={form.notes}
                onChange={(e) => setProfileForm({ ...form, notes: e.target.value })}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                rows={3}
                placeholder="For PWD must be indicated or attach in the certificates; other screening remarks…"
              />
            </label>
          </div>
        ) : (
          <dl className="mt-3 grid gap-3 text-sm sm:grid-cols-2">
            {[
              ['Nature of application', formatNature(app.profile)],
              ['Highest qualification', formatQualification(app.profile)],
              ['Professional qualifications', app.profile?.professional_qualifications],
              ['Management course', formatYesNoCourse(app.profile?.management_course) || null],
              ['Leadership course', formatYesNoCourse(app.profile?.leadership_course) || null],
              ['Professional membership', app.profile?.professional_membership],
              ['Computer proficiency', formatYesNoCourse(app.profile?.computer_proficiency) || null],
              [
                'Experience',
                app.profile?.experience_years != null
                  ? `${app.profile.experience_years} year(s)${
                      app.profile.experience_summary ? ` — ${app.profile.experience_summary}` : ''
                    }`
                  : app.profile?.experience_summary,
              ],
              ['Certifications & skills', app.profile?.certifications_skills],
              [
                'PWD',
                app.applicant?.is_pwd == null
                  ? null
                  : app.applicant.is_pwd
                    ? `Yes${app.applicant.pwd_details ? ` — ${app.applicant.pwd_details}` : ''}`
                    : 'No',
              ],
              ['County', app.applicant?.county],
              ['Gender', app.applicant?.gender],
            ].map(([label, value]) => (
              <div key={String(label)} className="rounded-xl bg-nck-mist/50 px-3 py-2">
                <dt className="text-xs uppercase tracking-[0.12em] text-slate-500">{label}</dt>
                <dd className="mt-1 whitespace-pre-wrap font-medium text-nck-slate">{value || '—'}</dd>
              </div>
            ))}
          </dl>
        )}

        {app.profile?.extracted_at ? (
          <p className="mt-3 text-xs text-slate-500">
            Extracted {formatEAT(app.profile.extracted_at)}
            {app.profile.sources?.length
              ? ` from ${(app.profile.sources ?? []).map((s) => s.replace('_', ' ')).join(' + ')}`
              : ''}
            {app.profile.documents_scanned
              ? ` · ${app.profile.documents_scanned} document(s) scanned`
              : ''}
          </p>
        ) : (
          <p className="mt-3 text-xs text-slate-500">
            Not extracted yet — background extract is running, or use Edit profile.
          </p>
        )}
        {(app.profile?.document_sources?.length ?? 0) > 0 && (
          <ul className="mt-2 text-xs text-slate-500">
            {(app.profile?.document_sources ?? []).map((doc) => (
              <li key={`${doc.path}-${doc.name}`}>CV/doc: {doc.name}</li>
            ))}
          </ul>
        )}
      </div>

      <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div>
            <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
              Remarks / Comments
            </h3>
            <p className="mt-1 text-xs text-slate-500">
              Appears on the longlisting report. For PWD, indicate status or that the certificate is
              attached.
            </p>
          </div>
          {!canEditProfile || !editingRemarks ? (
            canEditProfile ? (
            <button
              type="button"
              className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-3 py-1.5 text-sm font-semibold text-nck-green"
              onClick={() => {
                setRemarksDraft(app.notes ?? '');
                setEditingRemarks(true);
              }}
            >
              {app.notes ? 'Edit remarks' : 'Add remarks'}
            </button>
            ) : null
          ) : (
            <div className="flex gap-2">
              <button
                type="button"
                className="rounded-xl border border-slate-200 px-3 py-1.5 text-sm text-slate-600"
                disabled={remarksMutation.isPending}
                onClick={() => {
                  setEditingRemarks(false);
                  setRemarksDraft(app.notes ?? '');
                }}
              >
                Cancel
              </button>
              <button
                type="button"
                className="rounded-xl bg-nck-green px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-60"
                disabled={remarksMutation.isPending}
                onClick={() => remarksMutation.mutate(remarksDraft)}
              >
                {remarksMutation.isPending ? 'Saving…' : 'Save remarks'}
              </button>
            </div>
          )}
        </div>

        {remarksMutation.isError && (
          <p className="mt-3 text-sm text-red-600" role="alert">
            {getApiError(remarksMutation.error)}
          </p>
        )}

        {canEditProfile && editingRemarks ? (
          <textarea
            value={remarksDraft}
            onChange={(e) => setRemarksDraft(e.target.value)}
            className="mt-4 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
            rows={4}
            placeholder="e.g. PWD indicated — disability certificate attached; documents received in pieces; follow up on ID…"
          />
        ) : (
          <p className="mt-4 whitespace-pre-wrap text-sm font-medium text-nck-slate">
            {app.notes?.trim() ? app.notes : '— No remarks yet.'}
          </p>
        )}
      </div>

      <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Documents</h3>
        {(downloadError || null) && (
          <p className="mt-2 text-sm text-red-600" role="alert">
            {downloadError}
          </p>
        )}
        <div className="mt-3 overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-2 py-2">Name</th>
                <th className="px-2 py-2">Type</th>
                <th className="px-2 py-2">Size</th>
                <th className="px-2 py-2">Action</th>
              </tr>
            </thead>
            <tbody>
              {(app.documents ?? []).length === 0 && (
                <tr>
                  <td className="px-2 py-3 text-slate-500" colSpan={4}>
                    No documents yet.
                  </td>
                </tr>
              )}
              {(app.documents ?? []).map((doc) => {
                const isCloud = Boolean(doc.external_url) && !doc.has_file;
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
                    <td className="px-2 py-2">{doc.document_type}</td>
                    <td className="px-2 py-2">{doc.size ? `${Math.round(doc.size / 1024)} KB` : '—'}</td>
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
                          onClick={() => {
                            setDownloadError(null);
                            void downloadAuthorized(`/documents/${doc.id}/download`, doc.original_name).catch(
                              (error: unknown) => setDownloadError(getApiError(error, 'Download failed.')),
                            );
                          }}
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
        </div>
      </div>

      <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Screening results</h3>
        <div className="mt-3 overflow-x-auto">
          <table className="min-w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-xs uppercase tracking-wide text-slate-500">
              <tr>
                <th className="px-2 py-2">Criterion</th>
                <th className="px-2 py-2">Result</th>
                <th className="px-2 py-2">Evidence</th>
                <th className="px-2 py-2">Scored by</th>
              </tr>
            </thead>
            <tbody>
              {(app.screening_results ?? []).length === 0 && (
                <tr>
                  <td className="px-2 py-3 text-slate-500" colSpan={4}>
                    No screening results yet.
                  </td>
                </tr>
              )}
              {(app.screening_results ?? []).map((result) => (
                <tr key={`${result.criteria_code}-${result.id ?? ''}`} className="border-b border-slate-100">
                  <td className="px-2 py-2">{result.label}</td>
                  <td className="px-2 py-2">{result.result}</td>
                  <td className="px-2 py-2">{result.evidence ?? '—'}</td>
                  <td className="px-2 py-2">{result.scored_by ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="rounded-2xl border border-nck-green/10 bg-white p-5 shadow-sm">
        <h3 className="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">Status history</h3>
        <ul className="mt-3 space-y-2 text-sm">
          {(app.status_history ?? []).length === 0 && <li className="text-slate-500">No history yet.</li>}
          {(app.status_history ?? []).map((item) => (
            <li key={item.id} className="rounded-xl bg-nck-mist px-3 py-2">
              <span className="font-semibold text-nck-slate">
                {humanize(item.from_status)} → {humanize(item.to_status)}
              </span>
              <span className="ml-2 text-slate-500">{formatEAT(item.created_at)}</span>
              {item.user?.name && <span className="ml-2 text-slate-500">by {item.user.name}</span>}
              {item.note && <p className="mt-1 text-slate-600">{item.note}</p>}
            </li>
          ))}
        </ul>
      </div>
    </div>
  );
}
