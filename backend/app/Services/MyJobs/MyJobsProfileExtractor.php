<?php

namespace App\Services\MyJobs;

use App\Services\Applications\ApplicationProfileExtractor;
use Carbon\Carbon;
use Throwable;

class MyJobsProfileExtractor
{
    public function __construct(private readonly ApplicationProfileExtractor $extractor)
    {
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function extract(array $row): array
    {
        $education = $this->cleanEducation((string) ($row['education'] ?? ''));
        $gender = $this->clean((string) ($row['gender'] ?? ''));
        $phone = $this->clean((string) ($row['phone_no'] ?? $row['phone'] ?? ''));
        $name = $this->clean((string) ($row['name'] ?? ''));
        $position = $this->clean((string) ($row['position'] ?? ''));
        $company = $this->clean((string) ($row['company'] ?? ''));
        $ageRaw = $this->clean((string) ($row['age'] ?? ''));
        $salaryRaw = $this->clean((string) ($row['salary'] ?? ''));
        $score = $this->clean((string) ($row['score'] ?? $row['score_'] ?? ''));
        $scoresLink = $this->cleanScoresLink((string) ($row['scores_link'] ?? ''));
        $appliedAt = $this->parseDate((string) ($row['application_date'] ?? $row['applied_at'] ?? ''));

        [$expectedSalary, $currentSalary] = $this->parseSalary($salaryRaw);
        $ageYears = $this->parseAge($ageRaw);

        $body = $this->labeledBody([
            'Name' => $name,
            'Gender' => $gender,
            'Age' => $ageRaw,
            'Telephone/Mobile no' => $phone,
            'Highest Qualification' => $education,
            'Education' => $education,
            'Current position' => $position,
            'Company' => $company,
            'Expected salary' => $expectedSalary,
            'Current salary' => $currentSalary,
        ]);

        $extracted = $this->extractor->extract(
            'MyJobs application'.($education !== null ? ': '.$education : ''),
            null,
            $body
        );

        if (empty($extracted['highest_qualification']) && $education !== null) {
            $extracted['highest_qualification'] = $this->inferAcademicLevel($education);
            $extracted['highest_qualification_detail'] = $education;
            $extracted['evidence']['highest_qualification_detail'] = $education;
        } elseif ($education !== null && empty($extracted['highest_qualification_detail'])) {
            $extracted['highest_qualification_detail'] = $education;
        }

        if (empty($extracted['gender']) && $gender !== null) {
            $extracted['gender'] = $this->normalizeGender($gender);
        }

        if (empty($extracted['phone']) && $phone !== null) {
            $extracted['phone'] = $this->extractor->normalizePhoneForDisplay($phone) ?? $phone;
        }

        $extracted['nature_of_application'] = 'one';
        $extracted['nature_of_application_detail'] = 'Submitted via My Jobs In Kenya';
        $extracted['myjobs'] = array_filter([
            'file' => $row['file'] ?? null,
            'current_position' => $position,
            'company' => $company,
            'age' => $ageRaw,
            'age_years' => $ageYears,
            'expected_salary' => $expectedSalary,
            'current_salary' => $currentSalary,
            'score' => $score,
            'scores_link' => $scoresLink,
            'applied_at' => $appliedAt?->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== '');
        $extracted['received_at'] = $appliedAt;
        $extracted['sources'] = ['myjobs_csv'];

        return $extracted;
    }

    /**
     * @param  array<string, string|null>  $fields
     */
    private function labeledBody(array $fields): string
    {
        $lines = [];
        foreach ($fields as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = $label.': '.$value;
        }

        return implode("\n", $lines);
    }

    private function cleanEducation(string $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/\bbacherlor\b/iu', 'Bachelor', $value) ?? $value;
        $value = preg_replace('/\badvanced\s+diploma\b/iu', 'Higher Diploma', $value) ?? $value;
        $value = preg_replace('/\bdegree\s+in\b/iu', 'Bachelor of', $value) ?? $value;
        $value = preg_replace('/\bbachelors?\s+degree\b/iu', 'Bachelor', $value) ?? $value;

        return $value;
    }

    private function inferAcademicLevel(string $education): ?string
    {
        $hay = strtolower($education);
        if (preg_match('/\b(ph\.?\s*d|doctorate|doctoral)\b/iu', $hay)) {
            return 'phd';
        }
        if (preg_match('/\b(master\'?s|masters|m\.?\s*sc|mba|m\.?\s*a\.?|llm|mph|master\s+of)\b/iu', $hay)) {
            return 'masters';
        }
        if (preg_match('/\b(bachelor\'?s|bachelors|bacherlor|b\.?\s*sc|bsc|b\.?\s*a\.?|b\.?\s*com|bcom|llb|undergraduate|bachelor\s+of)\b/iu', $hay)) {
            return 'bachelors';
        }
        if (preg_match('/\b(higher\s+national\s+diploma|higher\s+diploma|h\.?\s*n\.?\s*d\.?)\b/iu', $hay)) {
            return 'higher_diploma';
        }
        if (preg_match('/\b(national\s+diploma|diploma)\b/iu', $hay)) {
            return 'diploma';
        }
        if (preg_match('/\b(k\.?\s*c\.?\s*s\.?\s*e\.?|kenya\s+certificate\s+of\s+secondary|form\s*(?:iv|4))\b/iu', $hay)) {
            return 'kcse';
        }
        if (preg_match('/\b(certificate|craft)\b/iu', $hay)) {
            return 'certificate';
        }

        return null;
    }

    private function normalizeGender(string $value): ?string
    {
        $v = strtolower($value);
        if (in_array($v, ['f', 'female'], true)) {
            return 'Female';
        }
        if (in_array($v, ['m', 'male'], true)) {
            return 'Male';
        }

        return null;
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseSalary(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [null, null];
        }

        $expected = null;
        $current = null;
        if (preg_match('/Exp:\s*(?:KES\s*)?([\d,]+)/iu', $raw, $m)) {
            $expected = $this->money($m[1]);
        }
        if (preg_match('/Curr:\s*(?:KES\s*)?([\d,]+)/iu', $raw, $m)) {
            $current = $this->money($m[1]);
        }

        return [$expected, $current];
    }

    private function money(string $digits): ?string
    {
        $n = (int) str_replace(',', '', $digits);
        if ($n <= 0) {
            return null;
        }

        return 'KES '.number_format($n);
    }

    private function parseAge(?string $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (preg_match('/(\d{2,3})\s*(?:years?)?/iu', $raw, $m)) {
            $age = (int) $m[1];

            return $age >= 16 && $age <= 80 ? $age : null;
        }

        return null;
    }

    private function parseDate(string $raw): ?Carbon
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }

        try {
            return Carbon::parse($raw, \App\Support\NairobiDate::TZ);
        } catch (Throwable) {
            return null;
        }
    }

    private function cleanScoresLink(string $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }

        return preg_replace('/^httpss:/i', 'https:', $value) ?? $value;
    }

    private function clean(string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);
        if ($value === '' || $value === '-' || strcasecmp($value, 'n/a') === 0) {
            return null;
        }

        return $value;
    }
}
