import { type FormEvent, useState } from 'react';
import { Navigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function LoginPage() {
  const { user, loading, devLogin } = useAuth();
  const [searchParams] = useSearchParams();
  const [email, setEmail] = useState('admin@nckenya.go.ke');
  const [password, setPassword] = useState('ChangeMeNow!123');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  if (!loading && user) {
    return <Navigate to="/dashboard" replace />;
  }

  const authError = searchParams.get('error');

  const onSubmit = async (event: FormEvent) => {
    event.preventDefault();
    setSubmitting(true);
    setError(null);
    try {
      await devLogin(email, password);
    } catch {
      setError('Unable to sign in. Confirm AUTH_DEV_LOGIN is enabled and credentials are correct.');
    } finally {
      setSubmitting(false);
    }
  };

  const microsoftLoginUrl = `${import.meta.env.VITE_API_BASE_URL}/auth/microsoft/redirect`;

  return (
    <div className="flex min-h-screen items-center justify-center px-4">
      <div className="grid w-full max-w-5xl overflow-hidden rounded-3xl bg-white shadow-panel lg:grid-cols-[1.1fr_0.9fr]">
        <section className="relative hidden bg-nck-green p-10 text-white lg:block">
          <div className="absolute inset-0 bg-[radial-gradient(circle_at_20%_20%,rgba(196,163,90,0.35),transparent_45%)]" />
          <div className="relative flex h-full flex-col justify-between">
            <div>
              <p className="text-sm uppercase tracking-[0.25em] text-white/70">Nursing Council of Kenya</p>
              <h1 className="mt-4 font-display text-5xl font-bold leading-tight">NCK Careers</h1>
              <p className="mt-4 max-w-md text-base text-white/85">
                Secure recruitment application management for applications received at careers@nckenya.go.ke.
              </p>
            </div>
            <p className="text-sm text-white/70">Microsoft 365 SSO · Audit trail · Human-led decisions</p>
          </div>
        </section>

        <section className="p-8 sm:p-10">
          <h2 className="font-display text-3xl font-semibold text-nck-slate">Sign in</h2>
          <p className="mt-2 text-sm text-slate-600">Use your NCK Microsoft 365 organizational account.</p>

          {(authError || error) && (
            <div className="mt-4 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
              {error ?? `Authentication error: ${authError}`}
            </div>
          )}

          <a
            href={microsoftLoginUrl}
            className="mt-6 flex w-full items-center justify-center rounded-xl bg-nck-green px-4 py-3 text-sm font-semibold text-white hover:bg-nck-greenDark"
          >
            Sign in with Microsoft 365
          </a>

          <div className="my-8 flex items-center gap-3 text-xs uppercase tracking-wide text-slate-400">
            <div className="h-px flex-1 bg-slate-200" />
            Local development
            <div className="h-px flex-1 bg-slate-200" />
          </div>

          <form onSubmit={onSubmit} className="space-y-4">
            <div>
              <label htmlFor="email" className="mb-1 block text-sm font-medium text-slate-700">
                Email
              </label>
              <input
                id="email"
                type="email"
                autoComplete="username"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                required
              />
            </div>
            <div>
              <label htmlFor="password" className="mb-1 block text-sm font-medium text-slate-700">
                Password
              </label>
              <input
                id="password"
                type="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2.5 text-sm"
                required
              />
            </div>
            <button
              type="submit"
              disabled={submitting}
              className="w-full rounded-xl border border-nck-green/20 bg-nck-greenLight px-4 py-3 text-sm font-semibold text-nck-green hover:bg-nck-green/10 disabled:opacity-60"
            >
              {submitting ? 'Signing in…' : 'Continue with local admin'}
            </button>
          </form>
        </section>
      </div>
    </div>
  );
}
