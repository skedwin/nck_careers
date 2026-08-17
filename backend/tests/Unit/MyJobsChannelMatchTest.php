<?php

namespace Tests\Unit;

use App\Services\MyJobs\MyJobsListingService;
use App\Services\MyJobs\MyJobsXlsxReader;
use Mockery;
use Tests\TestCase;

class MyJobsChannelMatchTest extends TestCase
{
    public function test_same_position_mailbox_application_counts_as_both_channels(): void
    {
        $listing = new MyJobsListingService(Mockery::mock(MyJobsXlsxReader::class));

        $channel = $listing->channelFromMatches([
            [
                'applicant_id' => 1,
                'applicant_name' => 'Jane Doe',
                'applicant_email' => 'jane@example.com',
                'matched_on' => 'email',
                'applications' => [
                    [
                        'application_id' => 10,
                        'application_reference' => 'NCK-MJ-2026-1',
                        'position_id' => 2,
                        'source' => 'myjobs',
                    ],
                    [
                        'application_id' => 11,
                        'application_reference' => 'NCK-2026-1',
                        'position_id' => 2,
                        'source' => 'email',
                    ],
                ],
            ],
        ], 2);

        $this->assertTrue($channel['also_in_mailbox']);
        $this->assertCount(1, $channel['mailbox_applications']);
        $this->assertCount(1, $channel['myjobs_applications']);
    }

    public function test_mailbox_application_for_another_position_is_myjobs_only(): void
    {
        $listing = new MyJobsListingService(Mockery::mock(MyJobsXlsxReader::class));

        $channel = $listing->channelFromMatches([
            [
                'applicant_id' => 1,
                'applicant_name' => 'Jane Doe',
                'applications' => [
                    [
                        'application_id' => 10,
                        'application_reference' => 'NCK-MJ-2026-1',
                        'position_id' => 2,
                        'source' => 'myjobs',
                    ],
                    [
                        'application_id' => 11,
                        'application_reference' => 'NCK-2026-1',
                        'position_id' => 5,
                        'source' => 'email',
                    ],
                ],
            ],
        ], 2);

        $this->assertFalse($channel['also_in_mailbox']);
        $this->assertSame([], $channel['mailbox_applications']);
    }
}
