import { NavLink, Outlet } from 'react-router-dom';
import { useState } from 'react';
import { useAuth } from '../context/AuthContext';
import api, { getApiError } from '../lib/api';

const navItems = [
  { to: '/dashboard', label: 'Dashboard', permission: 'applications.view' },
  { to: '/applications', label: 'Applications', permission: 'applications.view' },
  { to: '/applicants', label: 'Applicants', permission: 'applications.view' },
  { to: '/positions', label: 'Positions', permission: 'applications.view' },
  { to: '/screening', label: 'Screening', permission: 'screening.view' },
  { to: '/shortlisting', label: 'Shortlisting', permission: 'applications.shortlist' },
  { to: '/documents', label: 'Documents', permission: 'documents.view' },
  { to: '/mailbox', label: 'Mailbox', permission: 'mailbox.sync' },
  { to: '/myjobs', label: 'MyJobs', permission: 'applications.view' },
  { to: '/reports', label: 'Reports', permission: 'reports.view' },
  { to: '/users', label: 'Users', permission: 'users.manage' },
  { to: '/settings', label: 'Settings', permission: 'settings.view' },
  { to: '/audit-logs', label: 'Audit Logs', permission: 'audit.view' },
];

function canAccessNavItem(permissions: string[] | undefined, required?: string): boolean {
  if (!required) {
    return true;
  }

  return permissions?.includes(required) ?? false;
}

export default function AppLayout() {
  const { user, logout } = useAuth();
  const visibleNavItems = navItems.filter((item) => canAccessNavItem(user?.permissions, item.permission));
  const [passwordOpen, setPasswordOpen] = useState(false);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [passwordError, setPasswordError] = useState<string | null>(null);
  const [passwordSuccess, setPasswordSuccess] = useState<string | null>(null);
  const [savingPassword, setSavingPassword] = useState(false);

  const closePassword = () => {
    setPasswordOpen(false);
    setCurrentPassword('');
    setNewPassword('');
    setConfirmPassword('');
    setPasswordError(null);
    setPasswordSuccess(null);
  };

  const submitPassword = async (event: React.FormEvent) => {
    event.preventDefault();
    setPasswordError(null);
    setPasswordSuccess(null);
    if (newPassword !== confirmPassword) {
      setPasswordError('Password confirmation does not match.');
      return;
    }
    setSavingPassword(true);
    try {
      await api.post('/auth/password', {
        current_password: currentPassword,
        password: newPassword,
        password_confirmation: confirmPassword,
      });
      setPasswordSuccess('Password updated.');
      setCurrentPassword('');
      setNewPassword('');
      setConfirmPassword('');
    } catch (error) {
      setPasswordError(getApiError(error, 'Unable to update password.'));
    } finally {
      setSavingPassword(false);
    }
  };

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
      <aside className="border-r border-nck-green/10 bg-nck-green text-white">
        <div className="border-b border-white/10 px-6 py-6">
          <p className="font-display text-3xl font-bold tracking-tight">NCK</p>
          <p className="mt-1 text-sm text-white/80">Careers Application Management</p>
        </div>
        <nav className="space-y-1 p-4" aria-label="Primary">
          {visibleNavItems.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              className={({ isActive }) =>
                [
                  'block rounded-lg px-3 py-2 text-sm font-medium transition',
                  isActive ? 'bg-white text-nck-green' : 'text-white/85 hover:bg-white/10',
                ].join(' ')
              }
            >
              {item.label}
            </NavLink>
          ))}
        </nav>
      </aside>

      <div className="flex min-h-screen flex-col">
        <header className="flex items-center justify-between border-b border-nck-green/10 bg-white/80 px-6 py-4 backdrop-blur">
          <div>
            <p className="text-xs uppercase tracking-[0.2em] text-nck-green/70">Nursing Council of Kenya</p>
            <h1 className="font-display text-2xl font-semibold text-nck-slate">Recruitment Workspace</h1>
          </div>
          <div className="flex items-center gap-4">
            <div className="text-right">
              <p className="text-sm font-semibold text-nck-slate">{user?.display_name}</p>
              <p className="text-xs text-slate-500">{user?.roles?.[0] ?? 'Staff'}</p>
            </div>
            <button
              type="button"
              onClick={() => {
                setPasswordError(null);
                setPasswordSuccess(null);
                setPasswordOpen(true);
              }}
              className="rounded-lg border border-nck-green/20 px-3 py-2 text-sm font-medium text-nck-green hover:bg-nck-greenLight"
            >
              Change password
            </button>
            <button
              type="button"
              onClick={() => void logout()}
              className="rounded-lg border border-nck-green/20 px-3 py-2 text-sm font-medium text-nck-green hover:bg-nck-greenLight"
            >
              Sign out
            </button>
          </div>
        </header>
        <main className="flex-1 p-6">
          <Outlet />
        </main>
      </div>

      {passwordOpen && (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4">
          <form
            className="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl"
            onSubmit={(event) => void submitPassword(event)}
          >
            <h3 className="font-display text-xl font-semibold text-nck-slate">Change password</h3>
            <p className="mt-1 text-sm text-slate-600">Use at least 10 characters.</p>
            {passwordError && (
              <p className="mt-3 rounded-xl border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" role="alert">
                {passwordError}
              </p>
            )}
            {passwordSuccess && (
              <p className="mt-3 rounded-xl border border-nck-green/20 bg-nck-greenLight px-3 py-2 text-sm text-nck-green" role="status">
                {passwordSuccess}
              </p>
            )}
            <label className="mt-4 block space-y-1 text-sm">
              <span className="text-slate-600">Current password</span>
              <input
                type="password"
                value={currentPassword}
                onChange={(event) => setCurrentPassword(event.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                required
                autoComplete="current-password"
              />
            </label>
            <label className="mt-3 block space-y-1 text-sm">
              <span className="text-slate-600">New password</span>
              <input
                type="password"
                value={newPassword}
                onChange={(event) => setNewPassword(event.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                minLength={10}
                required
                autoComplete="new-password"
              />
            </label>
            <label className="mt-3 block space-y-1 text-sm">
              <span className="text-slate-600">Confirm new password</span>
              <input
                type="password"
                value={confirmPassword}
                onChange={(event) => setConfirmPassword(event.target.value)}
                className="w-full rounded-xl border border-slate-200 px-3 py-2"
                minLength={10}
                required
                autoComplete="new-password"
              />
            </label>
            <div className="mt-5 flex justify-end gap-2">
              <button
                type="button"
                className="rounded-xl border border-slate-200 px-4 py-2 text-sm text-slate-600"
                onClick={closePassword}
              >
                Close
              </button>
              <button
                type="submit"
                disabled={savingPassword}
                className="rounded-xl bg-nck-green px-4 py-2 text-sm font-semibold text-white disabled:opacity-60"
              >
                {savingPassword ? 'Saving…' : 'Update password'}
              </button>
            </div>
          </form>
        </div>
      )}
    </div>
  );
}
