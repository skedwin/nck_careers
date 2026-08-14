<?php

namespace Tests\Unit;

use App\Models\MailMessage;
use App\Services\Applications\JobBoardApplicantResolver;
use PHPUnit\Framework\TestCase;

class JobBoardApplicantResolverTest extends TestCase
{
    public function test_extracts_name_from_careerjet_subject(): void
    {
        $resolver = new JobBoardApplicantResolver;
        $message = new MailMessage([
            'sender_email' => 'noreply@careerjet.co.ke',
            'sender_name' => 'Careerjet',
            'subject' => 'Eunice Njoroge applied for Office Assistant Job NCK at Nursing Council of Kenya',
        ]);

        $this->assertTrue($resolver->isJobBoardMessage($message));
        $this->assertSame('Eunice Njoroge', $resolver->resolveDisplayName($message));
    }

    public function test_placeholder_when_name_missing(): void
    {
        $resolver = new JobBoardApplicantResolver;
        $message = new MailMessage([
            'sender_email' => 'noreply@careerjet.co.ke',
            'sender_name' => 'Careerjet',
            'subject' => 'New application notification',
        ]);

        $this->assertSame('Unnamed applicant (via Careerjet)', $resolver->resolveDisplayName($message));
    }
}
