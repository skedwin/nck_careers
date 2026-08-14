<?php

namespace App\Console\Commands;

use App\Models\MailMessage;
use App\Services\Applications\ApplicationIngestionService;
use App\Services\Applications\ApplicationProfileEnricher;
use App\Services\MicrosoftGraph\AttachmentDownloadDispatcher;
use App\Services\MicrosoftGraph\CloudLinkExtractor;
use App\Services\MicrosoftGraph\DownloadMailAttachmentsService;
use App\Services\MicrosoftGraph\MailService;
use Illuminate\Console\Command;
use Throwable;

class ReprocessCloudLinksCommand extends Command
{
    protected $signature = 'mailbox:reprocess-cloud-links
        {--sender= : Filter by sender email fragment (e.g. paulawarinda)}
        {--limit=100 : Max messages to process this run}
        {--queue : Queue DownloadMailAttachmentsJob instead of processing downloads inline}
        {--missing-body : Only messages with empty body_html}
        {--force-sync : Re-run full attachment sync even when already downloaded and no new links}
        {--append-docs : Only append existing mail attachments / cloud links onto applications}';

    protected $description = 'Refresh message bodies from Graph, extract Drive/SharePoint links, and download when accessible';

    public function handle(
        MailService $mail,
        DownloadMailAttachmentsService $downloads,
        AttachmentDownloadDispatcher $dispatcher,
        CloudLinkExtractor $links,
        ApplicationProfileEnricher $profiles,
        ApplicationIngestionService $ingestion,
    ): int {
        if ((bool) $this->option('append-docs')) {
            return $this->appendDocsOnly($ingestion);
        }

        $limit = max(1, (int) $this->option('limit'));
        $sender = trim((string) $this->option('sender'));
        $queue = (bool) $this->option('queue');
        $missingBody = (bool) $this->option('missing-body');
        $forceSync = (bool) $this->option('force-sync');

        $query = MailMessage::query()->orderBy('id');

        if ($sender !== '') {
            $query->where(function ($q) use ($sender): void {
                $q->where('sender_email', 'like', '%'.$sender.'%')
                    ->orWhere('sender_name', 'like', '%'.$sender.'%');
            });
        }

        if ($missingBody) {
            $query->where(function ($q): void {
                $q->whereNull('body_html')->orWhere('body_html', '');
            });
        }

        $messages = $query->limit($limit)->get();
        if ($messages->isEmpty()) {
            $this->info('No matching messages.');

            return self::SUCCESS;
        }

        $total = $messages->count();
        $this->info("Processing {$total} message(s)…");
        if (! $queue) {
            $this->comment('Tip: use --queue so downloads run on mailbox:queue-work (much faster for large batches).');
        }

        $refreshed = 0;
        $withLinks = 0;
        $queued = 0;
        $synced = 0;
        $skipped = 0;
        $errors = 0;
        $docsAppended = 0;
        $index = 0;

        foreach ($messages as $message) {
            $index++;
            $label = sprintf('[%d/%d] id=%d', $index, $total, $message->id);

            try {
                if (! filled($message->body_html) && filled($message->graph_message_id)) {
                    $this->line("{$label} fetching body from Graph…");
                    $payload = $mail->getMessage((string) $message->graph_message_id, true);
                    $downloads->applyBodyFromGraphPayload($message, $payload);
                    $message->save();
                    $refreshed++;
                }

                $found = $links->extract($message->body_html, $message->body_text);
                $needsIncompleteAttachmentWork = $message->has_attachments
                    && in_array($message->attachments_status, ['pending', 'failed', 'partial', 'queued'], true);

                if ($found !== []) {
                    $withLinks++;
                    $message->forceFill([
                        'has_attachments' => true,
                        'attachments_status' => in_array($message->attachments_status, ['downloaded'], true)
                            ? 'partial'
                            : (
                                in_array($message->attachments_status, ['pending', 'failed', 'partial', 'queued'], true)
                                    ? $message->attachments_status
                                    : 'pending'
                            ),
                    ])->save();

                    $this->line("{$label} cloud links=".count($found)." → ".($queue ? 'queue' : 'sync'));
                    if ($queue) {
                        $queued += $dispatcher->queueMessageIds([$message->id]);
                    } else {
                        $fresh = $message->fresh();
                        if ($fresh) {
                            $downloads->syncMessage($fresh);
                            $docsAppended += $ingestion->syncDocumentsFromMailMessage($fresh->fresh() ?? $fresh);
                            $synced++;
                        }
                    }
                } elseif ($forceSync || $needsIncompleteAttachmentWork) {
                    $this->line("{$label} attachments_status={$message->attachments_status} → ".($queue ? 'queue' : 'sync'));
                    if ($queue) {
                        $queued += $dispatcher->queueMessageIds([$message->id]);
                    } else {
                        $fresh = $message->fresh();
                        if ($fresh) {
                            $downloads->syncMessage($fresh);
                            $docsAppended += $ingestion->syncDocumentsFromMailMessage($fresh->fresh() ?? $fresh);
                            $synced++;
                        }
                    }
                } else {
                    $this->line("{$label} body ok, no new cloud links — skip");
                    $skipped++;
                }

                $fresh = $message->fresh();
                if ($fresh && (filled($fresh->body_html) || filled($fresh->body_text))) {
                    $profiles->enrichFromMailMessage($fresh);
                }
            } catch (Throwable $e) {
                $errors++;
                $this->warn("{$label} ERROR: ".$e->getMessage());
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count'],
            [
                ['Bodies refreshed', $refreshed],
                ['Messages with cloud links', $withLinks],
                ['Synced inline', $synced],
                ['Jobs queued', $queued],
                ['Docs appended (inline)', $docsAppended],
                ['Skipped (already complete)', $skipped],
                ['Errors', $errors],
            ]
        );

        $remaining = MailMessage::query()
            ->where(function ($q): void {
                $q->whereNull('body_html')->orWhere('body_html', '');
            })
            ->count();
        $this->info("Messages still missing body_html: {$remaining}");

        return self::SUCCESS;
    }

    private function appendDocsOnly(ApplicationIngestionService $ingestion): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $sender = trim((string) $this->option('sender'));

        $query = MailMessage::query()
            ->whereHas('attachments', function ($q): void {
                $q->where(function ($inner): void {
                    $inner->where(function ($downloaded): void {
                        $downloaded->where('download_status', 'downloaded')->whereNotNull('path');
                    })->orWhere(function ($linkOnly): void {
                        $linkOnly->where('download_status', 'link_only')->whereNotNull('external_url');
                    });
                });
            })
            ->whereHas('application')
            ->orderBy('id');

        if ($sender !== '') {
            $query->where(function ($q) use ($sender): void {
                $q->where('sender_email', 'like', '%'.$sender.'%')
                    ->orWhere('sender_name', 'like', '%'.$sender.'%');
            });
        }

        $messages = $query->limit($limit)->get();
        if ($messages->isEmpty()) {
            $this->info('No messages with usable attachments to append.');

            return self::SUCCESS;
        }

        $docs = 0;
        $apps = 0;
        foreach ($messages as $message) {
            $count = $ingestion->syncDocumentsFromMailMessage($message);
            if ($count > 0) {
                $apps++;
                $docs += $count;
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Messages scanned', $messages->count()],
                ['Applications updated', $apps],
                ['Documents upserted', $docs],
            ]
        );

        return self::SUCCESS;
    }
}
