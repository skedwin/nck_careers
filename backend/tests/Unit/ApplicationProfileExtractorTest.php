<?php

namespace Tests\Unit;

use App\Services\Applications\ApplicationProfileExtractor;
use PHPUnit\Framework\TestCase;

class ApplicationProfileExtractorTest extends TestCase
{
    public function test_extracts_core_profile_fields_from_labeled_body(): void
    {
        $body = <<<'TXT'
Application for NCK/REC1

Telephone/Mobile no: 0722123456
National ID no: 12345678
Nature of application: In pieces
Highest Qualification: Masters in Public Health
Management Course: Strategic Management Course, KSMS
Leadership course: SLDP
Membership to a professional body: IHRM
Experiences (Include years): 12 years in corporate services
Certifications & Skills: CPA(K), strategic planning, ERP
TXT;

        $extracted = (new ApplicationProfileExtractor)->extract('Application', null, $body);

        $this->assertSame('+254722123456', $extracted['phone']);
        $this->assertSame('12345678', $extracted['national_id']);
        $this->assertSame('pieces', $extracted['nature_of_application']);
        $this->assertSame('masters', $extracted['highest_qualification']);
        $this->assertSame('Yes', $extracted['management_course']);
        $this->assertSame('Yes', $extracted['leadership_course']);
        $this->assertSame('IHRM', $extracted['professional_membership']);
        $this->assertSame(12.0, $extracted['experience_years']);
        $this->assertStringContainsString('CPA', (string) $extracted['certifications_skills']);
    }

    public function test_phone_strips_address_and_keeps_number_only(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $this->assertSame(
            '+25420318262',
            $extractor->normalizePhoneForDisplay('+254-20-318262 NAIROBI, KENYA')
        );
        $this->assertSame(
            '072311919',
            $extractor->normalizePhoneForDisplay('072311919 Email: bcheruiyot40@gmail.com')
        );
        $this->assertSame(
            '+254713121288',
            $extractor->normalizePhoneForDisplay('0713 12 12 88 | Email: dunkahiga@gmail.com | Nationality: Kenyan')
        );
    }

    public function test_computer_proficiency_is_yes_or_no_only(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $this->assertSame('Yes', $extractor->normalizeComputerProficiencyForDisplay('proficient in MS Office'));
        $this->assertSame('Yes', $extractor->normalizeComputerProficiencyForDisplay('computer literate'));
        $this->assertSame('Yes', $extractor->normalizeComputerProficiencyForDisplay('Yes'));
        $this->assertSame('No', $extractor->normalizeComputerProficiencyForDisplay('NONE'));
        $this->assertSame('No', $extractor->normalizeComputerProficiencyForDisplay('NO'));
        $this->assertNull($extractor->normalizeComputerProficiencyForDisplay('-'));

        $extracted = $extractor->extract(null, null, "Computer Skills: Proficient in Microsoft Word and Excel\n");
        $this->assertSame('Yes', $extracted['computer_proficiency']);
    }

    public function test_picks_highest_experience_years_mentioned(): void
    {
        $cv = <<<'TXT'
Work Experience
Senior Nurse — 8 years
Nurse Manager with over 15 years experience in public health
Junior role 3 years
TXT;

        $extracted = (new ApplicationProfileExtractor)->extract(null, null, $cv);
        $this->assertSame(15.0, $extracted['experience_years']);
    }

    public function test_detects_worded_years_and_ihrm_membership(): void
    {
        $cv = <<<'TXT'
With over six years of progressive HR management experience
Professional Membership: Associate Member of IHRM
Education
Master of Business Administration
TXT;

        $extracted = (new ApplicationProfileExtractor)->extract(null, null, $cv);
        $this->assertSame(6.0, $extracted['experience_years']);
        $this->assertSame('IHRM', $extracted['professional_membership']);
        $this->assertSame('masters', $extracted['highest_qualification']);
    }

    public function test_extracts_gender_and_pwd_from_cv(): void
    {
        $cv = <<<'TXT'
Gender: Female
County of Origin: Kisumu
PWD: Yes — visual impairment
Education
Bachelor of Science in Nursing
TXT;

        $extracted = (new ApplicationProfileExtractor)->extract(null, null, $cv);
        $this->assertSame('Female', $extracted['gender']);
        $this->assertTrue($extracted['is_pwd']);
        $this->assertSame('bachelors', $extracted['highest_qualification']);
    }

    public function test_picks_highest_degree_from_cv_mentions(): void
    {
        $cv = <<<'TXT'
CURRICULUM VITAE

Education
Diploma in Nursing, KMTC 2010
Bachelor(s) of Science in Nursing, UoN 2014
Master(s) of Public Health, KU 2018

Work Experience
Nurse Manager ...
TXT;

        $extracted = (new ApplicationProfileExtractor)->extract('CV', null, $cv);

        $this->assertSame('masters', $extracted['highest_qualification']);
    }

    public function test_detects_phd_and_bachelor_variants(): void
    {
        $phd = (new ApplicationProfileExtractor)->extract(null, null, "Academic background\nPhD in Health Systems\nBSc Nursing");
        $this->assertSame('phd', $phd['highest_qualification']);

        $bachelors = (new ApplicationProfileExtractor)->extract(null, null, "Education\nBachelor's Degree in Midwifery");
        $this->assertSame('bachelors', $bachelors['highest_qualification']);

        $diploma = (new ApplicationProfileExtractor)->extract(null, null, "Qualifications\nDiploma in Community Health");
        $this->assertSame('diploma', $diploma['highest_qualification']);
    }

    public function test_ongoing_higher_degree_uses_lower_completed_level(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $phdOngoing = $extractor->extract(null, null, <<<'TXT'
Education
PhD in Public Health (ongoing)
Master of Science in Nursing, 2018
Bachelor of Science in Nursing, 2014
TXT);
        $this->assertSame('masters', $phdOngoing['highest_qualification']);
        $this->assertContains('phd', $phdOngoing['ongoing_qualifications']);

        $pursuing = $extractor->extract(null, null, <<<'TXT'
Currently pursuing a PhD in Health Systems.
MBA, University of Nairobi, 2016
BSc Nursing, 2010
TXT);
        $this->assertSame('masters', $pursuing['highest_qualification']);

        $mastersOngoing = $extractor->extract(null, null, <<<'TXT'
Education
Masters in Public Health — in progress
Bachelor of Science in Nursing, 2015
TXT);
        $this->assertSame('bachelors', $mastersOngoing['highest_qualification']);

        $completedPhd = $extractor->extract(null, null, <<<'TXT'
Education
PhD in Health Systems — completed 2022
Master of Science, 2016
TXT);
        $this->assertSame('phd', $completedPhd['highest_qualification']);
    }

    public function test_detects_higher_diploma_and_kcse_levels(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $hnd = $extractor->extract(null, null, <<<'TXT'
Education
Higher Diploma in Business Management, 2019
Diploma in Customer Care, 2016
KCSE, 2012
TXT);
        $this->assertSame('higher_diploma', $hnd['highest_qualification']);

        $diploma = $extractor->extract(null, null, <<<'TXT'
Education
Diploma in Business Administration
Kenya Certificate of Secondary Education, 2010
TXT);
        $this->assertSame('diploma', $diploma['highest_qualification']);

        $kcse = $extractor->extract(null, null, <<<'TXT'
Education
Kenya Certificate of Secondary Education (KCSE) mean grade C+
TXT);
        $this->assertSame('kcse', $kcse['highest_qualification']);
    }

    public function test_ignores_false_master_mentions(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $falseMaster = $extractor->extract(null, null, <<<'TXT'
Education
Bachelor of Commerce, 2018
Experience
Updating patient master index
Click to edit Master title style
Master Craft III Certificate in Electrical Installation
REFEREES
Mr Mwenda, MSc Former Head of Nursing
TXT);
        $this->assertSame('bachelors', $falseMaster['highest_qualification']);

        $realMaster = $extractor->extract(null, null, <<<'TXT'
Education
Master of Business Administration, University of Nairobi, 2020
Bachelor of Commerce, 2015
TXT);
        $this->assertSame('masters', $realMaster['highest_qualification']);

        $dashed = $extractor->extract(null, null, <<<'TXT'
Education
Master's – Business Administration | Africa Nazarene University | Sep 2017 - Oct 2020
Bachelor of Commerce | Daystar University | 2006 - 2010
TXT);
        $this->assertSame('masters', $dashed['highest_qualification']);

        $ongoingMba = $extractor->extract(null, null, <<<'TXT'
Education
MSc, Project Management University of Nairobi 2025 – Present (Ongoing)
BSc, Computer Information Systems Kenya Methodist University, 2018
TXT);
        $this->assertSame('bachelors', $ongoingMba['highest_qualification']);
    }

    public function test_ignores_referee_phd_signatures(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $referee = $extractor->extract(null, null, <<<'TXT'
Education
Bachelor of Arts, University of Nairobi, 2018
REFEREES
Dr. Joab M. Kinzi, Ph D- Dean Faculty of Education, Tom Mboya University
Ronald G. Maathai, Ph.d REGISTRAR, ACADEMIC ADMINISTRATION
Assisted two Ph D candidates in conducting field research
TXT);
        $this->assertSame('bachelors', $referee['highest_qualification']);

        $real = $extractor->extract(null, null, <<<'TXT'
Education
PhD in Health Systems, University of Nairobi, 2021
Master of Public Health, 2016
TXT);
        $this->assertSame('phd', $real['highest_qualification']);

        $candidate = $extractor->extract(null, null, <<<'TXT'
Education
Currently completing a Ph D in International Studies, with graduation expected in December 2026.
Bachelor of Arts, University of Nairobi, 2018
TXT);
        $this->assertSame('bachelors', $candidate['highest_qualification']);
        $this->assertContains('phd', $candidate['ongoing_qualifications']);
    }

    public function test_detects_glued_pdf_education_text(): void
    {
        $extractor = new ApplicationProfileExtractor;

        $bachelor = $extractor->extract(null, null, 'EDUCATION*BachelorofTourismManagement,UpperSecondClassHonors*KenyattaUniversity,Nairobi|2016*KenyaCertificateofSecondaryEducationKCSE*EliteA');
        $this->assertSame('bachelors', $bachelor['highest_qualification']);

        $diploma = $extractor->extract(null, null, 'EDUCATIONBACKGROUND2025-2026:CERTIFICATEINFOODPRODUCTION,CAKEMAKINGANDPASTRIES,UPISHIPOANYUMBANICOLLEGE.2023-2026:DIPLOMAININFORMATIONTECHNOLOGY,KENYACOASTNATIONALPOLYTECHNIC.');
        $this->assertSame('diploma', $diploma['highest_qualification']);

        $fromFilename = $extractor->extract(null, null, "===== ACADEMIC FILENAME HINT: Jane diploma_merged.pdf =====\n");
        $this->assertSame('diploma', $fromFilename['highest_qualification']);

        $soft = $extractor->extract(null, null, <<<'TXT'
EDUCATION AND PROFESSIONAL BACKGROUND
• Procurement and supplies management: Zetech University; 2015 – 2017
• Computer Literacy Course: Vision Computer College 2013-2014
TXT);
        $this->assertSame('diploma', $soft['highest_qualification']);
    }
}
