import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { AuthProvider } from './context/AuthContext';
import AppLayout from './layouts/AppLayout';
import ApplicantDetailPage from './pages/ApplicantDetailPage';
import ApplicantsPage from './pages/ApplicantsPage';
import ApplicationDetailPage from './pages/ApplicationDetailPage';
import ApplicationsPage from './pages/ApplicationsPage';
import AuditLogsPage from './pages/AuditLogsPage';
import AuthCallbackPage from './pages/AuthCallbackPage';
import DashboardPage from './pages/DashboardPage';
import DocumentsPage from './pages/DocumentsPage';
import LoginPage from './pages/LoginPage';
import MailboxPage from './pages/MailboxPage';
import MyJobsPage from './pages/MyJobsPage';
import PositionsPage from './pages/PositionsPage';
import EmailDuplicatesPage from './pages/EmailDuplicatesPage';
import HiddenDuplicatesPage from './pages/HiddenDuplicatesPage';
import LongListingCategoryPage from './pages/LongListingCategoryPage';
import ReportsPage from './pages/ReportsPage';
import ScreeningPage from './pages/ScreeningPage';
import SettingsPage from './pages/SettingsPage';
import ShortlistingPage from './pages/ShortlistingPage';
import UsersPage from './pages/UsersPage';
import ProtectedRoute, { HomeRedirect } from './routes/ProtectedRoute';

export default function App() {
  return (
    <AuthProvider>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />
          <Route path="/auth/callback" element={<AuthCallbackPage />} />

          <Route element={<ProtectedRoute />}>
            <Route element={<AppLayout />}>
              <Route path="/" element={<HomeRedirect />} />
              <Route path="/dashboard" element={<DashboardPage />} />
              <Route path="/applications" element={<ApplicationsPage />} />
              <Route path="/applications/:id" element={<ApplicationDetailPage />} />
              <Route path="/applicants" element={<ApplicantsPage />} />
              <Route path="/applicants/:id" element={<ApplicantDetailPage />} />
              <Route path="/positions" element={<PositionsPage />} />
              <Route path="/screening" element={<ScreeningPage />} />
              <Route path="/shortlisting" element={<ShortlistingPage />} />
              <Route path="/documents" element={<DocumentsPage />} />
              <Route path="/mailbox" element={<MailboxPage />} />
              <Route path="/myjobs" element={<MyJobsPage />} />
              <Route path="/reports" element={<ReportsPage />} />
              <Route path="/reports/long-listing/:categoryKey" element={<LongListingCategoryPage />} />
              <Route path="/reports/email-duplicates" element={<EmailDuplicatesPage />} />
              <Route path="/reports/hidden-duplicates" element={<HiddenDuplicatesPage />} />
              <Route path="/users" element={<UsersPage />} />
              <Route path="/settings" element={<SettingsPage />} />
              <Route path="/audit-logs" element={<AuditLogsPage />} />
            </Route>
          </Route>

          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
      </BrowserRouter>
    </AuthProvider>
  );
}
