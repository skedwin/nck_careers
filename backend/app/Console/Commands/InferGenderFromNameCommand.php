<?php

namespace App\Console\Commands;

use App\Models\Application;
use App\Models\Position;
use App\Support\GenderFromFirstName;
use Illuminate\Console\Command;

class InferGenderFromNameCommand extends Command
{
    protected $signature = 'applications:infer-gender-from-name
        {--position= : Position id or reference code (e.g. NCK/REC11)}
        {--overwrite : Replace already set gender}
        {--dry-run : Show what would change without saving}';

    protected $description = 'Set applicant gender from first name when missing (or overwrite with --overwrite)';

    public function handle(GenderFromFirstName $inferrer): int
    {
        $positionOpt = trim((string) $this->option('position'));
        if ($positionOpt === '') {
            $this->error('Provide --position=NCK/REC11 (or position id).');

            return self::FAILURE;
        }

        $position = Position::query()
            ->when(
                ctype_digit($positionOpt),
                fn ($q) => $q->whereKey((int) $positionOpt),
                fn ($q) => $q->where('reference_code', strtoupper($positionOpt))
            )
            ->first();

        if (! $position) {
            $this->error("Position [{$positionOpt}] not found.");

            return self::FAILURE;
        }

        $overwrite = (bool) $this->option('overwrite');
        $dryRun = (bool) $this->option('dry-run');

        $query = Application::query()
            ->with('applicant:id,full_name,gender')
            ->where('position_id', $position->id)
            ->whereHas('applicant');

        if (! $overwrite) {
            $query->whereHas('applicant', function ($q): void {
                $q->whereNull('gender')->orWhere('gender', '');
            });
        }

        $updated = 0;
        $skipped = 0;
        $unknown = 0;
        $male = 0;
        $female = 0;

        foreach ($query->cursor() as $application) {
            $applicant = $application->applicant;
            if (! $applicant) {
                $skipped++;
                continue;
            }

            $gender = $inferrer->infer($applicant->full_name);
            if ($gender === null) {
                $unknown++;
                continue;
            }

            if (! $overwrite && filled($applicant->gender)) {
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("id={$application->id} {$applicant->full_name} -> {$gender}");
            } else {
                $applicant->forceFill(['gender' => $gender])->save();
            }

            $updated++;
            if ($gender === 'Male') {
                $male++;
            } else {
                $female++;
            }
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Position', $position->reference_code.' · '.$position->title],
                ['Updated', $updated.($dryRun ? ' (dry-run)' : '')],
                ['Male', $male],
                ['Female', $female],
                ['Unknown first name', $unknown],
                ['Skipped', $skipped],
            ]
        );

        return self::SUCCESS;
    }
}
