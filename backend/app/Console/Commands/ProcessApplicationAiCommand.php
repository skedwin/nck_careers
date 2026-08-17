<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Services\AI\AiSettings;
use App\Services\AI\ApplicationAiProcessor;
use Illuminate\Console\Command;

class ProcessApplicationAiCommand extends Command
{
    protected $signature = 'ai:process-applications
        {--limit=50 : Max applications to queue}
        {--id= : Process a single application id}
        {--force : Re-run even if an unreviewed extraction exists}';

    protected $description = 'Queue AI-assisted extraction for applications (never auto-decides eligibility)';

    public function handle(ApplicationAiProcessor $processor, AiSettings $settings): int
    {
        if (! $settings->enabled() && ! $this->option('id') && ! $this->option('force')) {
            $this->warn('AI is disabled. Enable ai_enabled in Settings or pass --id / --force.');

            return self::SUCCESS;
        }

        $query = Application::query()->orderBy('id');
        if ($id = $this->option('id')) {
            $query->whereKey((int) $id);
        } else {
            $query->whereDoesntHave('aiExtractions');
        }

        $queued = 0;
        $skipped = 0;
        $limit = max(1, (int) $this->option('limit'));

        foreach ($query->limit($limit)->get() as $application) {
            $extraction = $processor->queue($application, (bool) $this->option('force') || filled($this->option('id')));
            if ($extraction) {
                $queued++;
            } else {
                $skipped++;
            }
        }

        $this->info(sprintf('AI queue: queued=%d skipped=%d provider=%s', $queued, $skipped, $settings->provider()));

        return self::SUCCESS;
    }
}
