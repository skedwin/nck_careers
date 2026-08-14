<?php

namespace App\Services\Applications;

use App\Models\Position;
use Illuminate\Support\Collection;

/**
 * Resolves open vacancies from free-text email subjects against NCK/REC1–13 only.
 */
class PositionMatcher
{
    /** @var array<string, string> */
    private const SYNONYMS = [
        'LICENCE' => 'LICENSING',
        'LICENSE' => 'LICENSING',
        'LICENSED' => 'LICENSING',
        'ASSISTANCE' => 'ASSISTANT',
        'ASSITANCE' => 'ASSISTANT',
        'ASSITANT' => 'ASSISTANT',
        'HR' => 'HUMAN RESOURCES',
        'HRA' => 'HUMAN RESOURCES ADMINISTRATION',
        'COMMS' => 'COMMUNICATION',
        'COMMUNICATIONS' => 'COMMUNICATION',
        'CORPORATE COMMUNICATIONS' => 'CORPORATE COMMUNICATION',
        'ADMINISTATOR' => 'ADMINISTRATOR',
        'ADMIMISTRATOR' => 'ADMINISTRATOR',
        'ADMINSTRATOR' => 'ADMINISTRATOR',
    ];

    /**
     * Extra subject phrases → reference_code (matched longest-first after normalisation).
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'DEPUTY DIRECTOR HUMAN RESOURCES AND ADMINISTRATION' => 'NCK/REC5',
        'DEPUTY DIRECTOR HUMAN RESOURCES ADMINISTRATION' => 'NCK/REC5',
        'DEPUTY DIRECTOR HR AND ADMINISTRATION' => 'NCK/REC5',
        'REGISTRATION AND LICENSING OFFICER' => 'NCK/REC8',
        'REGISTRATION AND LICENSE OFFICER' => 'NCK/REC8',
        'REGISTRATION AND LICENCE OFFICER' => 'NCK/REC8',
        'REGISTRATION LICENSING OFFICER' => 'NCK/REC8',
        'LICENSING OFFICER' => 'NCK/REC8',
        'LICENSE OFFICER' => 'NCK/REC8',
        'LICENCE OFFICER' => 'NCK/REC8',
        'CUSTOMER CARE ASSISTANT' => 'NCK/REC11',
        'SENIOR CUSTOMER CARE ASSISTANT' => 'NCK/REC11',
        'CUSTOMER CARE ASSISTANT SENIOR' => 'NCK/REC11',
        'CUSTOMER CARE ASSISTANCE' => 'NCK/REC11',
        'CUSTOMER CARE SERVICE' => 'NCK/REC11',
        'CUSTOMER CARE SERVICES' => 'NCK/REC11',
        'CUSTOMER SERVICE POSITION' => 'NCK/REC11',
        'CUSTOMER SERVICE AGENT' => 'NCK/REC11',
        'CUSTOMER CARE REPRESENTATIVE' => 'NCK/REC11',
        'CUSTOMER SERVICE' => 'NCK/REC11',
        'DIRECTOR REGISTRATION AND LICENSING' => 'NCK/REC1',
        'CORPORATE SECRETARY AND DIRECTOR LEGAL SERVICES' => 'NCK/REC2',
        'DIRECTOR LEGAL SERVICES' => 'NCK/REC2',
        'DIRECTOR CORPORATE SERVICES' => 'NCK/REC3',
        'DEPUTY DIRECTOR RESEARCH STRATEGY PLANNING AND PERFORMANCE MANAGEMENT' => 'NCK/REC4',
        'SENIOR CORPORATE COMMUNICATION OFFICER' => 'NCK/REC6',
        'CORPORATE COMMUNICATION OFFICER' => 'NCK/REC7',
        'EDUCATION AND EXAMINATION OFFICER' => 'NCK/REC9',
        'STANDARDS AND COMPLIANCE OFFICER' => 'NCK/REC10',
        'OFFICE ADMINISTRATOR' => 'NCK/REC12',
        'ADMINISTRATOR' => 'NCK/REC12',
        'OFFICE ASSISTANT' => 'NCK/REC13',
    ];

    public function resolveId(?string $subject): ?int
    {
        $match = $this->match($subject);

        return $match['position_id'] ?? null;
    }

    /**
     * @return array{position_id: int, reference_code: string, title: string, method: string, score: float}|null
     */
    public function match(?string $subject): ?array
    {
        if ($subject === null || trim($subject) === '') {
            return null;
        }

        $haystack = $this->normalize($subject);
        if ($haystack === '') {
            return null;
        }

        $positions = $this->eligiblePositions();
        if ($positions->isEmpty()) {
            return null;
        }

        if ($byCode = $this->matchReferenceCode($haystack, $positions)) {
            return $byCode;
        }

        if ($byAlias = $this->matchAlias($haystack, $positions)) {
            return $byAlias;
        }

        return $this->matchByTitleScore($haystack, $positions);
    }

    /**
     * Only the official NCK/REC1–13 vacancies.
     *
     * @return Collection<int, Position>
     */
    public function eligiblePositions(): Collection
    {
        return Position::query()
            ->where('status', 'open')
            ->where('reference_code', 'like', 'NCK/REC%')
            ->orderBy('sort_order')
            ->get(['id', 'reference_code', 'title']);
    }

    /**
     * @param  Collection<int, Position>  $positions
     * @return array{position_id: int, reference_code: string, title: string, method: string, score: float}|null
     */
    private function matchReferenceCode(string $haystack, Collection $positions): ?array
    {
        if (preg_match('/\bNCK\s*[\/\-]?\s*REC\s*0*(\d{1,2})\b/', $haystack, $m)
            || preg_match('/\bREC\s*0*(\d{1,2})\b/', $haystack, $m)
        ) {
            $num = (int) $m[1];
            if ($num < 1 || $num > 13) {
                return null;
            }
            $code = 'NCK/REC'.$num;
            $position = $positions->firstWhere('reference_code', $code);
            if ($position) {
                return $this->result($position, 'reference_code', 100.0);
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Position>  $positions
     * @return array{position_id: int, reference_code: string, title: string, method: string, score: float}|null
     */
    private function matchAlias(string $haystack, Collection $positions): ?array
    {
        $aliases = self::ALIASES;
        uksort($aliases, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($aliases as $phrase => $code) {
            $needle = $this->normalize($phrase);
            if ($needle !== '' && str_contains($haystack, $needle)) {
                $position = $positions->firstWhere('reference_code', $code);
                if ($position) {
                    return $this->result($position, 'alias', 90.0 + (strlen($needle) / 100));
                }
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Position>  $positions
     * @return array{position_id: int, reference_code: string, title: string, method: string, score: float}|null
     */
    private function matchByTitleScore(string $haystack, Collection $positions): ?array
    {
        $best = null;
        $bestScore = 0.0;

        foreach ($positions as $position) {
            $title = $this->normalize((string) $position->title);
            if ($title === '') {
                continue;
            }

            $score = 0.0;

            if (str_contains($haystack, $title)) {
                $score = 80.0 + min(15.0, strlen($title) / 10);
            } else {
                $titleTokens = $this->significantTokens($title);
                if ($titleTokens === []) {
                    continue;
                }

                $hayTokens = array_flip($this->significantTokens($haystack));
                $hits = 0;
                foreach ($titleTokens as $token) {
                    if (isset($hayTokens[$token])) {
                        $hits++;
                    }
                }

                $coverage = $hits / count($titleTokens);
                if ($coverage < 0.7) {
                    continue;
                }

                if ($hits < 2 && count($titleTokens) > 2) {
                    continue;
                }

                $score = 50.0 + ($coverage * 25.0) + min(10.0, strlen($title) / 12);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $this->result($position, 'title_score', $score);
            }
        }

        return $bestScore >= 50.0 ? $best : null;
    }

    public function normalize(string $value): string
    {
        $text = strtoupper($value);
        $text = str_replace(['&', '/', '-', '_', ',', '.', ':', ';', '|', '(', ')', '[', ']', '"', "'"], ' ', $text);

        $text = preg_replace(
            '/\b(APPLICATION|APPLING|APPLYING|APPLIED|JOB\s+APPLICATION|REF|RE|FW|FWD)\b/u',
            ' ',
            $text
        ) ?? $text;
        $text = preg_replace(
            '/\b(FOR\s+THE\s+POSITION\s+OF|FOR\s+THE\s+POST\s+OF|FOR\s+THE\s+ROLE\s+OF|FOR\s+THE|FOR\s+POSITION\s+OF|FOR\s+POST\s+OF|POSITION\s+OF|ROLE\s+OF|POST\s+OF|VACANCY|POSITION|ROLE|POST)\b/u',
            ' ',
            $text
        ) ?? $text;

        $text = preg_replace('/\s+/', ' ', trim($text)) ?? '';

        foreach (self::SYNONYMS as $from => $to) {
            $text = str_replace($from, $to, $text);
        }

        return preg_replace('/\s+/', ' ', trim($text)) ?? '';
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $normalized): array
    {
        $stop = [
            'AND', 'OR', 'THE', 'OF', 'A', 'AN', 'TO', 'IN', 'FOR', 'WITH', 'AT', 'ON',
            'APPLICATION', 'APPLICANT', 'JOB', 'VACANCY', 'NCK',
        ];

        $tokens = preg_split('/\s+/', $normalized) ?: [];
        $out = [];
        foreach ($tokens as $token) {
            if ($token === '' || in_array($token, $stop, true) || strlen($token) < 3) {
                continue;
            }
            $out[] = $token;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{position_id: int, reference_code: string, title: string, method: string, score: float}
     */
    private function result(Position $position, string $method, float $score): array
    {
        return [
            'position_id' => (int) $position->id,
            'reference_code' => (string) $position->reference_code,
            'title' => (string) $position->title,
            'method' => $method,
            'score' => round($score, 2),
        ];
    }
}
