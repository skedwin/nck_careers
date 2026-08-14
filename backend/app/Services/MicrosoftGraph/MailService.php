<?php

namespace App\Services\MicrosoftGraph;

class MailService
{
    public const MESSAGE_SELECT = [
        'id',
        'internetMessageId',
        'conversationId',
        'subject',
        'from',
        'toRecipients',
        'ccRecipients',
        'receivedDateTime',
        'hasAttachments',
        'bodyPreview',
        'body',
        'webLink',
    ];

    /** Lighter select for list pages when body is fetched separately. */
    public const MESSAGE_SELECT_LIST = [
        'id',
        'internetMessageId',
        'conversationId',
        'subject',
        'from',
        'toRecipients',
        'ccRecipients',
        'receivedDateTime',
        'hasAttachments',
        'bodyPreview',
        'webLink',
    ];

    public function __construct(private readonly GraphClient $client)
    {
    }

    public function mailbox(): string
    {
        return (string) config('services.microsoft_graph.mailbox');
    }

    /**
     * @return array<string, mixed>
     */
    public function getMailboxUser(): array
    {
        $mailbox = rawurlencode($this->mailbox());

        return $this->client->get("users/{$mailbox}", [
            '$select' => 'id,displayName,mail,userPrincipalName',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getInboxFolder(): array
    {
        $mailbox = rawurlencode($this->mailbox());

        return $this->client->get("users/{$mailbox}/mailFolders/inbox", [
            '$select' => 'id,displayName,totalItemCount,unreadItemCount,childFolderCount',
        ]);
    }

    /**
     * Read-only message list. Never mutates mailbox state.
     *
     * @param  array<string, mixed>  $extraQuery
     * @return array<string, mixed>
     */
    public function listInboxMessages(int $top = 25, array $extraQuery = []): array
    {
        $mailbox = rawurlencode($this->mailbox());

        $query = [
            '$top' => max(1, min($top, 50)),
            // List pages omit body to keep sync payloads small; full body is fetched per message when needed.
            '$select' => implode(',', self::MESSAGE_SELECT_LIST),
        ];

        // Graph rejects $orderby with some $filter combinations; omit when filtering.
        if (! isset($extraQuery['$filter'])) {
            $query['$orderby'] = 'receivedDateTime desc';
        }

        return $this->client->get("users/{$mailbox}/mailFolders/inbox/messages", array_merge($query, $extraQuery));
    }

    /**
     * Continue pagination using Graph @odata.nextLink (absolute URL).
     *
     * @return array<string, mixed>
     */
    public function getByNextLink(string $nextLink): array
    {
        return $this->client->getAbsolute($nextLink);
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pages: int, next_link: ?string}
     */
    public function samplePaginatedMessages(int $maxPages = 2, int $pageSize = 1): array
    {
        $items = [];
        $pages = 0;
        $nextLink = null;

        $this->client->paginate(
            'users/'.rawurlencode($this->mailbox()).'/mailFolders/inbox/messages',
            [
                '$top' => $pageSize,
                '$select' => implode(',', self::MESSAGE_SELECT_LIST),
                '$orderby' => 'receivedDateTime desc',
            ],
            function (array $pageItems, array $payload) use (&$items, &$pages, &$nextLink): void {
                foreach ($pageItems as $item) {
                    $items[] = $item;
                }
                $pages++;
                $nextLink = $payload['@odata.nextLink'] ?? null;
            },
            $maxPages
        );

        return [
            'items' => $items,
            'pages' => $pages,
            'next_link' => $nextLink,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getMessage(string $graphMessageId, bool $includeBody = true): array
    {
        $mailbox = rawurlencode($this->mailbox());
        $id = rawurlencode($graphMessageId);
        $select = $includeBody ? self::MESSAGE_SELECT : self::MESSAGE_SELECT_LIST;

        return $this->client->get("users/{$mailbox}/messages/{$id}", [
            '$select' => implode(',', $select),
        ]);
    }
}
