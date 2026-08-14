<?php

namespace App\Services\Applications;

use Illuminate\Support\Str;

class ApplicationProfileExtractor
{
    /**
     * Extract structured profile fields from email subject + body.
     *
     * @return array{
     *   phone: ?string,
     *   national_id: ?string,
     *   gender: ?string,
     *   county: ?string,
     *   is_pwd: ?bool,
     *   pwd_details: ?string,
     *   nature_of_application: ?string,
     *   nature_of_application_detail: ?string,
     *   highest_qualification: ?string,
     *   highest_qualification_detail: ?string,
     *   management_course: ?string,
     *   leadership_course: ?string,
     *   professional_membership: ?string,
     *   professional_qualifications: ?string,
     *   experience_summary: ?string,
     *   experience_years: ?float,
     *   certifications_skills: ?string,
     *   computer_proficiency: ?string,
     *   evidence: array<string, string>
     * }
     */
    public function extract(?string $subject, ?string $html, ?string $text): array
    {
        $plain = $this->toPlainText($html, $text, $subject);

        $evidence = [];

        $phone = $this->extractPhone($plain, $evidence);
        $nationalId = $this->extractNationalId($plain, $evidence);
        $gender = $this->extractGender($plain, $evidence);
        $county = $this->extractCounty($plain, $evidence);
        [$isPwd, $pwdDetails] = $this->extractPwd($plain, $evidence);
        [$nature, $natureDetail] = $this->extractNature($plain, $evidence);
        [$qualification, $qualificationDetail, $ongoingQualifications] = $this->extractHighestQualification($plain, $evidence);
        $management = $this->normalizeYesNoCourse(
            $this->extractLabeled(
                $plain,
                ['management course', 'management courses', 'management training', 'senior management course', 'smc'],
                $evidence,
                'management_course'
            ),
            $evidence,
            'management_course'
        );
        $leadership = $this->normalizeYesNoCourse(
            $this->extractLabeled(
                $plain,
                ['leadership course', 'leadership courses', 'leadership training', 'strategic leadership', 'sldp'],
                $evidence,
                'leadership_course'
            ),
            $evidence,
            'leadership_course'
        );
        $membership = $this->extractMembership($plain, $evidence);
        $professionalQualifications = $this->extractLabeled(
            $plain,
            [
                'professional qualifications',
                'professional qualification',
                'professional training',
                'professional courses',
            ],
            $evidence,
            'professional_qualifications'
        );
        [$experienceSummary, $experienceYears] = $this->extractExperience($plain, $evidence);
        $skills = $this->extractLabeled(
            $plain,
            [
                'certifications & skills',
                'certifications and skills',
                'certification & skills',
                'certifications',
                'professional certifications',
                'skills',
                'key skills',
            ],
            $evidence,
            'certifications_skills'
        );
        $computer = $this->extractComputerProficiency($plain, $evidence);

        return [
            'phone' => $phone,
            'national_id' => $nationalId,
            'gender' => $gender,
            'county' => $county,
            'is_pwd' => $isPwd,
            'pwd_details' => $pwdDetails,
            'nature_of_application' => $nature,
            'nature_of_application_detail' => $natureDetail,
            'highest_qualification' => $qualification,
            'highest_qualification_detail' => $qualificationDetail,
            'ongoing_qualifications' => $ongoingQualifications,
            'management_course' => $management,
            'leadership_course' => $leadership,
            'professional_membership' => $membership,
            'professional_qualifications' => $professionalQualifications,
            'experience_summary' => $experienceSummary,
            'experience_years' => $experienceYears,
            'certifications_skills' => $skills,
            'computer_proficiency' => $computer,
            'evidence' => $evidence,
        ];
    }

    private function toPlainText(?string $html, ?string $text, ?string $subject): string
    {
        $parts = [];
        if (filled($subject)) {
            $parts[] = (string) $subject;
        }
        if (filled($html)) {
            $normalized = preg_replace('/<\s*br\s*\/?>/iu', "\n", (string) $html) ?? (string) $html;
            $normalized = preg_replace('/<\/\s*p\s*>/iu', "\n", $normalized) ?? $normalized;
            $normalized = preg_replace('/<\/\s*div\s*>/iu', "\n", $normalized) ?? $normalized;
            $normalized = preg_replace('/<\/\s*li\s*>/iu', "\n", $normalized) ?? $normalized;
            $parts[] = html_entity_decode(strip_tags($normalized), ENT_QUOTES | ENT_HTML5);
        }
        if (filled($text)) {
            $parts[] = (string) $text;
        }

        $plain = implode("\n", $parts);
        $plain = str_replace(["\r\n", "\r"], "\n", $plain);
        $plain = preg_replace("/[ \t]+/", ' ', $plain) ?? $plain;
        $plain = preg_replace("/\n{3,}/", "\n\n", $plain) ?? $plain;

        return trim($plain);
    }

    /**
     * @param  array<string, string>  $evidence
     */
    private function extractPhone(string $plain, array &$evidence): ?string
    {
        $labeled = $this->extractLabeled(
            $plain,
            ['telephone', 'telephone no', 'telephone number', 'mobile', 'mobile no', 'mobile number', 'phone', 'phone no', 'tel', 'cell'],
            $evidence,
            'phone'
        );

        if ($labeled) {
            // Prefer mobile patterns inside the labeled value.
            if (preg_match('/(?:\+?\s*254|\b0)\s*[17]\d(?:[\s\-]?\d){7}\b/u', $labeled, $pm)) {
                $normalized = $this->normalizePhone($pm[0]);
                if ($normalized) {
                    $evidence['phone'] = $pm[0];

                    return $normalized;
                }
            }
            // Landline e.g. +254-20-318262 (stop before city/country text)
            if (preg_match('/\+?\s*254[\s\-()]*0?[\s\-()]*[2-6]\d[\s\-()]*\d{5,8}\b/u', $labeled, $pm)
                || preg_match('/\b0[2-6]\d[\s\-]?\d{5,8}\b/u', $labeled, $pm)) {
                $normalized = $this->normalizePhone($pm[0]);
                if ($normalized) {
                    $evidence['phone'] = $pm[0];

                    return $normalized;
                }
            }
            $normalized = $this->normalizePhone($labeled);
            if ($normalized) {
                $evidence['phone'] = $normalized;

                return $normalized;
            }
        }

        // Flexible Kenyan mobiles: +254 722 947 476 / 0722-947-476 / +254722947476
        if (preg_match('/(?:\+?\s*254|\b0)\s*[17]\d(?:[\s\-]?\d){7}\b/u', $plain, $m)) {
            $evidence['phone'] = $m[0];

            return $this->normalizePhone($m[0]);
        }

        if (preg_match('/(?:\+?254|0)\s*[17]\d{2}[\s\-]?\d{3}[\s\-]?\d{3}\b/', $plain, $m)) {
            $evidence['phone'] = $m[0];

            return $this->normalizePhone($m[0]);
        }

        // Kenyan landline without trailing address words
        if (preg_match('/\+?\s*254[\s\-()]*0?[\s\-()]*[2-6]\d[\s\-()]*\d{5,8}(?=\s|$|[,;])/u', $plain, $m)
            || preg_match('/\b0[2-6]\d[\s\-]?\d{5,8}\b/u', $plain, $m)) {
            $evidence['phone'] = $m[0];

            return $this->normalizePhone($m[0]);
        }

        return null;
    }

    /**
     * Return digits-only international form. Never keep city/address text.
     */
    private function normalizePhone(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Cut off at first letter (e.g. "NAIROBI, KENYA" / "Email:")
        if (preg_match('/^([^A-Za-z]*\d[^A-Za-z]*)/u', $raw, $m)) {
            $raw = trim($m[1], " \t\n\r\0\x0B,;|/");
        } else {
            return null;
        }

        // Keep first phone-looking token only
        if (preg_match('/\+?\d[\d\s\-().]{5,}\d/', $raw, $m)) {
            $raw = $m[0];
        }

        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        // OCR letter O as zero in rare cases already stripped by \D

        // Kenyan mobile: 2547XXXXXXXX / 07XXXXXXXX / 7XXXXXXXX
        if (preg_match('/^254([17]\d{8})$/', $digits, $m)) {
            return '+254'.$m[1];
        }
        if (preg_match('/^0([17]\d{8})$/', $digits, $m)) {
            return '+254'.$m[1];
        }
        if (preg_match('/^([17]\d{8})$/', $digits, $m)) {
            return '+254'.$m[1];
        }

        // Kenyan landline: +254-20-318262 → +25420318262
        if (preg_match('/^254([2-6]\d{7,9})$/', $digits, $m)) {
            return '+254'.$m[1];
        }
        if (preg_match('/^0([2-6]\d{6,8})$/', $digits, $m)) {
            return '+254'.$m[1];
        }

        // Other international numbers (clean digits only)
        if (strlen($digits) >= 10 && strlen($digits) <= 15) {
            return '+'.$digits;
        }

        return null;
    }

    /**
     * Phone number only — strip city/address/email text.
     * Prefer canonical +254… form; otherwise keep the leading number token.
     */
    public function normalizePhoneForDisplay(string $raw): ?string
    {
        $normalized = $this->normalizePhone($raw);
        if ($normalized) {
            return $normalized;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // e.g. "072311919 Email: …" / incomplete mobiles → keep digits only
        if (preg_match('/^([^A-Za-z]*\d[^A-Za-z]*)/u', $raw, $m)) {
            $token = trim($m[1], " \t\n\r\0\x0B,;|/");
            if (preg_match('/\+?\d[\d\s\-().]{4,}\d/', $token, $pm)) {
                $token = trim($pm[0]);
                $digits = preg_replace('/\D+/', '', $token) ?? '';

                return strlen($digits) >= 7
                    ? preg_replace('/\s+/', '', $token)
                    : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $evidence
     */
    private function extractNationalId(string $plain, array &$evidence): ?string
    {
        $labeled = $this->extractLabeled(
            $plain,
            ['national id', 'national id no', 'national id number', 'id no', 'id number', 'identity card', 'id/passport', 'passport/id'],
            $evidence,
            'national_id'
        );

        if ($labeled && preg_match('/\b(\d{6,10})\b/', $labeled, $m)) {
            $evidence['national_id'] = $m[1];

            return $m[1];
        }

        if (preg_match('/\b(?:national\s*id|id\s*no\.?|id\s*number)\s*[:\-]?\s*(\d{6,10})\b/iu', $plain, $m)) {
            $evidence['national_id'] = $m[1];

            return $m[1];
        }

        return null;
    }

    /**
     * Normalize management/leadership course answers to Yes / No.
     *
     * @param  array<string, string>  $evidence
     */
    private function normalizeYesNoCourse(?string $labeled, array &$evidence, string $key): ?string
    {
        if ($labeled === null || trim($labeled) === '') {
            return null;
        }

        $hay = strtolower(trim($labeled));
        if (preg_match('/^\s*(no|none|nil|n\/a|not\s+applicable|not\s+done|not\s+undertaken)\s*\.?$/iu', $hay)
            || preg_match('/\b(no|none|not\s+done|not\s+undertaken)\b/iu', $hay)
                && ! preg_match('/\b(yes|completed|undertaken|attended|done)\b/iu', $hay)) {
            $evidence[$key] = 'No';

            return 'No';
        }

        // Any meaningful course name / Yes / SMC / SLDP → Yes
        $evidence[$key] = 'Yes';

        return 'Yes';
    }

    /**
     * Keep the membership text as stated by the applicant (short cleanup only).
     *
     * @param  array<string, string>  $evidence
     */
    private function extractMembership(string $plain, array &$evidence): ?string
    {
        $labeled = $this->extractLabeled(
            $plain,
            [
                'membership to a professional body',
                'membership of a professional body',
                'professional memberships',
                'professional membership',
                'professional affiliations',
                'professional affiliation',
                'professional body',
                'member of',
                'membership',
            ],
            $evidence,
            'professional_membership'
        );

        $scan = $labeled ?: $plain;

        // Prefer known professional bodies when present in CV/certs.
        $bodies = [];
        $map = [
            'IHRM' => '/\b(ihrm|institute\s+of\s+human\s+resource\s+management)\b/iu',
            'NCK' => '/\b(nck|nursing\s+council\s+of\s+kenya)\b/iu',
            'LSK' => '/\b(lsk|law\s+society\s+of\s+kenya)\b/iu',
            'ICPAK' => '/\b(icpak)\b/iu',
            'KNA' => '/\b(kna|kenya\s+nurses\s+association)\b/iu',
            'SHRM' => '/\b(shrm)\b/iu',
            'HRMPEB' => '/\b(hrmpeb)\b/iu',
        ];
        foreach ($map as $code => $pattern) {
            if (preg_match($pattern, $scan)) {
                // Avoid false NCK from job advert boilerplate when only scanning full plain.
                if ($code === 'NCK' && $labeled === null && preg_match('/nursing\s+council\s+of\s+kenya/iu', $plain)
                    && ! preg_match('/\b(member|membership)\b.{0,40}\bnck\b|\bnck\b.{0,40}\b(member|membership)\b/iu', $plain)) {
                    continue;
                }
                $bodies[] = $code;
            }
        }
        if ($bodies !== []) {
            $value = implode(', ', array_values(array_unique($bodies)));
            $evidence['professional_membership'] = $value;

            return $value;
        }

        if ($labeled === null || trim($labeled) === '') {
            return null;
        }

        if (preg_match('/^\s*(no|none|nil|n\/a|not\s+applicable|not\s+a\s+member)\s*\.?$/iu', $labeled)) {
            $evidence['professional_membership'] = 'No';

            return 'No';
        }

        $value = Str::limit($labeled, 200, '');
        $evidence['professional_membership'] = $value;

        return $value;
    }

    /**
     * @param  array<string, string>  $evidence
     */
    private function extractGender(string $plain, array &$evidence): ?string
    {
        $labeled = $this->extractLabeled(
            $plain,
            ['gender', 'sex'],
            $evidence,
            'gender'
        );

        $hay = strtolower($labeled ?: '');
        if ($hay !== '') {
            if (preg_match('/\b(female|f)\b/iu', $hay)) {
                return 'Female';
            }
            if (preg_match('/\b(male|m)\b/iu', $hay)) {
                return 'Male';
            }
        }

        if (preg_match('/\b(?:gender|sex)\s*[:\-]\s*(male|female|m|f)\b/iu', $plain, $m)) {
            $evidence['gender'] = $m[1];

            return strcasecmp($m[1], 'f') === 0 || strcasecmp($m[1], 'female') === 0 ? 'Female' : 'Male';
        }

        // Common CV title lines
        if (preg_match('/\b(?:mrs|miss|ms)\.?\b/iu', $plain)) {
            $evidence['gender'] = 'title';

            return 'Female';
        }
        if (preg_match('/\b(?:mr)\.?\b/iu', $plain) && ! preg_match('/\b(?:mrs|miss|ms)\.?\b/iu', $plain)) {
            // Only trust Mr. near personal details / name lines to reduce false positives.
            if (preg_match('/(?:^|\n)\s*mr\.?\s+[A-Z][a-z]+/mu', $plain)) {
                $evidence['gender'] = 'title';

                return 'Male';
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $evidence
     */
    private function extractCounty(string $plain, array &$evidence): ?string
    {
        $labeled = $this->extractLabeled(
            $plain,
            ['county of origin', 'county of residence', 'home county', 'county'],
            $evidence,
            'county'
        );

        if ($labeled) {
            $normalized = \App\Support\KenyaCounties::normalize($labeled);
            if ($normalized) {
                $evidence['county'] = $normalized;

                return $normalized;
            }
        }

        // Scan full text for a known county name near "county" mentions.
        if (preg_match('/\bcounty(?:\s+of\s+origin|\s+of\s+residence)?\s*[:\-]?\s*([A-Za-z\'\-\s]{3,40})/iu', $plain, $m)) {
            $normalized = \App\Support\KenyaCounties::normalize($m[1]);
            if ($normalized) {
                $evidence['county'] = $normalized;

                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $evidence
     * @return array{0: ?bool, 1: ?string}
     */
    private function extractPwd(string $plain, array &$evidence): array
    {
        $labeled = $this->extractLabeled(
            $plain,
            [
                'person living with disability',
                'people living with disability',
                'persons living with disability',
                'person with disability',
                'persons with disability',
                'living with disability',
                'pwd status',
                'plwd',
                'pwd',
                'disability status',
                'disability',
            ],
            $evidence,
            'pwd_details'
        );

        $isPwd = null;

        if ($labeled !== null) {
            if (preg_match('/\b(no|n|none|nil|not applicable|n\/a|not\s+a)\b/iu', $labeled)
                && ! preg_match('/\b(yes|living with|person with)\b/iu', $labeled)) {
                $isPwd = false;
            } elseif (preg_match('/\b(yes|y|true|living with|person with|pwd|plwd|disability)\b/iu', $labeled)) {
                $isPwd = true;
            }
        }

        if ($isPwd === null && preg_match(
            '/\b(person(?:s)?\s+living\s+with\s+disabilit(?:y|ies)|people\s+living\s+with\s+disabilit(?:y|ies)|person(?:s)?\s+with\s+disabilit(?:y|ies)|living\s+with\s+(?:a\s+)?disabilit(?:y|ies)|\bplwd\b|\bpwd\b)\b/iu',
            $plain,
            $m
        )) {
            // Avoid false positives from instructions like "PWD must be indicated"
            $window = Str::lower(Str::limit($m[0].' '.substr($plain, max(0, (int) strpos(Str::lower($plain), Str::lower($m[0])) - 40), 120), 160, ''));
            if (! preg_match('/\b(must\s+be\s+indicated|attach|certificate\s+required|for\s+pwd)\b/iu', $window)) {
                $isPwd = true;
                $evidence['is_pwd'] = 'inferred';
                $labeled ??= Str::limit($m[0], 200, '');
            }
        }

        if ($isPwd === true) {
            $evidence['is_pwd'] = 'Yes';
        } elseif ($isPwd === false) {
            $evidence['is_pwd'] = 'No';
        }

        return [$isPwd, $labeled ? Str::limit($labeled, 500, '') : null];
    }

    /**
     * @param  array<string, string>  $evidence
     */
    private function extractComputerProficiency(string $plain, array &$evidence): ?string
    {
        $labeled = $this->extractLabeled(
            $plain,
            [
                'proficiency in computer studies',
                'computer proficiency',
                'computer studies',
                'computer skills',
                'ict skills',
                'computer literacy',
                'ms office',
                'microsoft office',
            ],
            $evidence,
            'computer_proficiency'
        );

        if ($labeled) {
            $normalized = $this->normalizeComputerProficiencyYesNo($labeled);
            if ($normalized) {
                $evidence['computer_proficiency'] = $normalized;

                return $normalized;
            }
        }

        if (preg_match('/\b(proficient|competent|literate)\b.{0,40}\b(computer|ms\s*office|ict|excel|word)\b/iu', $plain, $m)
            || preg_match('/\b(computer|ms\s*office|ict)\b.{0,40}\b(proficient|competent|literate|skills?)\b/iu', $plain, $m)) {
            $evidence['computer_proficiency'] = 'Yes';

            return 'Yes';
        }

        return null;
    }

    /**
     * Report/display/backfill: Yes / No only.
     */
    public function normalizeComputerProficiencyForDisplay(?string $raw): ?string
    {
        return $this->normalizeComputerProficiencyYesNo($raw);
    }

    private function normalizeComputerProficiencyYesNo(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $hay = strtolower(trim($raw));
        if ($hay === '' || $hay === '-' || $hay === '—' || $hay === '.') {
            return null;
        }

        if (preg_match('/^\s*(no|none|nil|n\/a|na|not\s+applicable|not\s+proficient)\s*\.?$/iu', $hay)
            || (preg_match('/\b(no|none|nil|not\s+proficient|not\s+computer\s+literate)\b/iu', $hay)
                && ! preg_match('/\b(yes|proficient|literate|competent|skills?|ms\s*office|excel|word|ict)\b/iu', $hay))) {
            return 'No';
        }

        if (preg_match('/^\s*(yes|y)\s*\.?$/iu', $hay)
            || preg_match('/\b(computer|ms\s*office|microsoft\s+office|ict|excel|word|powerpoint|access|outlook|software|programming|coding|spreadsheet|data\s*entry|typing)\b/iu', $hay)
            || preg_match('/\b(proficient|literate|competent|skilled)\b/iu', $hay)) {
            return 'Yes';
        }

        // Labeled free-text that is not clearly computer-related → leave blank
        return null;
    }

    /**
     * @param  array<string, string>  $evidence
     * @return array{0: ?string, 1: ?string}
     */
    private function extractNature(string $plain, array &$evidence): array
    {
        $labeled = $this->extractLabeled(
            $plain,
            [
                'nature of application',
                'nature of the application',
                'applying as',
                'application type',
                'one or in pieces',
                'one / in pieces',
            ],
            $evidence,
            'nature_of_application_detail'
        );

        $hay = strtolower($labeled ?: $plain);
        $nature = null;

        if (preg_match('/\b(in\s*pieces|piece[\s\-]?meal|piecemeal|multiple\s+positions?|more\s+than\s+one|several\s+positions?)\b/iu', $hay)) {
            $nature = 'pieces';
        } elseif (preg_match('/\b(one\s+position|single\s+position|one\s+only|\bas\s+one\b|\bone\b)\b/iu', $hay)
            && preg_match('/nature of application|one or in pieces|applying/iu', $hay)) {
            $nature = 'one';
        } elseif ($labeled && preg_match('/\bone\b/iu', $labeled) && ! preg_match('/pieces|multiple/iu', $labeled)) {
            $nature = 'one';
        }

        if ($nature) {
            $evidence['nature_of_application'] = $nature;
        }

        return [$nature, $labeled ? Str::limit($labeled, 500, '') : null];
    }

    /**
     * @param  array<string, string>  $evidence
     * @return array{0: ?string, 1: ?string, 2: list<string>}
     */
    private function extractHighestQualification(string $plain, array &$evidence): array
    {
        // PDF/DOCX often glues words: BachelorofTourism, DIPLOMAININFORMATION, KCSEElite…
        $plain = $this->normalizeAcademicText($plain);

        $labeled = $this->extractLabeled(
            $plain,
            [
                'highest qualification',
                'highest academic qualification',
                'academic qualification',
                'academic qualifications',
                'qualification',
                'education',
                'educational background',
                'education background',
            ],
            $evidence,
            'highest_qualification_detail'
        );

        $educationBlock = null;
        if (preg_match(
            '/(?:education(?:al)?\s*background|academic\s+qualifications?|education|qualifications?)\s*[:\-]?\s*(?:\n+)?(.+?)(?=\n\s*\n+[A-Z][A-Za-z0-9 &\/()]{2,48}\s*[:\-]|\z)/isu',
            $plain,
            $m
        )) {
            $educationBlock = $m[1];
        }

        $rank = $this->academicLevelRanks();

        $sources = array_values(array_filter([
            ['text' => $labeled, 'allow_certificate' => true, 'allow_kcse' => true],
            ['text' => $educationBlock, 'allow_certificate' => true, 'allow_kcse' => true],
            // Full CV/body: degrees + KCSE; generic "certificate" kept to education/labeled sources.
            ['text' => $plain, 'allow_certificate' => false, 'allow_kcse' => true],
        ], fn (array $s): bool => filled($s['text'])));

        // Degrees marked ongoing (and never completed) must not count as highest.
        $ongoingMap = [];
        $completedMap = [];
        foreach ($sources as $source) {
            $this->collectAcademicLevelStatus(
                (string) $source['text'],
                (bool) $source['allow_certificate'],
                (bool) $source['allow_kcse'],
                $ongoingMap,
                $completedMap
            );
        }
        $ongoingOnly = [];
        foreach ($ongoingMap as $level => $_) {
            if (! isset($completedMap[$level])) {
                $ongoingOnly[$level] = true;
            }
        }
        $ongoingLevels = array_keys($ongoingOnly);

        $best = null;
        $bestRank = 0;
        $detail = $labeled;

        foreach ($sources as $source) {
            [$level, $snippet] = $this->detectAcademicLevel(
                (string) $source['text'],
                (bool) $source['allow_certificate'],
                (bool) $source['allow_kcse'],
                $ongoingOnly
            );
            if ($level === null) {
                continue;
            }
            $levelRank = $rank[$level] ?? 0;
            if ($levelRank > $bestRank) {
                $best = $level;
                $bestRank = $levelRank;
                if ($snippet) {
                    $detail = $snippet;
                }
            }
        }

        // Image-only academic uploads often contribute only a filename hint.
        if ($best === null) {
            [$hintLevel, $hintDetail] = $this->detectAcademicLevelFromFilenameHints($plain);
            if ($hintLevel !== null) {
                $best = $hintLevel;
                $detail = $hintDetail;
            }
        }

        // Kenyan CVs sometimes list "Course: University" without saying Diploma/Degree.
        if ($best === null && filled($educationBlock)) {
            [$softLevel, $softDetail] = $this->detectSoftUniversityProgram((string) $educationBlock);
            if ($softLevel !== null) {
                $best = $softLevel;
                $detail = $softDetail;
            }
        }

        if ($best) {
            $evidence['highest_qualification'] = $best;
        }
        if ($ongoingLevels !== []) {
            $evidence['ongoing_qualifications'] = implode(',', $ongoingLevels);
        }

        return [$best, $detail ? Str::limit($detail, 500, '') : null, $ongoingLevels];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function detectAcademicLevelFromFilenameHints(string $plain): array
    {
        if (! preg_match_all('/ACADEMIC FILENAME HINT:\s*(.+)$/miu', $plain, $matches)) {
            return [null, null];
        }

        $rank = $this->academicLevelRanks();
        $best = null;
        $bestRank = 0;
        $detail = null;

        foreach ($matches[1] as $name) {
            $n = strtolower(trim($name));
            // Strip trailing banner markers from "===== ... =====" chunk headers.
            $n = trim(preg_replace('/=+/', ' ', $n) ?? $n);
            $level = null;
            if (preg_match('/(?<![\w])(phd|doctorate)(?![\w])/iu', $n)) {
                $level = 'phd';
            } elseif (preg_match('/(?<![\w])(masters?|mba|msc)(?![\w])/iu', $n)) {
                $level = 'masters';
            } elseif (preg_match('/(?<![\w])(bachelor|degree|bsc)(?![\w])/iu', $n)) {
                $level = 'bachelors';
            } elseif (preg_match('/higher[\s\-_]*diploma/iu', $n)) {
                $level = 'higher_diploma';
            } elseif (preg_match('/diploma/iu', $n)) {
                $level = 'diploma';
            } elseif (preg_match('/kcse/iu', $n)) {
                $level = 'kcse';
            } elseif (preg_match('/certificate|\bcerts?\b/iu', $n)) {
                $level = 'certificate';
            }

            if ($level === null) {
                continue;
            }
            $levelRank = $rank[$level] ?? 0;
            if ($levelRank > $bestRank) {
                $best = $level;
                $bestRank = $levelRank;
                $detail = 'Filename hint: '.trim($name);
            }
        }

        return [$best, $detail];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function detectSoftUniversityProgram(string $educationBlock): array
    {
        // e.g. "Procurement and supplies management: Zetech University; 2015 – 2017"
        if (preg_match(
            '/^[•\-\*◦\x{2022}]?\s*([A-Za-z][A-Za-z0-9\s&\-\/,]{4,70})\s*[:\-]\s*([A-Za-z][A-Za-z0-9\s&\-\']{2,50}\b(?:University|Polytechnic)\b[^\n]{0,40})/mu',
            $educationBlock,
            $m
        )) {
            $line = trim($m[0]);
            if (preg_match('/\b(?:bachelor|master|phd|doctorate|kcse|secondary)\b/iu', $line)) {
                return [null, null];
            }

            return ['diploma', $line];
        }

        return [null, null];
    }

    /**
     * @param  array<string, true>  $ongoingMap
     * @param  array<string, true>  $completedMap
     */
    private function collectAcademicLevelStatus(
        string $text,
        bool $allowCertificate,
        bool $allowKcse,
        array &$ongoingMap,
        array &$completedMap
    ): void {
        foreach ($this->academicLevelPatterns($allowCertificate, $allowKcse) as $level => $pattern) {
            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $levelKey = $this->normalizeMatchedAcademicLevel(
                    $level,
                    $text,
                    (int) $match[1],
                    (string) $match[0]
                );
                if ($levelKey === null) {
                    continue;
                }
                if ($this->isOngoingDegreeMentionAt($text, (int) $match[1], strlen((string) $match[0]))) {
                    $ongoingMap[$levelKey] = true;
                } else {
                    $completedMap[$levelKey] = true;
                }
            }
        }
    }

    /**
     * Detect the highest completed academic level mentioned in text.
     * Ongoing / in-progress degrees are skipped (use the next lower completed level).
     *
     * @param  array<string, true>  $excludeLevels
     * @return array{0: ?string, 1: ?string} [level, matching snippet]
     */
    private function detectAcademicLevel(
        string $text,
        bool $allowCertificate = true,
        bool $allowKcse = true,
        array $excludeLevels = []
    ): array {
        $rank = $this->academicLevelRanks();

        $best = null;
        $bestRank = 0;
        $bestSnippet = null;

        foreach ($this->academicLevelPatterns($allowCertificate, $allowKcse) as $level => $pattern) {
            if (! preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $matchText = (string) $match[0];
                $offset = (int) $match[1];
                $levelKey = $this->normalizeMatchedAcademicLevel($level, $text, $offset, $matchText);
                if ($levelKey === null || isset($excludeLevels[$levelKey])) {
                    continue;
                }
                if ($this->isOngoingDegreeMentionAt($text, $offset, strlen($matchText))) {
                    continue;
                }

                $levelRank = $rank[$levelKey] ?? 0;
                if ($levelRank > $bestRank) {
                    $best = $levelKey;
                    $bestRank = $levelRank;
                    $start = max(0, $offset - 20);
                    $bestSnippet = trim(preg_replace('/\s+/u', ' ', substr($text, $start, 120)) ?? $matchText);
                }
            }
        }

        return [$best, $bestSnippet];
    }

    /**
     * Insert spaces into common glued education phrases from PDF text extraction.
     */
    private function normalizeAcademicText(string $text): string
    {
        $replacements = [
            '/\bEDUCATIONBACKGROUND\b/iu' => 'EDUCATION BACKGROUND ',
            '/\bEDUCATIONALQUALIFICATIONS?\b/iu' => 'EDUCATIONAL QUALIFICATIONS ',
            '/\bEDUCATIONANDPROFESSIONAL\b/iu' => 'EDUCATION AND PROFESSIONAL ',
            '/\bBachelorof(?=[A-Za-z])/iu' => 'Bachelor of ',
            '/\bBachelorDegree(?:in)?(?=[A-Za-z])/iu' => 'Bachelor Degree in ',
            '/\bBachelorsDegree(?:in)?(?=[A-Za-z])/iu' => 'Bachelors Degree in ',
            '/\bMasterof(?=[A-Za-z])/iu' => 'Master of ',
            '/\bMasterDegree(?:in)?(?=[A-Za-z])/iu' => 'Master Degree in ',
            '/\bDiplomaIn(?=[A-Za-z])/iu' => 'Diploma in ',
            '/\bDiplomaOf(?=[A-Za-z])/iu' => 'Diploma of ',
            '/\bDIPLOMAIN(?=[A-Z])/u' => 'DIPLOMA IN ',
            '/\bCERTIFICATEIN(?=[A-Z])/u' => 'CERTIFICATE IN ',
            '/\bCertificateIn(?=[A-Za-z])/iu' => 'Certificate in ',
            '/\bHigherDiploma(?=[A-Za-z])/iu' => 'Higher Diploma ',
            '/\bHIGHERDIPLOMA(?=[A-Z])/u' => 'HIGHER DIPLOMA ',
            '/\bKenyaCertificateofSecondaryEducation/iu' => 'Kenya Certificate of Secondary Education ',
            '/\bKenyaCertificateOfSecondaryEducation/iu' => 'Kenya Certificate of Secondary Education ',
            '/\bSecondaryEducation(?=[A-Za-z*])/iu' => 'Secondary Education ',
            '/\bMeanGrade(?=[A-Za-z])/iu' => 'Mean Grade ',
            '/\bUpperSecondClass(?=[A-Za-z])/iu' => 'Upper Second Class ',
            '/\bSecondUpperDivision(?=[A-Za-z])/iu' => 'Second Upper Division ',
            '/\b(KCSE)(?=[A-Z*])/u' => '$1 ',
            '/\b(KCPE)(?=[A-Z*])/u' => '$1 ',
        ];

        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        // CamelCase leftovers: TourismManagement → Tourism Management
        $text = preg_replace('/([a-z])([A-Z])/', '$1 $2', $text) ?? $text;

        return $text;
    }

    /**
     * @return array<string, int>
     */
    private function academicLevelRanks(): array
    {
        return [
            'phd' => 7,
            'masters' => 6,
            'bachelors' => 5,
            'higher_diploma' => 4,
            'diploma' => 3,
            'certificate' => 2,
            'kcse' => 1,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function academicLevelPatterns(bool $allowCertificate = true, bool $allowKcse = true): array
    {
        $patterns = [
            'phd' => '/\b(?:ph\.?\s*d\.?|phd|d\.?\s*phil|doctorate|doctoral(?:\s+degree)?)\b/iu',
            // Require academic phrasing — avoid "master index", "Master title style", "to master", "Master Craft", "Mombasa".
            'masters' => '/(?:(?<![A-Za-z])MBA(?![A-Za-z])|(?<![A-Za-z])M\.?\s*Sc\.?(?![A-Za-z])|(?<![A-Za-z])M\.?\s*Phil\.?(?![A-Za-z])|(?<![A-Za-z])M\.?\s*Ed\.?(?![A-Za-z])|(?<![A-Za-z])LLM(?![A-Za-z])|(?<![A-Za-z])MPH(?![A-Za-z])|\bmaster(?:[\'’′]?s|\(s\))?\s+(?:of|in|degree)\b|\bmasters?\s+(?:degree|of|in)\b|\bmaster(?:[\'’′]?s|\(s\))?\s*[\-–—:]\s*[A-Za-z]{3,}|\bmaster(?:[\'’′]?s|\(s\))?\s+(?:business|arts|science|commerce|education|communication|project|public|strategic|corporate)\b|\bmaster\s+of\s+(?:arts|science|business|commerce|education|laws?|public)\b|\bM\.?\s*A\.?\s+(?:in|of)\b)/iu',
            'bachelors' => '/\b(?:bachelor(?:\'?s|\(s\))?|bachelors|b\.?\s*sc\.?|b\.?\s*a\.?|b\.?\s*com\.?|b\.?\s*ed\.?|b\.?\s*tech\.?|ll\.?\s*b\.?|undergraduate\s+degree)\b(?:\s+(?:of|in|degree))?/iu',
            'higher_diploma' => '/\b(?:higher\s+national\s+diploma|higher\s+diploma|h\.?\s*n\.?\s*d\.?)\b(?:\s+(?:in|of))?/iu',
            'diploma' => '/\b(?:national\s+diploma|diploma)\b(?:\s+(?:in|of))?/iu',
            'certificate' => '/\b(?:craft\s+certificate|certificate)\b(?:\s+(?:in|of))?/iu',
            'kcse' => '/\b(?:k\.?\s*c\.?\s*s\.?\s*e\.?|kenya\s+certificate\s+of\s+secondary(?:\s+education)?|form\s*(?:iv|4)|secondary\s+school\s+certificate)\b/iu',
        ];

        if (! $allowCertificate) {
            unset($patterns['certificate']);
        }
        if (! $allowKcse) {
            unset($patterns['kcse']);
        }

        return $patterns;
    }

    /**
     * Map raw pattern hits to canonical levels (e.g. KCSE phrase must not count as Certificate).
     */
    private function normalizeMatchedAcademicLevel(string $level, string $text, int $offset, string $matchText): ?string
    {
        $windowStart = max(0, $offset - 60);
        $window = strtolower(substr($text, $windowStart, strlen($matchText) + 100));

        if ($level === 'certificate') {
            if (preg_match('/\b(?:kcse|kenya\s+certificate\s+of\s+secondary|secondary\s+(?:school\s+)?(?:education|certificate)|form\s*(?:iv|4))\b/iu', $window)) {
                return 'kcse';
            }
        }

        if ($level === 'diploma') {
            $before = strtolower(substr($text, max(0, $offset - 24), min(24, $offset)));
            if (preg_match('/higher(?:\s+national)?\s*$/iu', $before)) {
                return 'higher_diploma';
            }
        }

        // Reject referee / non-academic "master" / degree-of-someone-else mentions.
        if (in_array($level, ['phd', 'masters'], true)) {
            $wideStart = max(0, $offset - 120);
            $wide = strtolower(substr($text, $wideStart, strlen($matchText) + 180));
            $before = strtolower(substr($text, max(0, $offset - 48), min(48, $offset)));
            $after = strtolower(substr($text, $offset + strlen($matchText), 90));

            if (preg_match('/\b(?:referees?|associate\s+professor|professor\b|former\s+head|contact\s*:|tel\s*:|phone\s*:|email\s*:)/iu', $wide)) {
                return null;
            }
            if (preg_match('/\b(?:master\s+title|master\s+text|master\s+index|master\s+roll|master\s+register|master\s+baker|master\s+craft|master\s+planner|station\s+masters?|to\s+master|quick\s+to\s+master|master\s+google|master\s+new)\b/iu', $wide)) {
                return null;
            }

            // Job-title / signature after "Ph.D" / "MSc" (referees, letter sign-offs).
            $titleAfter = (bool) preg_match(
                '/\b(?:lecturer|dean|registrar|chair(?:person)?|secretary|co-?ordinator|coordinator|director|senior\s+lecturer|veterinarian|study\s+co-?ordinator)\b/iu',
                $after
            );
            $titleNearby = (bool) preg_match(
                '/\b(?:lecturer|dean|registrar|chair(?:person)?|secretary\/?chief|co-?ordinator|director)\b/iu',
                $wide
            );
            $awardPhrase = (bool) preg_match(
                '/\b(?:ph\.?\s*d\.?|phd|doctorate|doctoral|master(?:[\'’′]?s)?|mba|m\.?\s*sc\.?)\s+(?:in|of)\b|\b(?:awarded|graduated|completed|obtained|conferred)\b/iu',
                $wide
            );
            if (($titleAfter || $titleNearby) && ! $awardPhrase) {
                return null;
            }

            if ($level === 'phd') {
                // "Assisted two PhD candidates" / work experience, not own award.
                $assistedPhd = (bool) preg_match(
                    '/\b(?:assisted|supervis(?:ed|ing)|mentor(?:ed|ing)?)\b.{0,50}\b(?:ph\.?\s*d\.?|phd)\b/iu',
                    $wide
                );
                $otherPhdCandidates = (bool) preg_match('/\b(?:ph\.?\s*d\.?|phd)\s+candidates?\b/iu', $wide)
                    && ! (bool) preg_match('/\b(?:ph\.?\s*d\.?|phd)\s+candidate\s+in\b/iu', $wide);
                if ($assistedPhd || $otherPhdCandidates) {
                    return null;
                }

                // Require academic award phrasing — bare "Name, Ph.D" signatures are not awards.
                $mention = strtolower(substr($text, $offset, min(70, max(0, strlen($text) - $offset))));
                $hasAcademicPhd = (bool) preg_match(
                    '/\b(?:ph\.?\s*d\.?|phd|d\.?\s*phil|doctorate|doctoral)\b\s*(?:\(|in\s|of\s|candidate\b)|\b(?:doctor\s+of\s+philosophy|doctoral\s+degree)\b|\b(?:awarded|completed|obtained|graduated|conferred).{0,40}\b(?:ph\.?\s*d\.?|phd|doctorate)\b|\b(?:ph\.?\s*d\.?|phd|doctorate)\b.{0,50}\b(?:university|college|thesis|dissertation)\b/iu',
                    $mention.' '.$after.' '.$wide
                );
                $looksLikeSignature = (bool) preg_match('/\b(?:dr|prof|professor|mr|mrs|ms)\b\.?\s+[a-z]/iu', $before)
                    || (bool) preg_match('/,\s*$/u', rtrim($before));
                if (! $hasAcademicPhd || ($looksLikeSignature && ! preg_match('/\b(?:in|of)\b/iu', $mention))) {
                    return null;
                }
            }
        }

        return $level;
    }

    /**
     * Ongoing only when the marker is on the same line as the degree (or a short previous label line).
     * Avoids marking a completed Masters as ongoing because a PhD line above says "ongoing".
     */
    private function isOngoingDegreeMentionAt(string $text, int $offset, int $matchLen): bool
    {
        $lineStart = strrpos(substr($text, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $matchEnd = $offset + $matchLen;

        // Same-line text before this mention only (avoid prior-line "(ongoing)" leaking).
        $before = substr($text, $lineStart, max(0, $offset - $lineStart));
        $before = substr($before, -80);

        // Text after this mention (may wrap one line break: "2023 -\nPresent").
        $after = substr($text, $matchEnd, 160);

        // "Currently pursuing / completing / in-progress Master's..."
        if (preg_match('/\b(?:(?:currently\s+)?(?:pursuing|undertaking|enrolled\s+in|studying|completing)|in[\s\-]?progress)\b/iu', $before)) {
            return true;
        }

        // "MSc ... 2025 – Present (Ongoing)" — marker must belong to this mention,
        // not a later degree further along in the CV text.
        if (preg_match('/\b(?:ongoing|on[\s\-]?going|in[\s\-]?progress|candidate|expected\s+(?:to\s+)?(?:graduate|complete|completion)|expected\s+(?:jan(?:uary)?|feb(?:ruary)?|mar(?:ch)?|apr(?:il)?|may|jun(?:e)?|jul(?:y)?|aug(?:ust)?|sep(?:tember)?|oct(?:ober)?|nov(?:ember)?|dec(?:ember)?|\d{4})|20\d{2}\s*[\-–—to\s]+\s*(?:present|current|date|now)|present\s*\(|to\s+date)\b/iu', $after, $m, PREG_OFFSET_CAPTURE)) {
            $between = substr($after, 0, (int) $m[0][1]);
            if (! $this->lineContainsAcademicDegree($between)) {
                return true;
            }
        }

        // Short previous line label: "Currently pursuing" / "Ongoing:"
        if ($lineStart > 0) {
            $prevEnd = $lineStart - 1;
            $prevStartPos = strrpos(substr($text, 0, $prevEnd), "\n");
            $prevStart = $prevStartPos === false ? 0 : $prevStartPos + 1;
            $prev = trim(substr($text, $prevStart, $prevEnd - $prevStart));
            if (
                $prev !== ''
                && mb_strlen($prev) <= 80
                && preg_match('/\b(?:currently\s+)?(?:pursuing|undertaking|ongoing|on[\s\-]?going|in\s+progress)\b/iu', $prev)
                && ! $this->lineContainsAcademicDegree($prev)
            ) {
                return true;
            }
        }

        return false;
    }

    private function lineContainsAcademicDegree(string $line): bool
    {
        foreach ($this->academicLevelPatterns(true) as $pattern) {
            if (preg_match($pattern, $line)) {
                return true;
            }
        }

        return false;
    }

    private function textIndicatesOngoingDegree(string $text): bool
    {
        if (preg_match('/\b20\d{2}\s*[\-–—to]+\s*(?:present|current|date|now)\b/iu', $text)) {
            return true;
        }

        return (bool) preg_match(
            '/\b(?:ongoing|on[\s\-]?going|in\s+progress|currently\s+(?:pursuing|undertaking|enrolled|studying|doing|taking)|pursuing|awaiting\s+(?:graduation|results|award)|expected\s+(?:to\s+)?(?:graduate|complete|completion)|to\s+be\s+completed|yet\s+to\s+(?:complete|graduate)|incomplete|final\s+year|candidate)\b/iu',
            $text
        );
    }

    private function textIndicatesCompletedDegree(string $text): bool
    {
        return (bool) preg_match(
            '/\b(?:completed|graduated|awarded|conferred|obtained|earned)\b/iu',
            $text
        );
    }

    /**
     * @param  array<string, string>  $evidence
     * @return array{0: ?string, 1: ?float}
     */
    private function extractExperience(string $plain, array &$evidence): array
    {
        $labeled = $this->extractLabeled(
            $plain,
            [
                'experiences (include years)',
                'experience (include years)',
                'years of working experience',
                'years of experience',
                'work experience',
                'relevant experience',
                'total experience',
                'experience',
                'experiences',
            ],
            $evidence,
            'experience_summary'
        );

        $years = $this->detectExperienceYears($labeled ?: '');
        if ($years === null) {
            $years = $this->detectExperienceYears($plain);
        }

        if ($years !== null) {
            $evidence['experience_years'] = (string) $years;
        }

        return [
            $labeled ? Str::limit($labeled, 2000, '') : null,
            $years,
        ];
    }

    /**
     * Pick the strongest years-of-experience figure mentioned (e.g. 15, 14, "six years").
     */
    private function detectExperienceYears(string $text): ?float
    {
        if (trim($text) === '') {
            return null;
        }

        $candidates = [];

        if (preg_match_all('/\b(\d{1,2})(?:\.\d)?\s*\+?\s*(?:years?|yrs?)\b/iu', $text, $matches)) {
            foreach ($matches[1] as $raw) {
                $n = (float) $raw;
                if ($n >= 1 && $n <= 45) {
                    $candidates[] = $n;
                }
            }
        }

        if (preg_match_all('/\b(?:over|more\s+than|above|at\s+least)\s+(\d{1,2})\s*(?:years?|yrs?)\b/iu', $text, $matches)) {
            foreach ($matches[1] as $raw) {
                $n = (float) $raw;
                if ($n >= 1 && $n <= 45) {
                    $candidates[] = $n;
                }
            }
        }

        // "over six years", "fifteen years of experience"
        $words = [
            'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
            'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
            'eleven' => 11, 'twelve' => 12, 'thirteen' => 13, 'fourteen' => 14, 'fifteen' => 15,
            'sixteen' => 16, 'seventeen' => 17, 'eighteen' => 18, 'nineteen' => 19, 'twenty' => 20,
            'twenty[\s\-]?five' => 25, 'thirty' => 30,
        ];
        foreach ($words as $word => $n) {
            if (preg_match('/\b(?:over|more\s+than|above|at\s+least)?\s*'.$word.'\s*(?:\+)?\s*(?:years?|yrs?)\b/iu', $text)) {
                $candidates[] = (float) $n;
            }
        }

        if (preg_match('/\b(?:years?\s+of\s+(?:working\s+)?experience|experience)\s*[:\-]\s*(\d{1,2})\b/iu', $text, $m)) {
            $n = (float) $m[1];
            if ($n >= 1 && $n <= 45) {
                $candidates[] = $n;
            }
        }

        if ($candidates === []) {
            return null;
        }

        return max($candidates);
    }

    /**
     * @param  list<string>  $labels
     * @param  array<string, string>  $evidence
     */
    private function extractLabeled(string $plain, array $labels, array &$evidence, string $evidenceKey): ?string
    {
        foreach ($labels as $label) {
            $quoted = preg_quote($label, '/');
            // Label: value (same line)
            $pattern = '/'.$quoted.'\s*[:\-]\s*(.+)$/imu';
            if (preg_match($pattern, $plain, $m)) {
                $value = $this->cleanValue($m[1]);
                if ($this->isMeaningful($value)) {
                    $evidence[$evidenceKey] = $value;

                    return $value;
                }
            }

            // Label on its own line, value on following lines until blank/next label-ish line.
            $patternBlock = '/'.$quoted.'\s*[:\-]?\s*\n+(.+?)(?=\n\s*\n|\n[A-Z][A-Za-z0-9 &\/()]{2,40}\s*[:\-]|\z)/isu';
            if (preg_match($patternBlock, $plain, $m)) {
                $value = $this->cleanValue($m[1]);
                if ($this->isMeaningful($value)) {
                    $evidence[$evidenceKey] = $value;

                    return $value;
                }
            }
        }

        return null;
    }

    private function cleanValue(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B-:;");
    }

    private function isMeaningful(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $lower = strtolower($value);
        if (in_array($lower, ['n/a', 'na', 'none', 'nil', '-', '--', 'not applicable'], true)) {
            return false;
        }

        return mb_strlen($value) >= 2;
    }
}
