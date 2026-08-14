<?php

namespace App\Services\MicrosoftGraph;

use App\Models\MailSyncState;

class DeltaSyncService
{
    public function __construct(
        private readonly GraphClient $client,
        private readonly MailService $mailService,
    ) {
    }

    public function supported(): bool
    {
        return true;
    }

    public function storeDeltaLink(string $mailbox, string $deltaLink): void
    {
        MailSyncState::query()->updateOrCreate(
            ['mailbox' => $mailbox],
            ['delta_link' => $deltaLink]
        );
    }

    /**
     * Start a delta query for the inbox.
     * Prefer this for historical sync — Graph skip-tokens are more reliable than deep $skip.
     *
     * @return array<string, mixed>
     */
    public function startDelta(): array
    {
        $pageSize = (int) config('services.microsoft_graph.page_size', 50);

        if (app(GraphAuthService::class)->isMockMode()) {
            return $this->mailService->listInboxMessages($pageSize);
        }

        $mailbox = rawurlencode($this->mailService->mailbox());

        return $this->client->get("users/{$mailbox}/mailFolders/inbox/messages/delta", [
            '$select' => implode(',', MailService::MESSAGE_SELECT),
            '$top' => $pageSize,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchDeltaPage(string $deltaOrNextLink): array
    {
        return $this->client->getAbsolute($deltaOrNextLink);
    }
}
