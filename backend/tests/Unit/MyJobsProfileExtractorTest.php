<?php

namespace Tests\Unit;

use App\Services\Applications\ApplicationProfileExtractor;
use App\Services\MyJobs\MyJobsProfileExtractor;
use PHPUnit\Framework\TestCase;

class MyJobsProfileExtractorTest extends TestCase
{
    public function test_extracts_education_gender_phone_and_salary(): void
    {
        $extracted = (new MyJobsProfileExtractor(new ApplicationProfileExtractor))->extract([
            'name' => 'Joseline Chekorir',
            'gender' => 'Female',
            'age' => '32 Years',
            'position' => 'Communications Officer',
            'company' => 'Public Service Commission',
            'salary' => 'Exp: KES 50,000 / - Curr: KES 30,000 / -',
            'education' => 'Bachelor Of Science ( Communication And Journalism)',
            'score' => '75',
            'scores_link' => 'httpss://www.myjobsinkenya.com/survey-results/43898',
            'email' => 'chepkorirbungei@gmail.com',
            'phone_no' => '0722123456',
            'application_date' => 'Monday 10 August 2026',
            'file' => 'Corporate communications Officer.xlsx',
        ]);

        $this->assertSame('bachelors', $extracted['highest_qualification']);
        $this->assertStringContainsString('Journalism', (string) $extracted['highest_qualification_detail']);
        $this->assertSame('Female', $extracted['gender']);
        $this->assertSame('+254722123456', $extracted['phone']);
        $this->assertSame('one', $extracted['nature_of_application']);
        $this->assertSame('KES 50,000', $extracted['myjobs']['expected_salary']);
        $this->assertSame('KES 30,000', $extracted['myjobs']['current_salary']);
        $this->assertSame(32, $extracted['myjobs']['age_years']);
        $this->assertSame('75', $extracted['myjobs']['score']);
        $this->assertSame('https://www.myjobsinkenya.com/survey-results/43898', $extracted['myjobs']['scores_link']);
        $this->assertSame(['myjobs_csv'], $extracted['sources']);
    }

    public function test_maps_degree_in_and_advanced_diploma(): void
    {
        $extractor = new MyJobsProfileExtractor(new ApplicationProfileExtractor);

        $degree = $extractor->extract([
            'education' => 'Degree In Criminology And Security Studies',
            'gender' => 'M',
        ]);
        $this->assertSame('bachelors', $degree['highest_qualification']);
        $this->assertSame('Male', $degree['gender']);

        $diploma = $extractor->extract([
            'education' => 'Advanced Diploma',
        ]);
        $this->assertSame('higher_diploma', $diploma['highest_qualification']);

        $kcse = $extractor->extract([
            'education' => 'Kenya Certificate Of Secondary Education (kcse)',
        ]);
        $this->assertSame('kcse', $kcse['highest_qualification']);
    }
}
