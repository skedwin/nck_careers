import { useEffect } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';

export default function AuthCallbackPage() {
  const [params] = useSearchParams();
  const navigate = useNavigate();
  const { loginWithToken } = useAuth();

  useEffect(() => {
    const token = params.get('token');
    if (!token) {
      navigate('/login?error=missing_token', { replace: true });
      return;
    }

    void loginWithToken(token).then(() => navigate('/dashboard', { replace: true }));
  }, [params, loginWithToken, navigate]);

  return (
    <div className="flex min-h-screen items-center justify-center text-sm text-slate-600">
      Completing Microsoft sign-in…
    </div>
  );
}
