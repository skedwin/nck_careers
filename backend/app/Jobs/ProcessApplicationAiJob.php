<?php

namespace App\Jobs;

use App\Models\AiExtraction;
use App\Services\AI\ApplicationAiProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessApplicationAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $aiExtractionId)
    {
    }

    public function backoff(): array
    {
        return [15, 45, 90];
    }

    public function handle(ApplicationAiProcessor $processor): void
    {
        $extraction = AiExtraction::query()->find($this->aiExtractionId);
        if (! $extraction) {
            return;
        }

        $processor->run($extraction);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('ai.extraction.job_failed', [
            'ai_extraction_id' => $this->aiExtractionId,
            'error' => $exception?->getMessage(),
        ]);

        $extraction = AiExtraction::query()->find($this->aiExtractionId);
        $extraction?->forceFill([
            'status' => AiExtraction::STATUS_FAILED,
            'payload' => ['error' => 'Extraction job failed. Officers can retry from the application.'],
        ])->save();
    }
}
