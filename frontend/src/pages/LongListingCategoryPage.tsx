import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import api, {
  getApiError,
  type ApiSuccess,
  type LongListingCategoryPageData,
} from '../lib/api';
import { formatEAT } from '../lib/dates';
import { downloadAuthorized } from '../lib/download';

const PER_PAGE = 25;

const QUALIFICATION_FILTERS = [
  { value: '', label: 'All qualifications' },
  { value: 'phd', label: 'PhD' },
  { value: 'masters', label: 'Masters' },
  { value: 'bachelors', label: 'Bachelors' },
  { value: 'higher_diploma', label: 'Higher Diploma' },
  { value: 'diploma', label: 'Diploma' },
  { value: 'certificate', label: 'Certificate' },
  { value: 'kcse', label: 'KCSE' },
  { value: 'none', label: 'Not detected' },
] as const;

const DUPLICATE_FILTERS = [
  { value: '', label: 'All applications' },
  { value: 'duplicates', label: 'Duplicates only' },
  { value: 'unique', label: 'Unique Identifier only' },
] as const;

const MATCH_FILTERS = [
  { value: '', label: 'All match types' },
  { value: 'all_criteria', label: 'Same applicant + email + ID No' },
  { value: 'email', label: 'Same email' },
  { value: 'national_id', label: 'Same ID No' },
  { value: 'applicant', label: 'Same applicant' },
] as const;

const DOCUMENT_FILTERS = [
  { value: '', label: 'All applications' },
  { value: 'with', label: 'With documents' },
  { value: 'without', label: 'Without documents' },
] as const;

function cell(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—';
  return String(value);
}

export default function LongListingCategoryPage() {
  const { categoryKey = '' } = useParams<{ categoryKey: string }>();
  const key = decodeURIComponent(categoryKey);
  const [searchParams] = useSearchParams();
  const listingSource = searchParams.get('source') === 'myjobs' ? 'myjobs' : 'mailbox';
  const isMyJobs = listingSource === 'myjobs';
  const initialDuplicates = ['duplicates', 'unique'].includes(searchParams.get('duplicates') ?? '')
    ? (searchParams.get('duplicates') as string)
    : '';

  const [page, setPage] = useState(1);
  const [q, setQ] = useState('');
  const [search, setSearch] = useState('');
  const [qualificationDraft, setQualificationDraft] = useState('');
  const [qualification, setQualification] = useState('');
  const [duplicatesDraft, setDuplicatesDraft] = useState(initialDuplicates);
  const [duplicates, setDuplicates] = useState(initialDuplicates);
  const initialMatch = MATCH_FILTERS.some((option) => option.value === (searchParams.get('match') ?? ''))
    ? (searchParams.get('match') as string)
    : '';
  const [matchDraft, setMatchDraft] = useState(initialMatch);
  const [match, setMatch] = useState(initialMatch);
  const initialDocuments = DOCUMENT_FILTERS.some((option) => option.value === (searchParams.get('documents') ?? ''))
    ? (searchParams.get('documents') as string)
    : '';
  const [documentsDraft, setDocumentsDraft] = useState(initialDocuments);
  const [documents, setDocuments] = useState(initialDocuments);
  const [exporting, setExporting] = useState(false);
  const [exportError, setExportError] = useState<string | null>(null);

  const queryKey = useMemo(
    () => ['reports-long-listing-category', key, listingSource, page, search, qualification, duplicates, match, documents] as const,
    [key, listingSource, page, search, qualification, duplicates, match, documents],
  );

  const detailQuery = useQuery({
    queryKey,
    enabled: Boolean(key),
    queryFn: async () => {
      const response = await api.get<ApiSuccess<LongListingCategoryPageData>>(
        `/reports/long-listing/${encodeURIComponent(key)}`,
        {
          params: {
            page,
            per_page: PER_PAGE,
            q: search || undefined,
            qualification: qualification || undefined,
            duplicates: duplicates || undefined,
            match: match || undefined,
            documents: documents || undefined,
            source: isMyJobs ? 'myjobs' : undefined,
          },
        },
      );
      return response.data.data;
    },
  });

  const exportCsv = async () => {
    setExporting(true);
    setExportError(null);
    try {
      const params = new URLSearchParams({ category: key });
      if (search) params.set('q', search);
      if (qualification) params.set('qualification', qualification);
      if (duplicates) params.set('duplicates', duplicates);
      if (match) params.set('match', match);
      if (documents) params.set('documents', documents);
      if (isMyJobs) params.set('source', 'myjobs');
      const code =
        detailQuery.data?.category.reference_code?.replace('/', '-') ??
        (key === 'unassigned' ? 'unassigned' : key);
      await downloadAuthorized(
        `/reports/long-listing/export?${params.toString()}`,
        `${isMyJobs ? 'nck_myjobs' : 'nck'}_long_listing_${code}_${Date.now()}.xls`,
      );
    } catch (error) {
      setExportError(getApiError(error, 'Excel export failed.'));
    } finally {
      setExporting(false);
    }
  };

  if (!key) {
    return (
      <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
        Missing category.
      </div>
    );
  }

  if (detailQuery.isLoading) {
    return <p className="text-sm text-slate-600">Loading long listing…</p>;
  }

  if (detailQuery.isError || !detailQuery.data) {
    return (
      <div className="space-y-3">
        <Link to="/reports" className="text-sm font-semibold text-nck-green hover:underline">
          ← Back to reports
        </Link>
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {getApiError(detailQuery.error, 'Unable to load this category.')}
        </div>
      </div>
    );
  }

  const { category, rows, meta, generated_at: generatedAt } = detailQuery.data;
  const hasFilters = Boolean(search || qualification || duplicates || match || documents);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <Link to="/reports" className="text-sm font-semibold text-nck-green hover:underline">
            ← Back to reports
          </Link>
          <p className="mt-3 text-xs font-semibold uppercase tracking-[0.14em] text-nck-green">
            {category.reference_code ?? 'UNASSIGNED'}
          </p>
          <h2 className="font-display text-3xl font-semibold text-nck-slate">
            {isMyJobs ? 'MyJobs · ' : ''}
            {category.title}
          </h2>
          <p className="mt-1 text-sm text-slate-600">
            {meta.total.toLocaleString()} applicant{meta.total === 1 ? '' : 's'}
            {category.duplicate_applicants != null
              ? ` · ${category.duplicate_applicants.toLocaleString()} duplicate${category.duplicate_applicants === 1 ? '' : 's'}`
              : ''}
            {category.vacancies != null ? ` · ${category.vacancies} vacancy slot(s)` : ''}
            {generatedAt ? ` · as of ${formatEAT(generatedAt)}` : ''}
          </p>
        </div>
        <button
          type="button"
          disabled={exporting}
          onClick={() => void exportCsv()}
          className="rounded-xl bg-nck-green px-4 py-2.5 text-sm font-semibold text-white hover:bg-nck-greenDark disabled:opacity-60"
        >
          {exporting ? 'Exporting…' : 'Export Excel'}
        </button>
      </div>

      {exportError && (
        <div className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700" role="alert">
          {exportError}
        </div>
      )}

      <form
        className="flex flex-wrap items-end gap-2 rounded-2xl border border-nck-green/10 bg-white p-4"
        onSubmit={(event) => {
          event.preventDefault();
          setPage(1);
          setSearch(q.trim());
          setQualification(qualificationDraft);
          setDuplicates(duplicatesDraft);
          setMatch(matchDraft);
          setDocuments(documentsDraft);
        }}
      >
        <label className="min-w-[240px] flex-1 space-y-1">
          <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Search</span>
          <input
            value={q}
            onChange={(event) => setQ(event.target.value)}
            placeholder="Search name, email, phone, ID, county…"
            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          />
        </label>
        <label className="w-full space-y-1 sm:w-56">
          <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
            Academic qualification
          </span>
          <select
            value={qualificationDraft}
            onChange={(event) => {
              setQualificationDraft(event.target.value);
              setQualification(event.target.value);
              setPage(1);
            }}
            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          >
            {QUALIFICATION_FILTERS.map((option) => (
              <option key={option.value || 'all'} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <label className="w-full space-y-1 sm:w-52">
          <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Duplicates</span>
          <select
            value={duplicatesDraft}
            onChange={(event) => {
              setDuplicatesDraft(event.target.value);
              setDuplicates(event.target.value);
              setPage(1);
            }}
            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          >
            {DUPLICATE_FILTERS.map((option) => (
              <option key={option.value || 'all-apps'} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <label className="w-full space-y-1 sm:w-52">
          <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Match</span>
          <select
            value={matchDraft}
            onChange={(event) => {
              const value = event.target.value;
              setMatchDraft(value);
              setMatch(value);
              if (value && !duplicatesDraft) {
                setDuplicatesDraft('duplicates');
                setDuplicates('duplicates');
              }
              setPage(1);
            }}
            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          >
            {MATCH_FILTERS.map((option) => (
              <option key={option.value || 'all-match'} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <label className="w-full space-y-1 sm:w-52">
          <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Documents</span>
          <select
            value={documentsDraft}
            onChange={(event) => {
              setDocumentsDraft(event.target.value);
              setDocuments(event.target.value);
              setPage(1);
            }}
            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm"
          >
            {DOCUMENT_FILTERS.map((option) => (
              <option key={option.value || 'all-docs'} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
        </label>
        <button
          type="submit"
          className="rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-2 text-sm font-semibold text-nck-green"
        >
          Search
        </button>
        {hasFilters && (
          <button
            type="button"
            className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50"
            onClick={() => {
              setQ('');
              setSearch('');
              setQualificationDraft('');
              setQualification('');
              setDuplicatesDraft('');
              setDuplicates('');
              setMatchDraft('');
              setMatch('');
              setDocumentsDraft('');
              setDocuments('');
              setPage(1);
            }}
          >
            Clear
          </button>
        )}
      </form>

      <div className="overflow-x-auto rounded-2xl border border-nck-green/10 bg-white shadow-sm">
        <table className="min-w-[1400px] w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-nck-mist/60 text-[11px] uppercase tracking-wide text-slate-500">
            <tr>
              <th className="px-2 py-2 whitespace-nowrap">SN.</th>
              <th className="px-2 py-2 whitespace-nowrap">Unique Identifier</th>
              <th className="px-2 py-2 whitespace-nowrap">Applicant Name</th>
              <th className="px-2 py-2 whitespace-nowrap">Telephone/Mobile No</th>
              <th className="px-2 py-2 whitespace-nowrap">Email</th>
              <th className="px-2 py-2 whitespace-nowrap">ID No</th>
              <th className="px-2 py-2 whitespace-nowrap">PWD (Yes/No)</th>
              <th className="px-2 py-2 whitespace-nowrap">County of Origin</th>
              <th className="px-2 py-2 whitespace-nowrap">Gender</th>
              <th className="px-2 py-2 min-w-[9rem]">Received as one (Yes/No)</th>
              <th className="px-2 py-2 min-w-[10rem]">Academic Qualifications</th>
              <th className="px-2 py-2 min-w-[10rem]">Professional Membership</th>
              <th className="px-2 py-2 min-w-[9rem]">Proficiency in Computer Studies</th>
              <th className="px-2 py-2 whitespace-nowrap">Years of Experience</th>
              {isMyJobs && <th className="px-2 py-2 whitespace-nowrap">MyJobs Score</th>}
              <th className="px-2 py-2 whitespace-nowrap">Documents</th>
              <th className="px-2 py-2 min-w-[12rem]">Comments/Remarks (PWD)</th>
            </tr>
          </thead>
          <tbody>
            {rows.length === 0 && (
              <tr>
                <td className="px-3 py-4 text-slate-500" colSpan={isMyJobs ? 17 : 16}>
                  {hasFilters ? 'No applicants match these filters.' : 'No applicants in this category yet.'}
                </td>
              </tr>
            )}
            {rows.map((row) => (
              <tr
                key={row.application_id}
                className={`border-b border-slate-100 align-top ${
                  row.is_duplicate ? 'bg-amber-50/70' : row.is_duplicate_primary ? 'bg-emerald-50/50' : ''
                }`}
              >
                <td className="px-2 py-2 tabular-nums text-slate-600">{row.serial_no}</td>
                <td className="px-2 py-2 whitespace-nowrap">
                  <Link
                    className="font-medium text-nck-green hover:underline"
                    to={`/applications/${row.application_id}`}
                  >
                    {row.application_reference}
                  </Link>
                  {row.is_duplicate && (
                    <p className="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-amber-800">
                      Duplicate — {row.duplicate_of ?? row.duplicate_label?.replace(/^Duplicate —\s*/i, '') ?? '—'}
                      {row.duplicate_match ? ` · ${row.duplicate_match}` : ''}
                    </p>
                  )}
                  {row.is_duplicate_primary && (
                    <p className="mt-0.5 text-[11px] font-semibold uppercase tracking-wide text-emerald-800">
                      Unique Identifier
                      {row.duplicate_count ? ` · group of ${row.duplicate_count}` : ''}
                    </p>
                  )}
                </td>
                <td className="px-2 py-2 font-medium text-nck-slate">{cell(row.applicant_name)}</td>
                <td className="px-2 py-2 whitespace-nowrap">{cell(row.phone)}</td>
                <td className="px-2 py-2">{cell(row.email)}</td>
                <td className="px-2 py-2 whitespace-nowrap">{cell(row.national_id)}</td>
                <td className="px-2 py-2">{cell(row.pwd)}</td>
                <td className="px-2 py-2">{cell(row.county)}</td>
                <td className="px-2 py-2">{cell(row.gender)}</td>
                <td className="px-2 py-2">{cell(row.received_as_one)}</td>
                <td className="px-2 py-2">{cell(row.academic_qualifications)}</td>
                <td className="px-2 py-2">{cell(row.professional_membership)}</td>
                <td className="px-2 py-2">{cell(row.computer_proficiency)}</td>
                <td className="px-2 py-2 tabular-nums">{cell(row.experience_years)}</td>
                {isMyJobs && (
                  <td className="px-2 py-2 tabular-nums font-semibold text-nck-slate">
                    {cell(row.myjobs_score)}
                  </td>
                )}
                <td className="px-2 py-2 tabular-nums">
                  {(row.documents_count ?? 0) > 0 ? (
                    <Link
                      className="font-semibold text-nck-green hover:underline"
                      to={`/applications/${row.application_id}`}
                      title="Open application documents"
                    >
                      {row.documents_count}
                    </Link>
                  ) : (
                    <span className="text-slate-400">0</span>
                  )}
                </td>
                <td className="px-2 py-2 text-slate-700">{cell(row.comments_remarks)}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-slate-600">
        <p>
          {meta.total === 0
            ? 'No results'
            : `Showing ${meta.from ?? 0}–${meta.to ?? 0} of ${meta.total.toLocaleString()}`}
        </p>
        <div className="flex items-center gap-2">
          <button
            type="button"
            disabled={page <= 1 || detailQuery.isFetching}
            onClick={() => setPage((p) => Math.max(1, p - 1))}
            className="rounded-xl border border-slate-200 px-3 py-1.5 font-semibold disabled:opacity-50"
          >
            Previous
          </button>
          <span className="tabular-nums">
            Page {meta.current_page} of {Math.max(1, meta.last_page)}
          </span>
          <button
            type="button"
            disabled={page >= meta.last_page || detailQuery.isFetching}
            onClick={() => setPage((p) => p + 1)}
            className="rounded-xl border border-slate-200 px-3 py-1.5 font-semibold disabled:opacity-50"
          >
            Next
          </button>
        </div>
      </div>
    </div>
  );
}
