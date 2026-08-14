<?php

namespace Tests\Unit;

use App\Services\MicrosoftGraph\CloudLinkExtractor;
use PHPUnit\Framework\TestCase;

class CloudLinkExtractorTest extends TestCase
{
    public function test_extracts_sharepoint_and_google_drive_links(): void
    {
        $html = <<<'HTML'
            <p>Docs:
            <a href="https://contoso.sharepoint.com/:b:/s/HR/abc123">SharePoint</a>
            and <a href="https://drive.google.com/file/d/xyz/view">Drive</a>
            plus https://1drv.ms/b/s!AbcDef
            </p>
        HTML;

        $links = (new CloudLinkExtractor)->extract($html);

        $providers = array_column($links, 'provider');
        $this->assertContains('sharepoint', $providers);
        $this->assertContains('google_drive', $providers);
        $this->assertContains('onedrive', $providers);
    }

    public function test_ignores_non_cloud_urls(): void
    {
        $links = (new CloudLinkExtractor)->extract('<a href="https://example.com/file.pdf">x</a>');
        $this->assertSame([], $links);
    }
}
