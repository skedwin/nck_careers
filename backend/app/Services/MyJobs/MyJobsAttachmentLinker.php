<?php

namespace App\Services\MyJobs;

use App\Models\Application;
use App\Models\ApplicationDocument;
use App\Models\Position;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class MyJobsAttachmentLinker
{
    public function __construct(private readonly MyJobsListingService $listing)
    {
    }

    public function directory(): string
    {
        return storage_path('app/private/myjobs_files');
    }

    /**
     * @return array{
     *   zips_extracted: int,
     *   packs: int,
     *   linked: int,
     *   documents: int,
     *   unmatched: int,
     *   ambiguous: int,
     *   skipped: int,
     *   dry_run: bool,
     *   unmatched_samples: list<string>
     * }
     */
    public function link(bool $dryRun = false): array
    {
        $counts = [
            'zips_extracted' => 0,
            'packs' => 0,
            'linked' => 0,
            'documents' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'skipped' => 0,
            'dry_run' => $dryRun,
            'unmatched_samples' => [],
        ];

        $root = $this->directory();
        if (! is_dir($root)) {
            return $counts;
        }

        $applications = $this->myJobsApplications();
        $byPortal = [];
        $byPosition = [];
        foreach ($applications as $application) {
            $portalId = data_get($application->profile_extraction, 'myjobs.portal_applicant_id');
            if (filled($portalId)) {
                $byPortal[(int) $portalId][] = $application;
            }
            $byPosition[(int) $application->position_id][] = $application;
        }

        foreach ($this->jobFolders($root) as $jobFolder) {
            $positionId = $this->positionIdForFolder($jobFolder);
            if (! $positionId) {
                $counts['skipped']++;
                continue;
            }

            $this->extractZips($jobFolder, $counts, $dryRun);

            foreach ($this->applicantPacks($jobFolder) as $pack) {
                $counts['packs']++;
                $files = $this->packFiles($pack['dir']);
                $name = $this->applicantNameFromFiles($files)
                    ?: $this->applicantNameFromFiles($this->zipEntryNames($pack['zip']));

                if ($files === []) {
                    $zipNames = $this->zipEntryNames($pack['zip']);
                    if ($dryRun && $zipNames !== []) {
                        $files = $zipNames;
                    } else {
                        $counts['skipped']++;
                        continue;
                    }
                }

                $matches = $this->matchApplications(
                    $byPortal,
                    $byPosition,
                    $positionId,
                    $pack['portal_id'],
                    $name,
                );

                if (count($matches) === 0) {
                    $counts['unmatched']++;
                    if (count($counts['unmatched_samples']) < 12) {
                        $counts['unmatched_samples'][] = basename($jobFolder).' / applicant-'.$pack['portal_id'].' / '.($name ?: 'unknown name');
                    }
                    continue;
                }
                if (count($matches) > 1) {
                    $counts['ambiguous']++;
                    continue;
                }

                $application = $matches[0];
                if ($dryRun) {
                    $counts['linked']++;
                    $counts['documents'] += count($files);
                    continue;
                }

                $linked = $this->attachFiles($application, $files);
                $this->rememberPortalId($application, $pack['portal_id']);
                $counts['linked']++;
                $counts['documents'] += $linked;
            }
        }

        return $counts;
    }

    /**
     * @param  list<string>  $filenames
     */
    public function applicantNameFromFiles(array $filenames): ?string
    {
        foreach ($filenames as $filename) {
            $base = pathinfo(basename((string) $filename), PATHINFO_FILENAME);
            if (preg_match('/^\d{1,4}[-_](.+)$/u', $base, $m)) {
                $name = $this->slugToName($m[1]);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        foreach ($filenames as $filename) {
            $base = pathinfo(basename((string) $filename), PATHINFO_FILENAME);
            if (preg_match('/^(.+)_(?:profile|questionnaire)$/iu', $base, $m)) {
                $name = $this->slugToName($m[1]);
                if ($name !== null) {
                    return $name;
                }
            }
        }

        return null;
    }

    public function positionCodeForFolder(string $folderName): ?string
    {
        $normalized = $this->normalizeJobLabel($folderName);
        $needles = [
            'senior corporate communication' => 'NCK/REC6',
            'corporate secretary director legal' => 'NCK/REC2',
            'deputy director human resources' => 'NCK/REC5',
            'deputy director research' => 'NCK/REC4',
            'director registration' => 'NCK/REC1',
            'director corporate services' => 'NCK/REC3',
            'corporate communication officer' => 'NCK/REC7',
            'registration licensing officer' => 'NCK/REC8',
            'education examination' => 'NCK/REC9',
            'standards compliance' => 'NCK/REC10',
            'customer care' => 'NCK/REC11',
            'office administrator' => 'NCK/REC12',
            'office assistant' => 'NCK/REC13',
        ];

        foreach ($needles as $needle => $code) {
            if (str_contains($normalized, $needle)) {
                return $code;
            }
        }

        return null;
    }

    /**
     * @return list<Application>
     */
    private function myJobsApplications(): array
    {
        return Application::query()
            ->myJobs()
            ->with(['applicant:id,full_name,email', 'position:id,reference_code,title'])
            ->get()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function jobFolders(string $root): array
    {
        $dirs = [];
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        return $dirs;
    }

    private function positionIdForFolder(string $jobFolder): ?int
    {
        $code = $this->positionCodeForFolder(basename($jobFolder));
        if (! $code) {
            return null;
        }

        $id = Position::query()->where('reference_code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    /**
     * @param  array<string, mixed>  $counts
     */
    private function extractZips(string $jobFolder, array &$counts, bool $dryRun): void
    {
        foreach (scandir($jobFolder) ?: [] as $entry) {
            if (! str_ends_with(strtolower($entry), '.zip')) {
                continue;
            }
            $portalId = $this->portalIdFromName($entry);
            if (! $portalId) {
                continue;
            }
            $target = $jobFolder.DIRECTORY_SEPARATOR.'applicant-'.$portalId.'-zipped';
            if (is_dir($target) && $this->packFiles($target) !== []) {
                continue;
            }
            $counts['zips_extracted']++;
            if ($dryRun) {
                continue;
            }
            $this->extractZip($jobFolder.DIRECTORY_SEPARATOR.$entry, $target);
        }
    }

    private function extractZip(string $zipPath, string $targetDir): void
    {
        if (! class_exists(ZipArchive::class)) {
            return;
        }

        File::ensureDirectoryExists($targetDir);
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return;
        }

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name) || $name === '' || str_ends_with($name, '/')) {
                    continue;
                }
                if (str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, ':')) {
                    continue;
                }
                if (str_starts_with($name, '__MACOSX') || str_contains($name, '/__MACOSX/')) {
                    continue;
                }

                $basename = basename(str_replace('\\', '/', $name));
                if ($basename === '' || str_starts_with($basename, '.')) {
                    continue;
                }

                $contents = $zip->getFromIndex($i);
                if (! is_string($contents) || $contents === '') {
                    continue;
                }
                file_put_contents($targetDir.DIRECTORY_SEPARATOR.$basename, $contents);
            }
        } finally {
            $zip->close();
        }
    }

    /**
     * @return list<array{portal_id: int, dir: string, zip: ?string}>
     */
    private function applicantPacks(string $jobFolder): array
    {
        $packs = [];
        foreach (scandir($jobFolder) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $portalId = $this->portalIdFromName($entry);
            if (! $portalId) {
                continue;
            }
            $path = $jobFolder.DIRECTORY_SEPARATOR.$entry;
            $packs[$portalId] ??= [
                'portal_id' => $portalId,
                'dir' => $jobFolder.DIRECTORY_SEPARATOR.'applicant-'.$portalId.'-zipped',
                'zip' => null,
            ];
            if (is_file($path) && str_ends_with(strtolower($entry), '.zip') && ! $packs[$portalId]['zip']) {
                $packs[$portalId]['zip'] = $path;
            }
        }

        return array_values($packs);
    }

    private function portalIdFromName(string $name): ?int
    {
        if (! preg_match('/applicant-(\d+)-zipped/i', $name, $m)) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @return list<string>
     */
    private function packFiles(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($path)) {
                continue;
            }
            $lower = strtolower($entry);
            if (str_ends_with($lower, '.zip') || in_array($lower, ['thumbs.db', 'desktop.ini', '.ds_store'], true)) {
                continue;
            }
            $files[] = $path;
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private function zipEntryNames(?string $zipPath): array
    {
        if (! $zipPath || ! is_file($zipPath) || ! class_exists(ZipArchive::class)) {
            return [];
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            return [];
        }

        $names = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (is_string($name) && $name !== '') {
                    $names[] = $name;
                }
            }
        } finally {
            $zip->close();
        }

        return $names;
    }

    /**
     * @param  array<int, list<Application>>  $byPortal
     * @param  array<int, list<Application>>  $byPosition
     * @return list<Application>
     */
    private function matchApplications(
        array $byPortal,
        array $byPosition,
        int $positionId,
        int $portalId,
        ?string $name,
    ): array {
        $portalHits = array_values(array_filter(
            $byPortal[$portalId] ?? [],
            fn (Application $application) => (int) $application->position_id === $positionId
        ));
        if (count($portalHits) === 1) {
            return $portalHits;
        }

        if (! $name) {
            return [];
        }

        $hits = [];
        foreach ($byPosition[$positionId] ?? [] as $application) {
            $fullName = (string) ($application->applicant?->full_name ?? '');
            if ($this->listing->namesMatch($name, $fullName)) {
                $hits[$application->id] = $application;
            }
        }

        return array_values($hits);
    }

    /**
     * @param  list<string>  $files
     */
    private function attachFiles(Application $application, array $files): int
    {
        $root = realpath(Storage::disk('private')->path('')) ?: rtrim(Storage::disk('private')->path(''), '\\/');
        $linked = 0;

        foreach ($files as $absolute) {
            if (! is_file($absolute)) {
                continue;
            }
            $real = realpath($absolute) ?: $absolute;
            $rootPrefix = rtrim(str_replace('\\', '/', $root), '/');
            $realNorm = str_replace('\\', '/', $real);
            if (! str_starts_with($realNorm, $rootPrefix.'/')) {
                continue;
            }
            $relative = ltrim(substr($realNorm, strlen($rootPrefix)), '/');

            ApplicationDocument::query()->updateOrCreate(
                [
                    'application_id' => $application->id,
                    'path' => $relative,
                ],
                [
                    'mail_attachment_id' => null,
                    'document_type' => ApplicationDocument::TYPE_ATTACHMENT,
                    'original_name' => basename($absolute),
                    'disk' => 'private',
                    'mime_type' => File::mimeType($absolute) ?: 'application/octet-stream',
                    'size' => (int) filesize($absolute),
                    'sha256_hash' => hash_file('sha256', $absolute) ?: null,
                ]
            );
            $linked++;
        }

        return $linked;
    }

    private function rememberPortalId(Application $application, int $portalId): void
    {
        $meta = is_array($application->profile_extraction) ? $application->profile_extraction : [];
        $myjobs = is_array($meta['myjobs'] ?? null) ? $meta['myjobs'] : [];
        if ((int) ($myjobs['portal_applicant_id'] ?? 0) === $portalId) {
            return;
        }
        $myjobs['portal_applicant_id'] = $portalId;
        $meta['myjobs'] = $myjobs;
        $application->forceFill(['profile_extraction' => $meta])->save();
    }

    private function slugToName(string $slug): ?string
    {
        $slug = strtolower(str_replace('_', '-', $slug));
        $slug = preg_replace('/[^a-z0-9-]+/', '-', $slug) ?? $slug;
        $parts = array_values(array_filter(explode('-', $slug)));
        $skip = [
            'profile', 'questionnaire', 'cv', 'resume', 'application',
            'chrp', 'cpa', 'cps', 'ics', 'adv', 'advocate', 'hon', 'dr', 'prof', 'eng',
        ];
        $parts = array_values(array_filter($parts, fn (string $part) => ! in_array($part, $skip, true)));
        while ($parts !== [] && preg_match('/^\d+$/', (string) $parts[array_key_last($parts)])) {
            array_pop($parts);
        }
        if (count($parts) < 2) {
            return null;
        }

        return implode(' ', $parts);
    }

    private function normalizeJobLabel(string $name): string
    {
        $label = mb_strtolower($name);
        $label = str_replace(['sectretary', 'communications', 'corporation secretary'], ['secretary', 'communication', 'corporate secretary'], $label);
        $label = str_replace(['&', ',', '.', '/', '-', '_'], ' ', $label);
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;

        return trim($label);
    }
}
