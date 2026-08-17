import axios from 'axios';

/** API host follows the page: same hostname, port 8000 (localhost or LAN IP). */
export function resolveApiBaseUrl(): string {
  const fallback =
    (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? 'http://127.0.0.1:8000/api/v1';
  if (typeof window === 'undefined') {
    return fallback;
  }

  const { hostname, protocol } = window.location;
  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    return `${protocol}//${hostname}:8000/api/v1`;
  }

  return `${protocol}//${hostname}:8000/api/v1`;
}

/** Microsoft SSO always starts on localhost (Entra callback is registered there). */
export function resolveMicrosoftAuthBaseUrl(): string {
  const fromEnv = import.meta.env.VITE_MICROSOFT_AUTH_BASE_URL as string | undefined;
  if (fromEnv) {
    return fromEnv.replace(/\/$/, '');
  }

  return 'http://localhost:8000/api/v1';
}

const api = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
});

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('nck_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

export type ApiSuccess<T> = {
  success: true;
  data: T;
  message?: string;
};

export type ApiErrorBody = {
  success?: false;
  message?: string;
  errors?: Record<string, string[]>;
};

/** Laravel LengthAwarePaginator JSON (ApiResponse wraps this in data). */
export type LaravelPaginator<T> = {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  from?: number | null;
  to?: number | null;
};

/** Legacy / mailbox-style paginated payload. */
export type Paginated<T> = {
  items: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
};

export function asList<T>(payload: LaravelPaginator<T> | Paginated<T> | T[] | null | undefined): T[] {
  if (!payload) return [];
  if (Array.isArray(payload)) return payload;
  if ('data' in payload && Array.isArray(payload.data)) return payload.data;
  if ('items' in payload && Array.isArray(payload.items)) return payload.items;
  return [];
}

export function pageMeta(
  payload: LaravelPaginator<unknown> | Paginated<unknown> | null | undefined,
): { current_page: number; last_page: number; per_page: number; total: number } {
  if (!payload) {
    return { current_page: 1, last_page: 1, per_page: 20, total: 0 };
  }
  if ('meta' in payload && payload.meta) {
    return payload.meta;
  }
  if ('current_page' in payload) {
    return {
      current_page: payload.current_page,
      last_page: payload.last_page,
      per_page: payload.per_page,
      total: payload.total,
    };
  }
  return { current_page: 1, last_page: 1, per_page: 20, total: 0 };
}

export type User = {
  id: number;
  uuid: string;
  name: string;
  display_name: string;
  email: string;
  job_title?: string | null;
  department?: string | null;
  roles: string[];
  permissions: string[];
  is_active?: boolean;
};

export type DashboardStats = {
  total_applications: number;
  applications_today: number;
  applications_this_week: number;
  applications_this_month: number;
  eligible: number;
  not_eligible: number;
  needs_review: number;
  shortlisted: number;
  pending_ai_processing: number;
  failed_document_processing: number;
  mailbox_sync: {
    status: string;
    last_successful_sync_at: string | null;
  };
};

export type Applicant = {
  id: number;
  uuid: string;
  full_name: string;
  email?: string | null;
  phone?: string | null;
  registration_number?: string | null;
  national_id?: string | null;
  gender?: string | null;
  county?: string | null;
  is_pwd?: boolean | null;
  pwd_details?: string | null;
  applications_count?: number;
  applications?: ApplicationSummary[];
};

export type PositionCriterion = {
  id?: number;
  code: string;
  label: string;
  description?: string | null;
  is_mandatory?: boolean;
  weight?: number;
  sort_order?: number;
};

export type Position = {
  id: number;
  uuid: string;
  title: string;
  reference_code: string;
  description?: string | null;
  department?: string | null;
  grade?: string | null;
  status: string;
  vacancies?: number;
  sort_order?: number;
  opens_at?: string | null;
  closes_at?: string | null;
  criteria_count?: number;
  criteria?: PositionCriterion[];
};

export type ApplicationDocument = {
  id: number;
  uuid: string;
  application_id?: number;
  document_type: string;
  original_name: string;
  mime_type?: string | null;
  size?: number;
  path?: string | null;
  external_url?: string | null;
  has_file?: boolean;
  provider?: string | null;
  created_at?: string | null;
  application?: {
    id: number;
    application_reference: string;
    applicant?: Applicant | null;
  } | null;
};

export type MailAttachment = {
  id: number;
  uuid: string;
  name: string;
  content_type?: string | null;
  size?: number;
  download_status: string;
  source?: string | null;
  provider?: string | null;
  external_url?: string | null;
  error_message?: string | null;
  is_inline?: boolean;
  mail_message_id?: number;
  created_at?: string | null;
  mail_message?: {
    id: number;
    subject?: string | null;
    sender_email?: string | null;
    received_at?: string | null;
  } | null;
};

export type ScreeningResult = {
  id?: number;
  criteria_code: string;
  label: string;
  result: 'pass' | 'fail' | 'unknown' | string;
  evidence?: string | null;
  scored_by?: string;
};

export type StatusHistoryItem = {
  id?: number;
  from_status?: string | null;
  to_status: string;
  note?: string | null;
  created_at?: string | null;
  user?: { id: number; name?: string; display_name?: string } | null;
};

export type ApplicationSummary = {
  id: number;
  uuid: string;
  application_reference: string;
  subject?: string | null;
  status: string;
  screening_status: string;
  source?: string;
  received_at?: string | null;
  applicant_id?: number;
  position_id?: number | null;
  applicant?: Applicant | null;
  position?: Position | null;
};

export type ApplicationProfile = {
  nature_of_application?: string | null;
  nature_of_application_detail?: string | null;
  highest_qualification?: string | null;
  highest_qualification_detail?: string | null;
  management_course?: string | null;
  leadership_course?: string | null;
  professional_membership?: string | null;
  professional_qualifications?: string | null;
  experience_summary?: string | null;
  experience_years?: number | null;
  certifications_skills?: string | null;
  computer_proficiency?: string | null;
  extracted_at?: string | null;
  sources?: string[];
  documents_scanned?: number;
  document_sources?: Array<{ name: string; path?: string; chars?: number; type?: string }>;
  myjobs?: {
    file?: string | null;
    current_position?: string | null;
    company?: string | null;
    age?: string | null;
    age_years?: number | null;
    expected_salary?: string | null;
    current_salary?: string | null;
    score?: string | null;
    scores_link?: string | null;
    applied_at?: string | null;
  } | null;
};

export type ApplicationDuplicateRelated = {
  application_id: number;
  application_reference: string;
  applicant_name?: string | null;
  email?: string | null;
  phone?: string | null;
  national_id?: string | null;
  received_at?: string | null;
  status?: string;
  is_primary?: boolean;
  is_duplicate?: boolean;
  is_hidden?: boolean;
  label?: string | null;
  match?: string | null;
};

export type ApplicationDuplicates = {
  is_duplicate: boolean;
  is_primary: boolean;
  label?: string | null;
  match?: string | null;
  primary_reference?: string | null;
  group_size: number;
  related: ApplicationDuplicateRelated[];
};

export type Application = ApplicationSummary & {
  notes?: string | null;
  profile?: ApplicationProfile | null;
  documents?: ApplicationDocument[];
  screening_results?: ScreeningResult[];
  status_history?: StatusHistoryItem[];
  duplicates?: ApplicationDuplicates | null;
  mail_message?: {
    id: number;
    subject?: string | null;
    sender_email?: string | null;
    sender_name?: string | null;
    body_preview?: string | null;
  } | null;
};

export type SystemSetting = {
  id?: number;
  group?: string;
  key: string;
  value: string | number | boolean | null;
  type?: string;
  is_public?: boolean;
  description?: string | null;
};

export type AuditLog = {
  id: number;
  action: string;
  entity_type?: string | null;
  entity_id?: number | string | null;
  ip_address?: string | null;
  created_at?: string | null;
  user?: { id: number; name?: string; display_name?: string; email?: string } | null;
};

export type EmailDuplicateCategory = {
  key: string;
  position_id: number | null;
  reference_code?: string | null;
  title: string;
  groups: number;
  duplicate_applicants: number;
};

export type EmailDuplicateRow = {
  serial_no: number;
  application_id: number;
  application_reference: string;
  position_key?: string;
  position_code?: string | null;
  position_title?: string | null;
  applicant_name?: string | null;
  email?: string | null;
  phone?: string | null;
  national_id?: string | null;
  duplicate_of_reference?: string | null;
  duplicate_of_application_id?: number | null;
  group_size?: number | null;
  received_at?: string | null;
  status?: string;
};

export type EmailDuplicatesReport = {
  generated_at?: string | null;
  total: number;
  groups: number;
  categories: EmailDuplicateCategory[];
  rows: EmailDuplicateRow[];
};

export type ReportSummary = {
  counts_by_status?: Record<string, number>;
  counts_by_position?: Array<{
    position_id: number | null;
    title?: string;
    reference_code?: string | null;
    vacancies?: number | null;
    total: number;
  }>;
  email_duplicates?: {
    total: number;
    groups: number;
    categories: EmailDuplicateCategory[];
  };
  applications_this_week?: number;
  applications_this_month?: number;
  myjobs_total?: number;
  generated_at?: string | null;
  mailbox?: {
    messages_total?: number;
    messages_pending_application?: number;
    attachments_pending?: number;
    attachments_failed?: number;
    attachments_downloaded?: number;
    attachments_by_status?: Record<string, number>;
  };
};

export type HiddenDuplicateRow = {
  serial_no: number;
  application_id: number;
  application_reference: string;
  position_code?: string | null;
  position_title?: string | null;
  applicant_name?: string | null;
  email?: string | null;
  phone?: string | null;
  national_id?: string | null;
  duplicate_of_reference?: string | null;
  duplicate_of_application_id?: number | null;
  hidden_at?: string | null;
  hidden_by?: string | null;
  received_at?: string | null;
  status?: string;
};

export type HiddenDuplicatesReport = {
  generated_at?: string | null;
  total: number;
  rows: HiddenDuplicateRow[];
};

export type LongListingRow = {
  serial_no: number;
  application_id: number;
  application_reference: string;
  applicant_name?: string | null;
  phone?: string | null;
  email?: string | null;
  national_id?: string | null;
  pwd?: string | null;
  county?: string | null;
  gender?: string | null;
  received_as_one?: string | null;
  academic_qualifications?: string | null;
  professional_membership?: string | null;
  computer_proficiency?: string | null;
  experience_years?: string | number | null;
  comments_remarks?: string | null;
  is_duplicate?: boolean;
  is_duplicate_primary?: boolean;
  duplicate_match?: string | null;
  duplicate_count?: number | null;
  duplicate_of?: string | null;
  duplicate_label?: string | null;
  registration_number?: string | null;
  received_at?: string | null;
  status?: string;
  screening_status?: string;
  documents_count?: number;
  subject?: string | null;
  notes?: string | null;
};

export type LongListingCategorySummary = {
  key: string;
  position_id: number | null;
  reference_code?: string | null;
  title: string;
  department?: string | null;
  vacancies?: number | null;
  total_applicants: number;
  duplicate_applicants?: number;
};

export type LongListingIndex = {
  generated_at?: string | null;
  source?: 'mailbox' | 'myjobs' | string;
  categories: LongListingCategorySummary[];
  unassigned?: LongListingCategorySummary | null;
};

export type LongListingCategoryPageData = {
  category: LongListingCategorySummary;
  rows: LongListingRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
    search?: string | null;
    qualification?: string | null;
    duplicates?: string | null;
    match?: string | null;
    source?: string | null;
  };
  generated_at?: string | null;
};

/** @deprecated Prefer LongListingCategorySummary + LongListingIndex */
export type LongListingCategory = LongListingCategorySummary & {
  rows?: LongListingRow[];
};

export type LongListingReport = LongListingIndex;

export type MyJobsMatch = {
  applicant_id: number;
  applicant_name?: string | null;
  applicant_email?: string | null;
  matched_on?: string;
  applications?: Array<{
    application_id: number;
    application_reference: string;
    position_code?: string | null;
    position_title?: string | null;
  }>;
};

export type MyJobsRow = {
  serial_no: number;
  file: string;
  name?: string | null;
  email?: string | null;
  phone?: string | null;
  gender?: string | null;
  education?: string | null;
  position?: string | null;
  company?: string | null;
  age?: string | null;
  applied_at?: string | null;
  score?: string | null;
  mapped_position_id?: number | null;
  mapped_position_code?: string | null;
  mapped_position_title?: string | null;
  in_system: boolean;
  exists_by_email: boolean;
  exists_by_name: boolean;
  match: string;
  matches: MyJobsMatch[];
};

export type MyJobsListing = {
  generated_at?: string | null;
  files: Array<{
    file: string;
    position_title?: string | null;
    position_code?: string | null;
    listed: number;
    in_system: number;
    missing: number;
  }>;
  totals: {
    listed: number;
    in_system: number;
    missing: number;
    by_email: number;
    by_name: number;
    by_name_only: number;
  };
  rows: MyJobsRow[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from?: number | null;
    to?: number | null;
    file?: string | null;
    existence?: string | null;
    search?: string | null;
  };
};

export function getApiError(error: unknown, fallback = 'Request failed.'): string {
  if (error instanceof Error && !('response' in error) && error.message) {
    return error.message;
  }
  const ax = error as {
    response?: { status?: number; data?: ApiErrorBody | Blob };
    message?: string;
  };
  const data = ax.response?.data;
  if (data && typeof data === 'object' && !(data instanceof Blob) && 'message' in data && data.message) {
    return String(data.message);
  }
  if (ax.response?.status === 403) {
    return 'You do not have permission to perform this action.';
  }
  return ax.message || fallback;
}

export default api;
