<?php

namespace App\Services\Applications;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Smalot\PdfParser\Config as PdfConfig;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;
use ZipArchive;

class DocumentTextExtractor
{
    private const MAX_CHARS = 200000;

    /** Large combined application packs are common; page-cap still protects memory. */
    private const MAX_FILE_BYTES = 25_000_000;

    /** Read more pages so education / KCSE / diploma cert pages are not missed. */
    private const MAX_PDF_PAGES = 24;

    private const MAX_PDF_PAGES_DEEP = 40;

    private const MAX_FILES = 12;

    private const MAX_FILES_DEEP = 20;

    /**
     * @return array{text: string, sources: list<array{name: string, path: string, chars: int, type: string}>, skipped: list<array{name: string, path: string, reason: string}>}
     */
    public function extractFromApplication(ApplicationDocumentPaths $bundle, bool $deep = false): array
    {
        $chunks = [];
        $sources = [];
        $skipped = [];

        $files = $this->prioritizeFiles($bundle->files);
        $cvCoverSeen = 0;
        $academicSeen = 0;
        $maxFiles = $deep ? self::MAX_FILES_DEEP : self::MAX_FILES;
        $maxPages = $deep ? self::MAX_PDF_PAGES_DEEP : self::MAX_PDF_PAGES;

        foreach ($files as $file) {
            if (count($sources) >= $maxFiles) {
                break;
            }

            $priority = $this->filePriority($file['name']);

            // After CV/cover + academic docs are in, skip bulky unrelated packs.
            if (! $deep && $priority >= 5 && ($cvCoverSeen >= 1 || $academicSeen >= 1) && count($sources) >= 4) {
                $skipped[] = [
                    'name' => $file['name'],
                    'path' => $file['relative_path'],
                    'reason' => 'low_priority_after_academic',
                ];
                continue;
            }

            $result = $this->extractFromPathDetailed(
                $file['absolute_path'],
                $file['name'],
                $file['mime'] ?? null,
                $maxPages
            );

            if (($result['skip_reason'] ?? null) !== null) {
                $skipped[] = [
                    'name' => $file['name'],
                    'path' => $file['relative_path'],
                    'reason' => $result['skip_reason'],
                ];
                continue;
            }

            $text = $result['text'] ?? null;
            if ($text === null || trim($text) === '') {
                $skipped[] = [
                    'name' => $file['name'],
                    'path' => $file['relative_path'],
                    'reason' => 'empty_text',
                ];
                // Keep filename hints for image-only academic scans (e.g. "Jane diploma_merged.pdf").
                if ($priority <= 3 && preg_match('/\b(kcse|diploma|degree|bachelor|masters?|phd|certificate|cert|transcript)\b/iu', $file['name'])) {
                    $chunks[] = "===== ACADEMIC FILENAME HINT: {$file['name']} =====\n";
                    $sources[] = [
                        'name' => $file['name'],
                        'path' => $file['relative_path'],
                        'chars' => 0,
                        'type' => $file['type'],
                        'priority' => $priority,
                        'hint_only' => true,
                    ];
                    if ($priority === 3) {
                        $academicSeen++;
                    }
                }
                continue;
            }

            $trimmed = Str::limit(trim($text), self::MAX_CHARS, '');
            $label = match (true) {
                $priority <= 2 => 'CV/RESUME',
                $priority === 3 => 'ACADEMIC',
                default => 'DOCUMENT',
            };
            $chunks[] = "===== {$label}: {$file['name']} =====\n".$trimmed;
            $sources[] = [
                'name' => $file['name'],
                'path' => $file['relative_path'],
                'chars' => mb_strlen($trimmed),
                'type' => $file['type'],
                'priority' => $priority,
            ];

            if ($priority <= 2) {
                $cvCoverSeen++;
            }
            if ($priority === 3) {
                $academicSeen++;
            }

            unset($text, $trimmed, $result);
            gc_collect_cycles();

            $totalChars = mb_strlen(implode("\n", $chunks));
            // Prefer finishing CV + at least one academic doc before stopping.
            $softLimit = $deep ? 200000 : 120000;
            $hardLimit = $deep ? 280000 : 180000;
            if ($totalChars >= $softLimit && $cvCoverSeen >= 1 && $academicSeen >= 1) {
                break;
            }
            if ($totalChars >= $hardLimit) {
                break;
            }
        }

        return [
            'text' => implode("\n\n", $chunks),
            'sources' => $sources,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  list<array{name: string, relative_path: string, absolute_path: string, mime: ?string, type: string}>  $files
     * @return list<array{name: string, relative_path: string, absolute_path: string, mime: ?string, type: string}>
     */
    private function prioritizeFiles(array $files): array
    {
        usort($files, function (array $a, array $b): int {
            return $this->filePriority($a['name']) <=> $this->filePriority($b['name']);
        });

        return $files;
    }

    private function filePriority(string $name): int
    {
        $n = strtolower($name);

        if (preg_match('/\b(cv|resume|curriculum|biodata|bio[\s\-]?data)\b/iu', $n)) {
            return 1;
        }
        if (preg_match('/\b(cover[\s\-]?letter|application[\s\-]?letter)\b/iu', $n)) {
            return 2;
        }
        if (preg_match('/\b(application|applicant)\b/iu', $n) && ! preg_match('/\b(certificate|cert|licence|license)\b/iu', $n)) {
            return 2;
        }
        // Academic evidence for highest qualification (KCSE, diplomas, transcripts, degree certs)
        if (preg_match('/\b(kcse|k\.?c\.?s\.?e\.?|transcript|result[\s\-]?slip|academic|education|qualification|diploma|degree|bachelor|masters?|phd|form[\s\-]?4|secondary)\b/iu', $n)) {
            return 3;
        }
        if (preg_match('/\b(certificate|cert)\b/iu', $n) && ! preg_match('/\b(membership|practi[cs]ing|licence|license|good\s+standing)\b/iu', $n)) {
            return 3;
        }
        if (preg_match('/\b(membership|licence|license|practi[cs]ing|good\s+standing)\b/iu', $n)) {
            return 5;
        }

        return 4;
    }

    public function extractFromPath(string $absolutePath, ?string $name = null, ?string $mime = null): ?string
    {
        return $this->extractFromPathDetailed($absolutePath, $name, $mime)['text'] ?? null;
    }

    /**
     * @return array{text: ?string, skip_reason: ?string}
     */
    public function extractFromPathDetailed(
        string $absolutePath,
        ?string $name = null,
        ?string $mime = null,
        ?int $maxPdfPages = null
    ): array {
        $maxPdfPages ??= self::MAX_PDF_PAGES;
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            return ['text' => null, 'skip_reason' => 'missing'];
        }

        $name = $name ?: basename($absolutePath);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = strtolower((string) $mime);
        $size = (int) filesize($absolutePath);

        if ($size > self::MAX_FILE_BYTES) {
            Log::warning('document_text.skipped_large_file', [
                'name' => $name,
                'bytes' => $size,
            ]);

            return ['text' => null, 'skip_reason' => 'too_large'];
        }

        try {
            if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
                return ['text' => $this->extractPdf($absolutePath, $maxPdfPages), 'skip_reason' => null];
            }

            if (in_array($ext, ['docx'], true) || str_contains($mime, 'wordprocessingml')) {
                return ['text' => $this->extractDocx($absolutePath), 'skip_reason' => null];
            }

            if (in_array($ext, ['doc'], true) || $mime === 'application/msword') {
                return ['text' => $this->extractDocLegacy($absolutePath), 'skip_reason' => null];
            }

            if (in_array($ext, ['txt', 'csv', 'md', 'log', 'rtf'], true) || str_starts_with($mime, 'text/')) {
                $raw = file_get_contents($absolutePath);

                return ['text' => is_string($raw) ? $raw : null, 'skip_reason' => null];
            }

            if ($ext === '' || $mime === 'application/octet-stream') {
                $head = file_get_contents($absolutePath, false, null, 0, 5);
                if ($head === '%PDF-') {
                    return ['text' => $this->extractPdf($absolutePath, $maxPdfPages), 'skip_reason' => null];
                }
            }
        } catch (Throwable $e) {
            Log::warning('document_text.extract_failed', [
                'path' => $absolutePath,
                'name' => $name,
                'error' => $e->getMessage(),
            ]);

            return ['text' => null, 'skip_reason' => 'parse_error'];
        }

        return ['text' => null, 'skip_reason' => 'unsupported'];
    }

    private function extractPdf(string $absolutePath, ?int $maxPdfPages = null): ?string
    {
        $config = new PdfConfig;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(48 * 1024 * 1024);

        $parser = new PdfParser([], $config);
        $pdf = $parser->parseFile($absolutePath);

        $pages = $pdf->getPages();
        $parts = [];
        $maxPages = min($maxPdfPages ?? self::MAX_PDF_PAGES, count($pages));
        for ($i = 0; $i < $maxPages; $i++) {
            try {
                $parts[] = $pages[$i]->getText();
            } catch (Throwable) {
                continue;
            }
            if (mb_strlen(implode("\n", $parts)) >= self::MAX_CHARS) {
                break;
            }
        }

        $text = trim(implode("\n", $parts));

        // Fallback: whole-document text when page iteration yields nothing (some PDFs).
        if ($text === '') {
            try {
                $text = trim((string) $pdf->getText());
                $text = Str::limit($text, self::MAX_CHARS, '');
            } catch (Throwable) {
                $text = '';
            }
        }

        unset($pages, $pdf, $parser, $parts);
        gc_collect_cycles();

        return $text !== '' ? $text : null;
    }

    private function extractDocx(string $absolutePath): ?string
    {
        if (! class_exists(ZipArchive::class)) {
            return null;
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return null;
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! is_string($xml) || $xml === '') {
            return null;
        }

        $xml = str_replace(['</w:p>', '</w:tr>', '<w:br/>', '<w:tab/>'], ["\n", "\n", "\n", "\t"], $xml);
        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);

        return preg_replace("/[ \t]+/", ' ', $text) ?: $text;
    }

    /**
     * Best-effort for legacy .doc — extract readable ASCII/UTF-8 runs.
     */
    private function extractDocLegacy(string $absolutePath): ?string
    {
        $raw = @file_get_contents($absolutePath);
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        // Prefer UTF-16LE text streams common in Word OLE docs.
        if (preg_match_all('/(?:[\x20-\x7E\r\n]{4,}|(?:[\x20-\x7E]\x00){4,})/u', $raw, $matches)) {
            $chunks = [];
            foreach ($matches[0] as $chunk) {
                if (str_contains($chunk, "\x00")) {
                    $decoded = @mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE');
                    if (is_string($decoded) && strlen($decoded) >= 4) {
                        $chunks[] = $decoded;
                    }
                } else {
                    $chunks[] = $chunk;
                }
            }
            $text = trim(implode("\n", $chunks));
            $text = preg_replace("/[^\P{C}\n\t]+/u", ' ', $text) ?? $text;
            $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

            return strlen($text) >= 40 ? Str::limit($text, self::MAX_CHARS, '') : null;
        }

        return null;
    }
}
