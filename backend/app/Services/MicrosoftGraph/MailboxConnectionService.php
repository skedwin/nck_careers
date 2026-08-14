<?php

namespace App\Services\MicrosoftGraph;

use App\Services\Audit\AuditLogger;
use App\Services\MicrosoftGraph\Exceptions\GraphException;
use Illuminate\Support\Facades\Cache;
use Throwable;

class MailboxConnectionService
{
    private const STATUS_CACHE_KEY = 'mailbox_connection_status';

    public function __construct(
        private readonly GraphAuthService $auth,
        private readonly MailService $mailService,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cached = Cache::get(self::STATUS_CACHE_KEY);

        return [
            'mailbox' => $this->mailService->mailbox(),
            'mock_mode' => $this->auth->isMockMode(),
            'credentials_configured' => $this->auth->isConfigured(),
            'graph_base_url' => config('services.microsoft_graph.base_url'),
            'read_only' => true,
            'last_check' => is_array($cached) ? $cached : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function testConnection(): array
    {
        $started = microtime(true);

        try {
            if (! $this->auth->isMockMode() && ! $this->auth->isConfigured()) {
                throw new GraphException('Microsoft Graph credentials are not configured.');
            }

            $this->auth->getAccessToken(forceRefresh: true);

            $user = null;
            try {
                $user = $this->mailService->getMailboxUser();
            } catch (GraphException $e) {
                // Application Mail.Read may work without User.Read.All.
                // Continue with configured mailbox identity.
            }

            $inbox = $this->mailService->getInboxFolder();
            $sample = $this->mailService->samplePaginatedMessages(maxPages: 2, pageSize: 1);

            $result = [
                'success' => true,
                'mode' => $this->auth->isMockMode() ? 'mock' : 'live',
                'mailbox' => $this->mailService->mailbox(),
                'checked_at' => now()->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'mailbox_user' => [
                    'id' => $user['id'] ?? null,
                    'display_name' => $user['displayName'] ?? 'careers mailbox',
                    'mail' => $user['mail'] ?? ($user['userPrincipalName'] ?? $this->mailService->mailbox()),
                    'profile_resolved' => $user !== null,
                ],
                'inbox' => [
                    'id' => $inbox['id'] ?? null,
                    'display_name' => $inbox['displayName'] ?? null,
                    'total_item_count' => $inbox['totalItemCount'] ?? null,
                    'unread_item_count' => $inbox['unreadItemCount'] ?? null,
                ],
                'sample_messages' => [
                    'pages_retrieved' => $sample['pages'],
                    'messages_retrieved' => count($sample['items']),
                    'has_more' => filled($sample['next_link']),
                    'subjects' => collect($sample['items'])->pluck('subject')->filter()->values()->all(),
                ],
                'message' => 'Mailbox connection successful (read-only).',
            ];

            Cache::put(self::STATUS_CACHE_KEY, $result, now()->addDay());
            $this->auditLogger->log('mailbox.connection_tested', null, null, [
                'success' => true,
                'mode' => $result['mode'],
                'mailbox' => $result['mailbox'],
            ]);

            return $result;
        } catch (Throwable $e) {
            $error = [
                'success' => false,
                'mode' => $this->auth->isMockMode() ? 'mock' : 'live',
                'mailbox' => $this->mailService->mailbox(),
                'checked_at' => now()->toIso8601String(),
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                'message' => $this->safeMessage($e),
                'status_code' => $e instanceof GraphException ? $e->statusCode : null,
                'error_code' => $e instanceof GraphException ? data_get($e->graphError, 'error.code') : null,
            ];

            Cache::put(self::STATUS_CACHE_KEY, $error, now()->addDay());
            $this->auditLogger->log('mailbox.connection_tested', null, null, [
                'success' => false,
                'mode' => $error['mode'],
                'mailbox' => $error['mailbox'],
                'error_code' => $error['error_code'],
            ]);

            return $error;
        }
    }

    private function safeMessage(Throwable $e): string
    {
        $message = $e->getMessage();
        $haystack = strtolower($message);

        if (
            str_contains($haystack, 'client_secret')
            || str_contains($haystack, 'access_token')
            || str_contains($haystack, 'bearer ')
        ) {
            return 'Microsoft Graph connection failed. Check application permissions and mailbox access policy.';
        }

        return $message !== '' ? $message : 'Microsoft Graph connection failed.';
    }
}
