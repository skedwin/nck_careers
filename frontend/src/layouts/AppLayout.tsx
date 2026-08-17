import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

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
    </div>
  );
}
