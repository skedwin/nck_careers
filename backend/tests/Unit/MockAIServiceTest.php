<?php

namespace Tests\Unit;

use App\Services\AI\MockAIService;
use Tests\TestCase;

class MockAIServiceTest extends TestCase
{
    public function test_extracts_facts_present_in_the_email_and_does_not_invent_a_decision(): void
    {
        $result = (new MockAIService)->extract([
            'subject' => 'Application for Registered Nurse',
            'body' => "Name: Mary Wanjiku\nEmail: mary.wanjiku@example.com\nPhone: 0722123456\nRegistration number: NCK/9988\nI attached my diploma.",
            'sender_email' => 'relay@example.com',
        ]);

        $this->assertSame('mock', $result['provider']);
        $this->assertSame('Mary Wanjiku', $result['applicant']['full_name']);
        $this->assertSame('mary.wanjiku@example.com', $result['applicant']['email']);
        $this->assertSame('0722123456', $result['applicant']['phone']);
        $this->assertSame('NCK/9988', $result['applicant']['registration_number']);
        $this->assertSame('Registered Nurse', $result['position_hint']);
        $this->assertContains('diploma', $result['keywords']);
        $this->assertArrayNotHasKey('decision', $result);
        $this->assertArrayNotHasKey('eligible', $result);
    }
}
