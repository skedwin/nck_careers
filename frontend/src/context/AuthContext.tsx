import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import api, { type ApiSuccess, type User } from '../lib/api';

type AuthContextValue = {
  user: User | null;
  token: string | null;
  loading: boolean;
  loginWithToken: (token: string) => Promise<void>;
  devLogin: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  refreshUser: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | undefined>(undefined);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(localStorage.getItem('nck_token'));
  const [loading, setLoading] = useState(true);

  const refreshUser = useCallback(async () => {
    if (!localStorage.getItem('nck_token')) {
      setUser(null);
      setLoading(false);
      return;
    }

    try {
      const { data } = await api.get<ApiSuccess<User>>('/auth/me');
      setUser(data.data);
    } catch {
      localStorage.removeItem('nck_token');
      setToken(null);
      setUser(null);
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void refreshUser();
  }, [refreshUser]);

  const loginWithToken = useCallback(async (nextToken: string) => {
    localStorage.setItem('nck_token', nextToken);
    setToken(nextToken);
    setLoading(true);
    await refreshUser();
  }, [refreshUser]);

  const devLogin = useCallback(async (email: string, password: string) => {
    const { data } = await api.post<ApiSuccess<{ token: string; user: User }>>('/auth/dev-login', {
      email,
      password,
    });
    localStorage.setItem('nck_token', data.data.token);
    setToken(data.data.token);
    setUser(data.data.user);
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout');
    } catch {
      // Ignore network failures on logout
    }
    localStorage.removeItem('nck_token');
    setToken(null);
    setUser(null);
  }, []);

  const value = useMemo(
    () => ({ user, token, loading, loginWithToken, devLogin, logout, refreshUser }),
    [user, token, loading, loginWithToken, devLogin, logout, refreshUser],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within AuthProvider');
  }
  return context;
}
