<?php

namespace App\Console\Commands;

use App\Models\Application;
use Illuminate\Console\Command;

class UnhideDuplicatesCommand extends Command
{
    protected $signature = 'applications:unhide-duplicates
        {--position= : Limit to a position id or reference code (e.g. NCK/REC11)}
        {--dry-run : Show how many would be restored without saving}';

    protected $description = 'Restore all hidden duplicates back to the long listing';

    public function handle(): int
    {
        $query = Application::query()->whereNotNull('duplicate_hidden_at');

        $positionOpt = trim((string) $this->option('position'));
        if ($positionOpt !== '') {
            $positionId = ctype_digit($positionOpt)
                ? (int) $positionOpt
                : \App\Models\Position::query()
                    ->where('reference_code', strtoupper($positionOpt))
                    ->value('id');

            if (! $positionId) {
                $this->error("Position [{$positionOpt}] not found.");

                return self::FAILURE;
            }

            $query->where('position_id', $positionId);
            $this->info("Filtering position_id={$positionId}");
        }

        $count = $query->count();

        if ((bool) $this->option('dry-run')) {
            $this->info("Would unhide {$count} duplicate".($count === 1 ? '' : 's').'.');

            return self::SUCCESS;
        }

        $updated = (clone $query)->update([
            'duplicate_hidden_at' => null,
            'duplicate_hidden_by' => null,
            'duplicate_of_application_id' => null,
            'duplicate_of_reference' => null,
        ]);

        $this->info("Unhid {$updated} duplicate".($updated === 1 ? '' : 's').' back to long listing.');

        return self::SUCCESS;
    }
}
