<?php

namespace App\Services\MicrosoftGraph;

use App\Services\MicrosoftGraph\Exceptions\GraphException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GraphClient
{
    public function __construct(private readonly GraphAuthService $auth)
    {
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        if ($this->auth->isMockMode()) {
            return $this->mockGet($path, $query);
        }

        $url = $this->absoluteUrl($path);

        return $this->request('GET', $url, $query);
    }

    /**
     * Follow @odata.nextLink until exhausted or $maxPages reached.
     * Does not hard-cap total messages at 1,000.
     *
     * @param  array<string, mixed>  $query
     * @param  callable(array<int, array<string, mixed>>, array<string, mixed>): void  $onPage
     */
    public function paginate(string $path, array $query, callable $onPage, ?int $maxPages = null): void
    {
        $page = 0;
        $nextUrl = null;
        $initial = true;

        while ($initial || filled($nextUrl)) {
            if ($maxPages !== null && $page >= $maxPages) {
                break;
            }

            if ($initial) {
                $payload = $this->get($path, $query);
                $initial = false;
            } else {
                $payload = $this->getAbsolute((string) $nextUrl);
            }

            $values = $payload['value'] ?? [];
            if (! is_array($values)) {
                $values = [];
            }

            $onPage($values, $payload);
            $page++;
            $nextUrl = $payload['@odata.nextLink'] ?? null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getAbsolute(string $url): array
    {
        if ($this->auth->isMockMode()) {
            return $this->mockGetAbsolute($url);
        }

        return $this->request('GET', $url);
    }

    /**
     * Download a Graph binary stream (e.g. attachment /$value).
     * Uses a dedicated client — Prefer/Accept:json from http() breaks /$value.
     */
    public function getRaw(string $path): string
    {
        if ($this->auth->isMockMode()) {
            return 'mock-attachment-bytes';
        }

        $tmp = tempnam(sys_get_temp_dir(), 'nckatt');
        if ($tmp === false) {
            throw new GraphException('Unable to create temporary file for attachment download.');
        }

        try {
            $this->getRawToFile($path, $tmp);

            $bytes = file_get_contents($tmp);
            if ($bytes === false) {
                throw new GraphException('Unable to read downloaded attachment bytes.');
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Stream an attachment /$value response directly to a local file (avoids large RAM spikes).
     *
     * @return array{size: int, sha256: string}
     */
    public function getRawToFile(string $path, string $destination): array
    {
        if ($this->auth->isMockMode()) {
            file_put_contents($destination, 'mock-attachment-bytes');

            return [
                'size' => strlen('mock-attachment-bytes'),
                'sha256' => hash('sha256', 'mock-attachment-bytes'),
            ];
        }

        $url = $this->absoluteUrl($path);
        $timeout = (int) config('services.microsoft_graph.timeout', 180);

        $directory = dirname($destination);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new GraphException('Unable to create attachment storage directory.');
        }

        $send = function () use ($url, $timeout, $destination) {
            return Http::withToken($this->auth->getAccessToken())
                ->withHeaders([
                    'Accept' => 'application/octet-stream, */*',
                ])
                ->timeout($timeout)
                ->connectTimeout(30)
                ->withOptions([
                    'http_errors' => false,
                    'sink' => $destination,
                ])
                ->get($url);
        };

        try {
            $response = $send();
        } catch (ConnectionException $e) {
            @unlink($destination);
            throw new GraphException(
                'Microsoft Graph timed out while downloading an attachment.',
                408,
                ['error' => ['code' => 'Timeout', 'message' => $e->getMessage()]],
                $e
            );
        }

        if ($response->status() === 401) {
            $this->auth->forgetCachedToken();
            @unlink($destination);
            try {
                $response = $send();
            } catch (ConnectionException $e) {
                @unlink($destination);
                throw new GraphException(
                    'Microsoft Graph timed out while downloading an attachment.',
                    408,
                    ['error' => ['code' => 'Timeout', 'message' => $e->getMessage()]],
                    $e
                );
            }
        }

        if ($response->status() < 200 || $response->status() >= 300) {
            @unlink($destination);
            // Body may be empty when sunk to file; try reading any leftover error JSON from file.
            $json = $response->json();
            if (! is_array($json) && is_file($destination)) {
                $raw = @file_get_contents($destination);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                $json = is_array($decoded) ? $decoded : null;
            }

            $message = is_array($json)
                ? (string) (data_get($json, 'error.message') ?: 'Attachment download failed.')
                : ('Attachment download failed (HTTP '.$response->status().').');

            Log::warning('microsoft_graph.attachment_raw_failed', [
                'status' => $response->status(),
                'url' => Str::limit($url, 250, ''),
                'message' => $message,
            ]);

            throw new GraphException($message, $response->status(), is_array($json) ? $json : null);
        }

        if (! is_file($destination) || filesize($destination) === 0) {
            @unlink($destination);
            throw new GraphException('Attachment /$value response was empty.');
        }

        return [
            'size' => (int) filesize($destination),
            'sha256' => (string) hash_file('sha256', $destination),
        ];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, array $query = []): array
    {
        // Never pass an empty `query` option: Guzzle replaces the URL query string and
        // strips Graph @odata.nextLink $skiptoken / $skip values, causing infinite page loops.
        $options = $query === [] ? [] : ['query' => $query];

        try {
            $response = $this->http()->send($method, $url, $options);
        } catch (ConnectionException $e) {
            Log::warning('microsoft_graph.connection_timeout', [
                'url' => Str::limit($url, 250, ''),
                'error' => $e->getMessage(),
            ]);

            throw new GraphException(
                'Microsoft Graph timed out while reading the mailbox. Use Continue sync to resume from the last page.',
                408,
                ['error' => ['code' => 'Timeout', 'message' => $e->getMessage()]],
                $e
            );
        }

        if ($response->status() === 401) {
            $this->auth->forgetCachedToken();

            try {
                $response = $this->http()->send($method, $url, $options);
            } catch (ConnectionException $e) {
                throw new GraphException(
                    'Microsoft Graph timed out while reading the mailbox. Use Continue sync to resume from the last page.',
                    408,
                    ['error' => ['code' => 'Timeout', 'message' => $e->getMessage()]],
                    $e
                );
            }
        }

        return $this->parseResponse($response->status(), $response->json(), $url);
    }

    private function http(): PendingRequest
    {
        $timeout = (int) config('services.microsoft_graph.timeout', 180);
        $retries = (int) config('services.microsoft_graph.retries', 4);

        return Http::withToken($this->auth->getAccessToken())
            ->acceptJson()
            ->timeout($timeout)
            ->connectTimeout(30)
            ->retry(
                $retries,
                2500,
                function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    if ($exception instanceof RequestException) {
                        $status = $exception->response?->status();

                        return in_array($status, [429, 500, 502, 503, 504], true);
                    }

                    return false;
                },
                throw: true
            )
            ->withHeaders([
                'Prefer' => 'odata.maxpagesize='.(int) config('services.microsoft_graph.page_size', 50),
            ]);
    }

    private function absoluteUrl(string $path): string
    {
        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return rtrim((string) config('services.microsoft_graph.base_url'), '/').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function parseResponse(int $status, ?array $json, string $url): array
    {
        if ($status >= 200 && $status < 300) {
            return $json ?? [];
        }

        Log::warning('microsoft_graph.request_failed', [
            'status' => $status,
            'url' => Str::limit($url, 250, ''),
            'code' => data_get($json, 'error.code'),
        ]);

        $message = (string) (data_get($json, 'error.message') ?: 'Microsoft Graph request failed.');

        throw new GraphException($message, $status, $json);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function mockGet(string $path, array $query): array
    {
        $mailbox = strtolower((string) config('services.microsoft_graph.mailbox'));
        $normalized = strtolower(trim(rawurldecode($path), '/'));

        if (
            $normalized === 'users/'.$mailbox
            || str_starts_with($normalized, 'users/'.$mailbox.'?')
        ) {
            return $this->fixture('mailbox_user.json');
        }

        if (str_contains($normalized, '/mailfolders/inbox') && ! str_contains($normalized, '/messages')) {
            return $this->fixture('inbox_folder.json');
        }

        if (str_contains($normalized, '/attachments')) {
            return $this->mockAttachments($normalized);
        }

        if (str_contains($normalized, '/messages')) {
            return $this->mockMessagesPage($query);
        }

        throw new GraphException("No mock fixture mapped for Graph path [{$path}].");
    }

    /**
     * @return array<string, mixed>
     */
    private function mockAttachments(string $normalizedPath): array
    {
        // Single attachment: .../attachments/{id}
        if (preg_match('#/attachments/([^/]+)$#', $normalizedPath, $matches)) {
            $attachmentId = rawurldecode($matches[1]);

            return [
                '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#attachments/$entity',
                '@odata.type' => '#microsoft.graph.fileAttachment',
                'id' => $attachmentId,
                'name' => 'mock-cv.pdf',
                'contentType' => 'application/pdf',
                'size' => 128,
                'isInline' => false,
                'contentBytes' => base64_encode("%PDF-1.4\n% mock NCK careers attachment\n"),
            ];
        }

        // Attachment collection for a message.
        return [
            '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#attachments',
            'value' => [
                [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'id' => 'AAMkAGMockAttachmentCV',
                    'name' => 'application-cv.pdf',
                    'contentType' => 'application/pdf',
                    'size' => 24576,
                    'isInline' => false,
                ],
                [
                    '@odata.type' => '#microsoft.graph.fileAttachment',
                    'id' => 'AAMkAGMockAttachmentInline',
                    'name' => 'logo.png',
                    'contentType' => 'image/png',
                    'size' => 1024,
                    'isInline' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mockGetAbsolute(string $url): array
    {
        $parts = parse_url($url);
        $query = [];
        parse_str($parts['query'] ?? '', $query);

        $top = (int) ($query['$top'] ?? config('services.microsoft_graph.page_size', 50));
        $skip = (int) ($query['$skip'] ?? 0);

        if (isset($query['$skiptoken']) && is_string($query['$skiptoken']) && preg_match('/mock-(\d+)/', $query['$skiptoken'], $m)) {
            $skip = (int) $m[1];
        }

        // Completed delta token snapshot — empty page.
        if (str_contains($url, 'deltatoken=') && ! isset($query['$skiptoken'])) {
            return [
                '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#messages',
                '@odata.deltaLink' => $url,
                'value' => [],
            ];
        }

        if ($top <= 1) {
            if ($skip > 0 || str_contains($url, 'skiptoken=page2')) {
                return $this->fixture('messages_page_2.json');
            }

            return $this->fixture('messages_page_1.json');
        }

        return $this->mockMessagesPage([
            '$top' => $top,
            '$skip' => $skip,
        ]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function mockMessagesPage(array $query): array
    {
        $top = max(1, (int) ($query['$top'] ?? 25));
        $skip = max(0, (int) ($query['$skip'] ?? 0));
        $total = (int) config('services.microsoft_graph.mock_message_count', 75);

        if ($top <= 1) {
            if ($skip > 0) {
                return $this->fixture('messages_page_2.json');
            }

            return $this->fixture('messages_page_1.json');
        }

        $items = [];
        $end = min($total, $skip + $top);

        for ($i = $skip; $i < $end; $i++) {
            $n = $i + 1;
            $items[] = [
                'id' => sprintf('AAMkAGMockHistorical%05d', $n),
                'internetMessageId' => sprintf('<mock-historical-%05d@nckenya.go.ke>', $n),
                'conversationId' => sprintf('AAQkAGMockConversation%05d', $n),
                'subject' => "Mock application email #{$n}",
                'from' => [
                    'emailAddress' => [
                        'name' => "Applicant {$n}",
                        'address' => "applicant{$n}@example.com",
                    ],
                ],
                'toRecipients' => [[
                    'emailAddress' => [
                        'name' => 'NCK Careers',
                        'address' => 'careers@nckenya.go.ke',
                    ],
                ]],
                'ccRecipients' => [],
                'receivedDateTime' => now()->subDays($n)->toIso8601String(),
                'hasAttachments' => $n % 2 === 0,
                'bodyPreview' => "Dear Sir/Madam, this is mock application {$n}.",
                'webLink' => "https://outlook.office365.com/owa/?ItemID=AAMkAGMockHistorical{$n}",
            ];
        }

        $payload = [
            '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#messages',
            'value' => $items,
        ];

        if ($end < $total) {
            $mailbox = rawurlencode((string) config('services.microsoft_graph.mailbox'));
            $payload['@odata.nextLink'] = rtrim((string) config('services.microsoft_graph.base_url'), '/')
                ."/users/{$mailbox}/mailFolders/inbox/messages/delta?\$top={$top}&\$skiptoken=mock-{$end}";
        } else {
            $payload['@odata.deltaLink'] = rtrim((string) config('services.microsoft_graph.base_url'), '/')
                .'/users/'.rawurlencode((string) config('services.microsoft_graph.mailbox'))
                .'/mailFolders/inbox/messages/delta?$deltatoken=mock-delta-token';
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $filename): array
    {
        $path = base_path('tests/Fixtures/Graph/'.$filename);

        if (! is_file($path)) {
            throw new GraphException("Missing Graph fixture [{$filename}].");
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }
}
