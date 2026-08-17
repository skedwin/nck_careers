<?php

namespace App\Console\Commands;

use App\Models\Position;
use App\Services\Reports\LongListingReportService;
use App\Support\Excel\NckReportExcel;
use Illuminate\Console\Command;

class ExportLongListingCommand extends Command
{
    protected $signature = 'reports:long-listing-export
        {--position= : Position id or NCK/REC code}
        {--path= : Output Excel path (default storage/app/private/reports)}
        {--include-unassigned : Include unassigned applications when exporting all}';

    protected $description = 'Export long listing Excel (.xls) per vacancy category (or all)';

    public function handle(LongListingReportService $service): int
    {
        $positionOpt = $this->option('position');
        $positionId = null;

        if (filled($positionOpt)) {
            if (is_numeric($positionOpt)) {
                $positionId = (int) $positionOpt;
            } else {
                $positionId = Position::query()
                    ->where('reference_code', strtoupper(trim((string) $positionOpt)))
                    ->value('id');
                if (! $positionId) {
                    $this->error("Position [{$positionOpt}] not found.");

                    return self::FAILURE;
                }
            }
        }

        $includeUnassigned = (bool) $this->option('include-unassigned') || $positionId === null;
        $rows = $service->csvRows($positionId, $includeUnassigned);
        $headers = $service->csvHeaders($positionId === null);

        $dir = $this->option('path') ?: storage_path('app/private/reports');
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Unable to create directory [{$dir}].");

            return self::FAILURE;
        }

        $stamp = now()->format('Ymd_His');
        $suffix = $positionId ? "position_{$positionId}" : 'all_categories';
        $file = rtrim((string) $dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR."nck_long_listing_{$suffix}_{$stamp}.xls";

        $path = (new NckReportExcel('Long listing', count($rows).' applicant row(s)'))
            ->addSheet('Long listing', $headers, $rows)
            ->save($file);

        $this->info('Exported '.count($rows)." row(s) to {$path}");

        return self::SUCCESS;
    }
}
