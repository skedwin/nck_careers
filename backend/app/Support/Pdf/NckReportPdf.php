<?php

namespace App\Support\Pdf;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NckReportPdf
{
    public function __construct(
        private readonly string $title,
        private readonly string $subtitle = '',
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $filename, string $view, array $data = []): StreamedResponse
    {
        $html = View::make($view, array_merge($data, [
            'reportTitle' => $this->title,
            'reportSubtitle' => $this->subtitle,
        ]))->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response()->streamDownload(function () use ($dompdf): void {
            echo $dompdf->output();
        }, $this->pdfFilename($filename), [
            'Content-Type' => 'application/pdf',
            'Cache-Control' => 'max-age=0, no-cache, must-revalidate',
        ]);
    }

    private function pdfFilename(string $filename): string
    {
        return (string) preg_replace('/\.pdf$/i', '', $filename).'.pdf';
    }
}
