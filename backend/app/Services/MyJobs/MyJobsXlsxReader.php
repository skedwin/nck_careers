<?php

namespace App\Services\MyJobs;

use ZipArchive;

class MyJobsXlsxReader
{
    /**
     * @return list<array<string, string>>
     */
    public function rows(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException("Unable to open spreadsheet: {$path}");
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $shared = $zip->getFromName('xl/sharedStrings.xml');
        $zip->close();

        if ($sheet === false || $sheet === '') {
            return [];
        }

        $strings = $this->sharedStrings(is_string($shared) ? $shared : null);
        $xml = simplexml_load_string($sheet);
        if ($xml === false) {
            return [];
        }

        $xml->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $sheetRows = $xml->xpath('//a:sheetData/a:row') ?: [];

        $headers = [];
        $out = [];

        foreach ($sheetRows as $sheetRow) {
            $map = [];
            foreach ($sheetRow->c as $cell) {
                $ref = (string) $cell['r'];
                $col = preg_replace('/\d+/', '', $ref) ?? '';
                $map[$col] = $this->cellValue($cell, $strings);
            }

            if ($headers === []) {
                $looksLikeHeader = false;
                foreach ($map as $value) {
                    $h = strtolower($value);
                    if (in_array($h, ['email', 'name', 'phone no', 'application date'], true) || str_contains($h, 'email')) {
                        $looksLikeHeader = true;
                        break;
                    }
                }
                if ($looksLikeHeader) {
                    foreach ($map as $col => $value) {
                        $headers[$col] = $this->headerKey($value);
                    }
                }
                continue;
            }

            $row = [];
            foreach ($headers as $col => $key) {
                if ($key === '') {
                    continue;
                }
                $row[$key] = $map[$col] ?? '';
            }
            if ($this->rowIsEmpty($row)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function sharedStrings(?string $xml): array
    {
        if ($xml === null || $xml === '') {
            return [];
        }

        $sx = simplexml_load_string($xml);
        if ($sx === false) {
            return [];
        }
        $sx->registerXPathNamespace('a', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $items = $sx->xpath('//a:si') ?: [];
        $strings = [];
        foreach ($items as $si) {
            $texts = $si->xpath('.//a:t') ?: [];
            $strings[] = implode('', array_map('strval', $texts));
        }

        return $strings;
    }

    /**
     * @param  list<string>  $strings
     */
    private function cellValue(\SimpleXMLElement $cell, array $strings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            return trim((string) ($strings[(int) $cell->v] ?? ''));
        }
        if ($type === 'inlineStr') {
            $texts = [];
            if (isset($cell->is->t)) {
                $texts[] = (string) $cell->is->t;
            }
            foreach ($cell->is->r ?? [] as $run) {
                $texts[] = (string) $run->t;
            }

            return trim(implode('', $texts));
        }

        return trim((string) $cell->v);
    }

    private function headerKey(string $header): string
    {
        $h = strtolower(trim($header));
        $h = preg_replace('/[^a-z0-9]+/', '_', $h) ?? $h;

        return trim($h, '_');
    }

    /**
     * @param  array<string, string>  $row
     */
    private function rowIsEmpty(array $row): bool
    {
        foreach ($row as $value) {
            if (trim($value) !== '') {
                return false;
            }
        }

        return true;
    }
}
