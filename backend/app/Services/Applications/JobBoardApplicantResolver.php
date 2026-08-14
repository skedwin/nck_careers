<?php

namespace App\Services\Applications;

use App\Models\MailMessage;
use Illuminate\Support\Str;

class JobBoardApplicantResolver
{
    /**
     * Job-board / aggregator senders that are not the real applicant.
     *
     * @var list<string>
     */
    private const BOARD_EMAIL_FRAGMENTS = [
        'careerjet',
        'brightermonday',
        'myjobmag',
        'fuzu.com',
        'linkedin.com',
    ];

    public function isJobBoardMessage(MailMessage $message): bool
    {
        $email = strtolower((string) $message->sender_email);
        $name = strtolower((string) $message->sender_name);

        foreach (self::BOARD_EMAIL_FRAGMENTS as $fragment) {
            if (str_contains($email, $fragment) || str_contains($name, $fragment)) {
                return true;
            }
        }

        $subject = (string) $message->subject;
        if (preg_match('/\bapplied for\b/iu', $subject) && preg_match('/\bvia\s+careerjet\b/iu', $subject.$name.$email)) {
            return true;
        }

        return (bool) preg_match('/\bapplied for\b.+\bat\s+Nursing Council/iu', $subject)
            && str_contains($email, 'noreply');
    }

    public function boardLabel(MailMessage $message): string
    {
        $hay = strtolower(($message->sender_email ?? '').' '.($message->sender_name ?? '').' '.($message->subject ?? ''));

        if (str_contains($hay, 'careerjet')) {
            return 'Careerjet';
        }
        if (str_contains($hay, 'brightermonday')) {
            return 'BrighterMonday';
        }
        if (str_contains($hay, 'myjobmag')) {
            return 'MyJobMag';
        }
        if (str_contains($hay, 'fuzu')) {
            return 'Fuzu';
        }
        if (str_contains($hay, 'linkedin')) {
            return 'LinkedIn';
        }

        return 'Job board';
    }

    /**
     * Prefer the person named in "{Name} applied for …", else a clear placeholder.
     */
    public function resolveDisplayName(MailMessage $message): string
    {
        $fromSubject = $this->nameFromSubject((string) $message->subject);
        if ($fromSubject !== null) {
            return $fromSubject;
        }

        return 'Unnamed applicant (via '.$this->boardLabel($message).')';
    }

    public function nameFromSubject(?string $subject): ?string
    {
        $subject = trim((string) $subject);
        if ($subject === '') {
            return null;
        }

        // "Eunice Njoroge applied for Office Assistant Job NCK at Nursing Council..."
        if (preg_match('/^\s*(.+?)\s+applied\s+for\b/iu', $subject, $m)) {
            $name = $this->cleanPersonName($m[1]);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    private function cleanPersonName(string $raw): ?string
    {
        $name = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5));
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $name = trim($name, " \t\n\r\0\x0B\"'");

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            return null;
        }

        $lower = strtolower($name);
        if (in_array($lower, ['careerjet', 'noreply', 'no-reply', 'unknown', 'applicant'], true)) {
            return null;
        }

        // Reject subjects that look like job titles rather than people.
        if (preg_match('/\b(deputy|director|officer|assistant|manager|job|position|vacancy)\b/iu', $name)
            && ! preg_match('/\s/', $name)) {
            return null;
        }

        return Str::title($name);
    }
}
