<?php

namespace App\Services\MicrosoftGraph;

use Illuminate\Support\Str;

class CloudLinkExtractor
{
    /**
     * Extract cloud document links from HTML and/or plain text.
     *
     * @return list<array{url: string, provider: string, name: string}>
     */
    public function extract(?string $html, ?string $text = null): array
    {
        $candidates = [];

        if (filled($html)) {
            if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/iu', $html, $matches)) {
                foreach ($matches[1] as $href) {
                    $candidates[] = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
                }
            }
            // Bare URLs inside HTML bodies.
            if (preg_match_all('#https?://[^\s<>"\']+#iu', $html, $matches)) {
                foreach ($matches[0] as $url) {
                    $candidates[] = rtrim($url, '.,);]');
                }
            }
        }

        if (filled($text)) {
            if (preg_match_all('#https?://[^\s<>"\']+#iu', $text, $matches)) {
                foreach ($matches[0] as $url) {
                    $candidates[] = rtrim($url, '.,);]');
                }
            }
        }

        $out = [];
        $seen = [];

        foreach ($candidates as $raw) {
            $url = $this->normalizeUrl($raw);
            if ($url === null) {
                continue;
            }

            $provider = $this->detectProvider($url);
            if ($provider === null) {
                continue;
            }

            $key = strtolower($url);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'url' => $url,
                'provider' => $provider,
                'name' => $this->guessName($url, $provider),
            ];
        }

        return $out;
    }

    public function detectProvider(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return null;
        }

        if (str_ends_with($host, 'sharepoint.com')) {
            return 'sharepoint';
        }

        if (
            $host === '1drv.ms'
            || str_ends_with($host, 'onedrive.live.com')
            || str_ends_with($host, 'sharepoint-df.com')
        ) {
            return 'onedrive';
        }

        if (
            $host === 'drive.google.com'
            || $host === 'docs.google.com'
            || $host === 'drive.usercontent.google.com'
        ) {
            return 'google_drive';
        }

        if (
            str_ends_with($host, 'dropbox.com')
            || str_ends_with($host, 'dropboxusercontent.com')
        ) {
            return 'dropbox';
        }

        return null;
    }

    public function isMicrosoftCloud(?string $provider): bool
    {
        return in_array($provider, ['onedrive', 'sharepoint'], true);
    }

    public function syntheticGraphId(string $url): string
    {
        return 'body-link:'.sha1(strtolower(trim($url)));
    }

    private function normalizeUrl(string $raw): ?string
    {
        $url = trim($raw);
        if ($url === '' || str_starts_with(strtolower($url), 'javascript:')) {
            return null;
        }

        // Outlook safe links sometimes wrap the real URL.
        if (preg_match('#https?://[^\s]+#iu', html_entity_decode($url, ENT_QUOTES | ENT_HTML5), $m)) {
            $url = $m[0];
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        return $url;
    }

    private function guessName(string $url, string $provider): string
    {
        $fallback = match ($provider) {
            'sharepoint' => 'SharePoint document',
            'onedrive' => 'OneDrive document',
            'google_drive' => 'Google Drive document',
            'dropbox' => 'Dropbox document',
            default => 'Cloud document',
        };

        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = basename(rawurldecode($path));
        $generic = ['view', 'edit', 'preview', 'open', 'uc', 'u', 's', 'b', 'f', 'file'];

        if (
            $base !== ''
            && $base !== '/'
            && ! str_contains($base, ':')
            && ! in_array(strtolower($base), $generic, true)
            && ! preg_match('/^[A-Za-z0-9_-]{20,}$/', $base)
        ) {
            $clean = preg_replace('/[^A-Za-z0-9._() -]+/', '_', $base) ?: $base;
            if (strlen($clean) > 3) {
                return Str::limit($clean, 180, '');
            }
        }

        return $fallback;
    }
}
