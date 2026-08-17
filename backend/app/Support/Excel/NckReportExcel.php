<?php

namespace App\Support\Excel;

use App\Support\NairobiDate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NckReportExcel
{
    /** NCK primary — deep plum / almost purple */
    public const PURPLE = '4B1E6D';

    public const PURPLE_DARK = '351456';

    public const PURPLE_LIGHT = 'F3EAF8';

    public const GOLD = 'C9A227';

    public const SLATE = '1F2937';

    public const MIST = 'F7F2FA';

    public const WHITE = 'FFFFFF';

    public const AMBER = 'FEF3C7';

    public const AMBER_TEXT = '92400E';

    public const ROW_WHITE = 'FFFFFF';

    public const ROW_ALT = 'F8F4FB';

    private Spreadsheet $spreadsheet;

    private bool $firstSheet = true;

    public function __construct(
        private readonly string $title,
        private readonly string $subtitle = '',
    ) {
        $this->spreadsheet = new Spreadsheet();
        $this->spreadsheet->getProperties()
            ->setCreator('NCK Careers')
            ->setLastModifiedBy('NCK Careers')
            ->setTitle($title)
            ->setSubject($title)
            ->setCompany('Nursing Council of Kenya')
            ->setDescription('Confidential recruitment report generated from NCK Careers.');
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<int|string, mixed>>  $rows
     * @param  array{highlight?: callable(array<int|string, mixed>, int): ?string}  $options
     */
    public function addSheet(string $name, array $headers, array $rows, array $options = []): self
    {
        if ($this->firstSheet) {
            $sheet = $this->spreadsheet->getActiveSheet();
            $this->firstSheet = false;
        } else {
            $sheet = $this->spreadsheet->createSheet();
        }

        $sheet->setTitle($this->safeSheetName($name));
        $colCount = max(1, count($headers));
        $lastCol = Coordinate::stringFromColumnIndex($colCount);

        $this->writeBanner($sheet, $lastCol);
        $headerRow = 5;
        $this->writeHeaders($sheet, $headers, $headerRow, $lastCol);

        $highlight = $options['highlight'] ?? null;
        $dataStart = $headerRow + 1;
        $rowNumber = $dataStart;

        foreach ($rows as $index => $row) {
            $values = $this->rowValues($headers, $row);
            $tone = is_callable($highlight) ? $highlight($row, $index) : null;
            $fill = $this->fillForTone($tone, $index);

            foreach ($values as $col => $value) {
                $sheet->setCellValue(
                    Coordinate::stringFromColumnIndex($col + 1).$rowNumber,
                    $this->scalar($value)
                );
            }

            $range = "A{$rowNumber}:{$lastCol}{$rowNumber}";
            $sheet->getStyle($range)->applyFromArray([
                'font' => [
                    'name' => 'Calibri',
                    'size' => 10,
                    'color' => ['rgb' => $tone === 'missing' || $tone === 'duplicate' ? self::AMBER_TEXT : self::SLATE],
                ],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => $fill],
                ],
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                    'wrapText' => true,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'D1D5DB'],
                    ],
                ],
            ]);
            $sheet->getRowDimension($rowNumber)->setRowHeight(-1);
            $rowNumber++;
        }

        $lastDataRow = max($headerRow, $rowNumber - 1);
        $sheet->freezePane('A'.$dataStart);
        if ($lastDataRow >= $headerRow) {
            $sheet->setAutoFilter("A{$headerRow}:{$lastCol}{$lastDataRow}");
        }

        $this->autosize($sheet, $colCount, $headers);
        $this->pageSetup($sheet, $lastCol);

        if ($rows === []) {
            $sheet->setCellValue('A6', 'No records for this report.');
            $sheet->mergeCells("A6:{$lastCol}6");
            $sheet->getStyle('A6')->getFont()->setItalic(true)->getColor()->setRGB('6B7280');
        }

        return $this;
    }

    public function download(string $filename): StreamedResponse
    {
        $filename = $this->xlsFilename($filename);

        return response()->streamDownload(function (): void {
            $writer = new Xls($this->spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $this->spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
        ]);
    }

    public function save(string $path): string
    {
        $path = $this->xlsFilename($path);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $writer = new Xls($this->spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save($path);
        $this->spreadsheet->disconnectWorksheets();

        return $path;
    }

    private function writeBanner(Worksheet $sheet, string $lastCol): void
    {
        $sheet->mergeCells("A1:{$lastCol}1");
        $sheet->setCellValue('A1', 'NURSING COUNCIL OF KENYA');
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'bold' => true,
                'size' => 18,
                'color' => ['rgb' => self::WHITE],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::PURPLE],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->mergeCells("A2:{$lastCol}2");
        $sheet->setCellValue('A2', mb_strtoupper($this->title));
        $sheet->getRowDimension(2)->setRowHeight(22);
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'bold' => true,
                'size' => 13,
                'color' => ['rgb' => self::PURPLE_DARK],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::PURPLE_LIGHT],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => self::GOLD],
                ],
            ],
        ]);

        $generated = NairobiDate::format(now()) ?: now()->timezone(NairobiDate::TZ)->format('d M Y H:i');
        $meta = 'Generated '.$generated.' (EAT)';
        if ($this->subtitle !== '') {
            $meta .= '  ·  '.$this->subtitle;
        }
        $meta .= '  ·  Confidential';

        $sheet->mergeCells("A3:{$lastCol}3");
        $sheet->setCellValue('A3', $meta);
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'size' => 9,
                'italic' => true,
                'color' => ['rgb' => '4B5563'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::MIST],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ]);

        $sheet->getRowDimension(4)->setRowHeight(8);
    }

    /**
     * @param  list<string>  $headers
     */
    private function writeHeaders(Worksheet $sheet, array $headers, int $row, string $lastCol): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($index + 1).$row, $header);
        }

        $sheet->getRowDimension($row)->setRowHeight(32);
        $sheet->getStyle("A{$row}:{$lastCol}{$row}")->applyFromArray([
            'font' => [
                'name' => 'Calibri',
                'bold' => true,
                'size' => 10,
                'color' => ['rgb' => self::WHITE],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => self::PURPLE_DARK],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => self::PURPLE],
                ],
            ],
        ]);
    }

    /**
     * @param  list<string>  $headers
     * @param  array<int|string, mixed>  $row
     * @return list<mixed>
     */
    private function rowValues(array $headers, array $row): array
    {
        if ($row !== [] && array_is_list($row)) {
            return array_pad(array_values($row), count($headers), '');
        }

        $values = [];
        foreach ($headers as $header) {
            $values[] = $row[$header] ?? '';
        }

        return $values;
    }

    private function fillForTone(?string $tone, int $index): string
    {
        return match ($tone) {
            'duplicate', 'missing' => self::AMBER,
            'match', 'found' => self::PURPLE_LIGHT,
            default => $index % 2 === 0 ? self::ROW_WHITE : self::ROW_ALT,
        };
    }

    private function autosize(Worksheet $sheet, int $colCount, array $headers): void
    {
        for ($i = 1; $i <= $colCount; $i++) {
            $sheet->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        // Cap very wide remark/qualification columns after autosize approximation.
        foreach ($headers as $index => $header) {
            $width = 14;
            $lower = strtolower($header);
            if (str_contains($lower, 'sn')) {
                $width = 8;
            } elseif (str_contains($lower, 'email')) {
                $width = 28;
            } elseif (str_contains($lower, 'academic') || str_contains($lower, 'comment') || str_contains($lower, 'remark')) {
                $width = 36;
            } elseif (str_contains($lower, 'name') || str_contains($lower, 'position') || str_contains($lower, 'category')) {
                $width = 26;
            } elseif (str_contains($lower, 'identifier') || str_contains($lower, 'reference')) {
                $width = 22;
            }
            $sheet->getColumnDimensionByColumn($index + 1)->setAutoSize(false);
            $sheet->getColumnDimensionByColumn($index + 1)->setWidth($width);
        }
    }

    private function pageSetup(Worksheet $sheet, string $lastCol): void
    {
        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToPage(true)
            ->setFitToWidth(1)
            ->setFitToHeight(0)
            ->setRowsToRepeatAtTopByStartAndEnd(1, 5);
        $sheet->getPageMargins()
            ->setTop(0.4)
            ->setBottom(0.4)
            ->setLeft(0.35)
            ->setRight(0.35);
        $sheet->getHeaderFooter()
            ->setOddHeader('&C&B Nursing Council of Kenya — Careers')
            ->setOddFooter('&LConfidential&CPage &P of &N&R NCK Careers');
        $sheet->getPageSetup()->setPrintArea('A1:'.$lastCol.$sheet->getHighestRow());
        $sheet->getSheetView()->setZoomScale(90);
    }

    private function safeSheetName(string $name): string
    {
        $name = preg_replace('/[\\\\\\/\\*\\?\\:\\[\\]]+/', ' ', $name) ?? $name;
        $name = trim($name);
        if ($name === '') {
            $name = 'Report';
        }

        return mb_substr($name, 0, 31);
    }

    private function xlsFilename(string $filename): string
    {
        return (string) preg_replace('/\\.(csv|xlsx|xls)$/i', '', $filename).'.xls';
    }

    private function scalar(mixed $value): bool|float|int|string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_scalar($value)) {
            return $value;
        }

        return (string) json_encode($value);
    }
}
