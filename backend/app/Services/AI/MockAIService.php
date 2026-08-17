<?php

namespace App\Services\AI;

class MockAIService implements AIServiceInterface
{
    public function providerName(): string
    {
        return 'mock';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extract(array $payload): array
    {
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? $payload['body_text'] ?? ''));
        $text = mb_strtolower($subject.' '.$body);

        $fullName = $this->matchName($subject, $body);
        $email = $this->matchEmail($text) ?? ($payload['sender_email'] ?? null);
        $phone = $this->matchPhone($text);
        $registration = $this->matchRegistration($text);
        $positionHint = $this->matchPositionHint($text);

        $confidence = 0.55;
        if ($fullName) {
            $confidence += 0.1;
        }
        if ($email) {
            $confidence += 0.1;
        }
        if ($registration) {
            $confidence += 0.1;
        }
        if ($positionHint) {
            $confidence += 0.05;
        }

        return [
            'provider' => 'mock',
            'status' => 'completed',
            'confidence' => min(0.95, round($confidence, 4)),
            'applicant' => [
                'full_name' => $fullName,
                'email' => $email ? strtolower((string) $email) : null,
                'phone' => $phone,
                'registration_number' => $registration,
            ],
            'position_hint' => $positionHint,
            'keywords' => $this->extractKeywords($text),
            'summary' => $subject !== ''
                ? 'Mock extraction from subject: '.$subject
                : 'Mock extraction from email body.',
            'raw' => [
                'subject' => $subject,
                'body_excerpt' => mb_substr($body, 0, 500),
            ],
        ];
    }

    private function matchName(string $subject, string $body): ?string
    {
        $pattern = '/\b(?:from|applicant|full\s*name|name)\s*[:\-]\s*([A-Z][a-zA-Z\'\-]+(?:[ \t]+[A-Z][a-zA-Z\'\-]+){1,3})/iu';

        if (preg_match($pattern, $body, $m) || preg_match($pattern, $subject, $m)) {
            $name = trim($m[1]);
            $blocked = ['email', 'phone', 'registration', 'nck', 'application'];
            foreach ($blocked as $word) {
                if (str_contains(mb_strtolower($name), $word)) {
                    return null;
                }
            }

            return $name;
        }

        return null;
    }

    private function matchEmail(string $text): ?string
    {
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)) {
            return $m[0];
        }

        return null;
    }

    private function matchPhone(string $text): ?string
    {
        if (preg_match('/(?:\+?254|0)\s*[17]\d[\d\s\-]{7,12}/', $text, $m)) {
            return preg_replace('/\s+/', '', $m[0]);
        }

        return null;
    }

    private function matchRegistration(string $text): ?string
    {
        if (preg_match('/\b(?:reg(?:istration)?\s*(?:no|number|#)|nck\s*(?:reg(?:istration)?|no|number|#))\s*[:\-]?\s*([A-Z0-9][A-Z0-9\-\/]{3,19})\b/i', $text, $m)) {
            return strtoupper($m[1]);
        }

        return null;
    }

    private function matchPositionHint(string $text): ?string
    {
        $hints = [
            'registered nurse' => 'Registered Nurse',
            'nurse tutor' => 'Nurse Tutor',
            'clinical officer' => 'Clinical Officer',
            'midwife' => 'Midwife',
            'ict officer' => 'ICT Officer',
        ];

        foreach ($hints as $needle => $label) {
            if (str_contains($text, $needle)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function extractKeywords(string $text): array
    {
        $candidates = ['kcse', 'bscn', 'diploma', 'degree', 'nck', 'registration', 'cv', 'certificate', 'experience'];
        $found = [];

        foreach ($candidates as $word) {
            if (str_contains($text, $word)) {
                $found[] = $word;
            }
        }

        return $found;
    }
}
