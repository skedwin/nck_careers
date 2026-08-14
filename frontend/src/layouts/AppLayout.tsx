import { NavLink, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

const navItems = [
  { to: '/dashboard', label: 'Dashboard' },
  { to: '/applications', label: 'Applications' },
  { to: '/applicants', label: 'Applicants' },
  { to: '/positions', label: 'Positions' },
  { to: '/screening', label: 'Screening' },
  { to: '/shortlisting', label: 'Shortlisting' },
  { to: '/documents', label: 'Documents' },
  { to: '/mailbox', label: 'Mailbox' },
  { to: '/reports', label: 'Reports' },
  { to: '/users', label: 'Users' },
  { to: '/settings', label: 'Settings' },
  { to: '/audit-logs', label: 'Audit Logs' },
];

export default function AppLayout() {
  const { user, logout } = useAuth();

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[280px_1fr]">
      <aside className="border-r border-nck-green/10 bg-nck-green text-white">
        <div className="border-b border-white/10 px-6 py-6">
          <p className="font-display text-3xl font-bold tracking-tight">NCK</p>
          <p className="mt-1 text-sm text-white/80">Careers Application Management</p>
        </div>
        <nav className="space-y-1 p-4" aria-label="Primary">
          {navItems.map((item) => (
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
