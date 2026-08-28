<?php

use App\Http\Controllers\Api\V1\AiExtractionController;
use App\Http\Controllers\Api\V1\ApplicantController;
use App\Http\Controllers\Api\V1\ApplicationController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MailAttachmentController;
use App\Http\Controllers\Api\V1\MailboxController;
use App\Http\Controllers\Api\V1\MyJobsController;
use App\Http\Controllers\Api\V1\PositionController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\ScreeningController;
use App\Http\Controllers\Api\V1\SettingController;
use App\Http\Controllers\Api\V1\ShortlistController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class);

    Route::prefix('auth')->group(function (): void {
        Route::get('/microsoft/redirect', [AuthController::class, 'redirectToMicrosoft']);
        Route::get('/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback']);
        Route::post('/dev-login', [AuthController::class, 'devLogin'])->middleware('throttle:10,1');

        Route::middleware('auth:sanctum')->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1');
        });
    });

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/dashboard', DashboardController::class)
            ->middleware('permission:applications.view|reports.view');

        Route::get('/settings', [SettingController::class, 'index'])
            ->middleware('permission:settings.view|settings.manage');
        Route::put('/settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.manage');
        Route::post('/settings', [SettingController::class, 'update'])
            ->middleware('permission:settings.manage');

        Route::prefix('mailbox')->group(function (): void {
            Route::get('/status', [MailboxController::class, 'status'])
                ->middleware('permission:mailbox.sync|applications.view');
            Route::post('/test-connection', [MailboxController::class, 'testConnection'])
                ->middleware(['permission:mailbox.sync', 'throttle:10,1']);
            Route::post('/sync', [MailboxController::class, 'startSync'])
                ->middleware(['permission:mailbox.sync', 'throttle:5,1']);
            Route::post('/sync/continue', [MailboxController::class, 'continueSync'])
                ->middleware(['permission:mailbox.sync', 'throttle:10,1']);
            Route::post('/sync/pause', [MailboxController::class, 'pause'])
                ->middleware('permission:mailbox.sync');
            Route::post('/sync/resume', [MailboxController::class, 'resume'])
                ->middleware('permission:mailbox.sync');
            Route::get('/logs', [MailboxController::class, 'logs'])
                ->middleware('permission:mailbox.sync');
            Route::post('/logs/{run}/retry', [MailboxController::class, 'retryFailed'])
                ->middleware(['permission:mailbox.sync', 'throttle:5,1']);
            Route::post('/attachments/download', [MailAttachmentController::class, 'queueDownloads'])
                ->middleware(['permission:mailbox.sync|documents.download', 'throttle:10,1']);
        });

        Route::get('/applications', [ApplicationController::class, 'index'])
            ->middleware('permission:applications.view');
        Route::post('/applications/convert-from-mailbox', [ApplicationController::class, 'convertFromMailbox'])
            ->middleware(['permission:applications.create|applications.update', 'throttle:10,1']);
        Route::post('/applications/unhide-all-duplicates', [ApplicationController::class, 'unhideAllDuplicates'])
            ->middleware('permission:applications.update|applications.profile.update');
        Route::get('/applications/{application}', [ApplicationController::class, 'show'])
            ->middleware('permission:applications.view');
        Route::put('/applications/{application}/profile', [ApplicationController::class, 'updateProfile'])
            ->middleware('permission:applications.update|applications.profile.update');
        Route::post('/applications/{application}/status', [ApplicationController::class, 'updateStatus'])
            ->middleware('permission:applications.update|applications.shortlist|applications.reject');
        Route::post('/applications/{application}/hide-duplicate', [ApplicationController::class, 'hideDuplicate'])
            ->middleware('permission:applications.update|applications.profile.update');
        Route::post('/applications/{application}/unhide-duplicate', [ApplicationController::class, 'unhideDuplicate'])
            ->middleware('permission:applications.update|applications.profile.update');
        Route::post('/applications/{application}/ai/process', [AiExtractionController::class, 'process'])
            ->middleware(['permission:screening.update|applications.update|applications.profile.update', 'throttle:20,1']);
        Route::post('/applications/{application}/ai/review', [AiExtractionController::class, 'review'])
            ->middleware(['permission:screening.update|applications.update|applications.profile.update', 'throttle:20,1']);

        Route::get('/applicants', [ApplicantController::class, 'index'])
            ->middleware('permission:applications.view');
        Route::get('/applicants/{applicant}', [ApplicantController::class, 'show'])
            ->middleware('permission:applications.view');

        Route::get('/positions', [PositionController::class, 'index'])
            ->middleware('permission:applications.view|positions.manage');
        Route::post('/positions', [PositionController::class, 'store'])
            ->middleware('permission:positions.manage');
        Route::get('/positions/{position}', [PositionController::class, 'show'])
            ->middleware('permission:applications.view|positions.manage');
        Route::put('/positions/{position}', [PositionController::class, 'update'])
            ->middleware('permission:positions.manage');
        Route::post('/positions/{position}/criteria', [PositionController::class, 'syncCriteria'])
            ->middleware('permission:positions.manage');

        Route::get('/documents', [DocumentController::class, 'index'])
            ->middleware('permission:documents.view');
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
            ->middleware('permission:documents.download');

        Route::get('/mail-attachments', [MailAttachmentController::class, 'index'])
            ->middleware('permission:documents.view|mailbox.sync');
        Route::get('/mail-attachments/{attachment}/download', [MailAttachmentController::class, 'download'])
            ->middleware('permission:documents.download');

        Route::get('/screening', [ScreeningController::class, 'index'])
            ->middleware('permission:screening.view');
        Route::post('/screening/{application}', [ScreeningController::class, 'upsertResults'])
            ->middleware('permission:screening.update');
        Route::post('/screening/{application}/auto', [ScreeningController::class, 'autoScreen'])
            ->middleware('permission:screening.update');

        Route::get('/shortlisting', [ShortlistController::class, 'index'])
            ->middleware('permission:applications.view|applications.shortlist');
        Route::get('/shortlisting/summary', [ShortlistController::class, 'summary'])
            ->middleware('permission:applications.view|applications.shortlist');
        Route::get('/shortlisting/grouped', [ShortlistController::class, 'grouped'])
            ->middleware('permission:applications.view|applications.shortlist');
        Route::get('/shortlisting/export/excel', [ShortlistController::class, 'exportExcel'])
            ->middleware('permission:applications.view|applications.shortlist');
        Route::get('/shortlisting/export/pdf', [ShortlistController::class, 'exportPdf'])
            ->middleware('permission:applications.view|applications.shortlist');
        Route::post('/shortlisting/{application}', [ShortlistController::class, 'shortlist'])
            ->middleware('permission:applications.shortlist');

        Route::get('/myjobs', [MyJobsController::class, 'index'])
            ->middleware('permission:applications.view|reports.view');
        Route::get('/myjobs/export', [MyJobsController::class, 'export'])
            ->middleware('permission:applications.view|reports.view');
        Route::post('/myjobs/import', [MyJobsController::class, 'import'])
            ->middleware(['permission:applications.create|applications.update', 'throttle:5,1']);
        Route::post('/myjobs/link-attachments', [MyJobsController::class, 'linkAttachments'])
            ->middleware(['permission:applications.create|applications.update', 'throttle:3,1']);

        Route::get('/reports/summary', [ReportController::class, 'summary'])
            ->middleware('permission:reports.view');
        Route::get('/reports/long-listing', [ReportController::class, 'longListing'])
            ->middleware('permission:reports.view');
        Route::get('/reports/long-listing/export', [ReportController::class, 'longListingExport'])
            ->middleware('permission:reports.view');
        Route::get('/reports/long-listing/{category}', [ReportController::class, 'longListingCategory'])
            ->where('category', 'unassigned|[0-9]+')
            ->middleware('permission:reports.view');
        Route::get('/reports/email-duplicates', [ReportController::class, 'emailDuplicates'])
            ->middleware('permission:reports.view');
        Route::get('/reports/email-duplicates/export', [ReportController::class, 'emailDuplicatesExport'])
            ->middleware('permission:reports.view');
        Route::get('/reports/hidden-duplicates', [ReportController::class, 'hiddenDuplicates'])
            ->middleware('permission:reports.view');
        Route::get('/reports/hidden-duplicates/export', [ReportController::class, 'hiddenDuplicatesExport'])
            ->middleware('permission:reports.view');

        Route::get('/users', [UserController::class, 'index'])
            ->middleware('permission:users.manage');
        Route::post('/users/{user}/role', [UserController::class, 'updateRole'])
            ->middleware('permission:users.manage');
        Route::post('/users/{user}/password', [UserController::class, 'updatePassword'])
            ->middleware(['permission:users.manage', 'throttle:10,1']);

        Route::get('/audit-logs', [AuditLogController::class, 'index'])
            ->middleware('permission:audit.view');
    });
});
