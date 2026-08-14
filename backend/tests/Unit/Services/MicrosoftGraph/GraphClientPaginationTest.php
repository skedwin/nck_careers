<?php

namespace Tests\Unit\Services\MicrosoftGraph;

use App\Services\MicrosoftGraph\GraphAuthService;
use App\Services\MicrosoftGraph\GraphClient;
use App\Services\MicrosoftGraph\MailService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GraphClientPaginationTest extends TestCase
{
    public function test_paginate_follows_next_link_without_hard_limit(): void
    {
        config([
            'services.microsoft_graph.mock_mode' => true,
            'services.microsoft_graph.mailbox' => 'careers@nckenya.go.ke',
        ]);

        $mail = app(MailService::class);
        $sample = $mail->samplePaginatedMessages(maxPages: 2, pageSize: 1);

        $this->assertSame(2, $sample['pages']);
        $this->assertCount(2, $sample['items']);
        $this->assertSame('AAMkAGMockMessage001', $sample['items'][0]['id']);
        $this->assertSame('AAMkAGMockMessage002', $sample['items'][1]['id']);
    }

    public function test_get_absolute_preserves_next_link_query_string(): void
    {
        config([
            'services.microsoft_graph.mock_mode' => false,
            'services.microsoft_graph.tenant_id' => 'tenant-id',
            'services.microsoft_graph.client_id' => 'client-id',
            'services.microsoft_graph.client_secret' => 'client-secret',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'unit-test-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
            'graph.microsoft.com/*' => Http::response([
                '@odata.context' => 'https://graph.microsoft.com/v1.0/$metadata#messages',
                'value' => [['id' => 'AAMkPage2']],
            ], 200),
        ]);

        app(GraphAuthService::class)->getAccessToken(forceRefresh: true);

        $next = 'https://graph.microsoft.com/v1.0/users/careers@nckenya.go.ke/mailFolders/inbox/messages?$skip=50&$top=50';
        app(GraphClient::class)->getAbsolute($next);

        Http::assertSent(function ($request) use ($next) {
            if (! str_contains($request->url(), 'mailFolders/inbox/messages')) {
                return false;
            }

            // Guzzle must not strip $skip/$top from the absolute nextLink.
            return str_contains($request->url(), '$skip=50')
                || str_contains($request->url(), '%24skip=50');
        });
    }

    public function test_live_token_request_shape_is_mocked_for_tests(): void
    {
        config([
            'services.microsoft_graph.mock_mode' => false,
            'services.microsoft_graph.tenant_id' => 'tenant-id',
            'services.microsoft_graph.client_id' => 'client-id',
            'services.microsoft_graph.client_secret' => 'client-secret',
        ]);

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'unit-test-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ], 200),
        ]);

        $token = app(GraphAuthService::class)->getAccessToken(forceRefresh: true);

        $this->assertSame('unit-test-token', $token);
        Http::assertSentCount(1);
    }
}
