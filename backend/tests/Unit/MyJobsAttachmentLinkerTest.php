<?php

namespace Tests\Unit;

use App\Services\MyJobs\MyJobsAttachmentLinker;
use App\Services\MyJobs\MyJobsListingService;
use Mockery;
use Tests\TestCase;

class MyJobsAttachmentLinkerTest extends TestCase
{
    public function test_maps_job_folders_including_typos_to_position_codes(): void
    {
        $linker = $this->linker();

        $this->assertSame('NCK/REC2', $linker->positionCodeForFolder('CORPORATION SECTRETARY & DIRECTOR LEGAL SERVICES'));
        $this->assertSame('NCK/REC5', $linker->positionCodeForFolder('Deputy Director human resources &'));
        $this->assertSame('NCK/REC4', $linker->positionCodeForFolder('Deputy Director research,strategy,planning & performance Mgt'));
        $this->assertSame('NCK/REC3', $linker->positionCodeForFolder('Director Corporate services'));
        $this->assertSame('NCK/REC1', $linker->positionCodeForFolder('Director Registration & Licensing'));
        $this->assertSame('NCK/REC8', $linker->positionCodeForFolder('Registration and Licensing Officer'));
        $this->assertSame('NCK/REC9', $linker->positionCodeForFolder('Education and Examination Officer'));
    }

    public function test_reads_applicant_name_from_numbered_and_profile_filenames(): void
    {
        $linker = $this->linker();

        $this->assertSame(
            'catherine mungania',
            $linker->applicantNameFromFiles(['88-catherine-mungania.pdf', 'academic-professional-certificates.pdf'])
        );
        $this->assertSame(
            'victor akanga',
            $linker->applicantNameFromFiles(['94-victor-akanga.pdf', 'bachelor-of-laws-llb.pdf'])
        );
        $this->assertSame(
            'audrey cheruto',
            $linker->applicantNameFromFiles(['audrey-cheruto_profile.pdf', 'masters.pdf'])
        );
        $this->assertSame(
            'yvonne achitsa',
            $linker->applicantNameFromFiles(['88-yvonne-achitsa-1.pdf'])
        );
        $this->assertSame(
            'bridget martha',
            $linker->applicantNameFromFiles(['88-chrp-bridget-martha.pdf'])
        );
    }

    public function test_name_matching_ignores_middle_names(): void
    {
        $listing = new MyJobsListingService(Mockery::mock(\App\Services\MyJobs\MyJobsXlsxReader::class));

        $this->assertTrue($listing->namesMatch('catherine mungania', 'Catherine Chepkorir Mungania'));
        $this->assertTrue($listing->namesMatch('kenneth sisimwo', 'Kenneth Sisimwo'));
        $this->assertFalse($listing->namesMatch('catherine mungania', 'Catherine Waithera'));
    }

    private function linker(): MyJobsAttachmentLinker
    {
        return new MyJobsAttachmentLinker(
            new MyJobsListingService(Mockery::mock(\App\Services\MyJobs\MyJobsXlsxReader::class))
        );
    }
}
