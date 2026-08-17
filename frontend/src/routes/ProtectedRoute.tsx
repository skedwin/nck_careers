import { Navigate, Outlet } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

function defaultPathForUser(permissions: string[] | undefined): string {
  if (permissions?.includes('applications.view')) {
    return '/dashboard';
  }
  if (permissions?.includes('reports.view')) {
    return '/reports';
  }

  return '/dashboard';
}

export default function ProtectedRoute() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center text-sm text-slate-600">
        Checking session…
      </div>
    );
  }

  if (!user) {
    return <Navigate to="/login" replace />;
  }

  return <Outlet />;
}

export function HomeRedirect() {
  const { user, loading } = useAuth();

  if (loading) {
    return (
      <div className="flex min-h-screen items-center justify-center text-sm text-slate-600">
        Checking session…
      </div>
    );
  }

  return <Navigate to={defaultPathForUser(user?.permissions)} replace />;
}
