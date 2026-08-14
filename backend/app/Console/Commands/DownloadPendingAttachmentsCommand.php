<?php

namespace App\Console\Commands;

use App\Services\MicrosoftGraph\AttachmentDownloadDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DownloadPendingAttachmentsCommand extends Command
{
    protected $signature = 'mailbox:download-attachments
        {--limit=100 : Max messages to queue per batch}
        {--until-done : Keep queueing batches until all attachments are downloaded}
        {--sleep=15 : Seconds to wait between refill checks when using --until-done}';

    protected $description = 'Queue attachment downloads (optionally keep going until complete)';

    public function handle(AttachmentDownloadDispatcher $dispatcher): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $untilDone = (bool) $this->option('until-done');
        $sleep = max(5, (int) $this->option('sleep'));

        if (! $untilDone) {
            $queued = $dispatcher->queueBatch($limit);
            if ($queued === 0) {
                $this->info('No messages require attachment download.');
            } else {
                $this->info("Queued {$queued} attachment download job(s) on mail-import.");
            }
            $this->line('Process queues with: php artisan mailbox:queue-work');

            return self::SUCCESS;
        }

        $this->info('Starting continuous attachment download until complete…');
        $this->line('Ensure a durable queue worker is running:');
        $this->line('  php artisan mailbox:queue-work');
        $this->line('(or: php artisan queue:work --queue=mail-sync,mail-import,default --timeout=600 --memory=512 --max-time=1800)');

        $rounds = 0;
        while (true) {
            $progress = $dispatcher->progress();
            $queued = $dispatcher->refillIfNeeded($limit, 30);
            $importJobs = (int) DB::table('jobs')->where('queue', 'mail-import')->count();
            $rounds++;

            $this->line(sprintf(
                '[%s] done=%d/%d (%.1f%%) queued_now=%d mail-import_jobs=%d',
                now()->format('H:i:s'),
                $progress['done'],
                $progress['with_attachments'],
                $progress['percent'],
                $queued,
                $importJobs
            ));

            if ($progress['remaining'] === 0 && $importJobs === 0) {
                $this->info('All attachments downloaded.');

                return self::SUCCESS;
            }

            // Safety: after many idle rounds with zero remaining workable pending but stuck queued.
            if ($rounds % 20 === 0) {
                $this->line('Still working… refresh Mailbox page for live progress.');
            }

            sleep($sleep);
        }
    }
}
