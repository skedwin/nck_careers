<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->string('nature_of_application', 32)->nullable()->after('notes')->index();
            // one|pieces|unknown
            $table->text('nature_of_application_detail')->nullable()->after('nature_of_application');
            $table->string('highest_qualification', 64)->nullable()->after('nature_of_application_detail')->index();
            $table->text('highest_qualification_detail')->nullable()->after('highest_qualification');
            $table->text('management_course')->nullable()->after('highest_qualification_detail');
            $table->text('leadership_course')->nullable()->after('management_course');
            $table->text('professional_membership')->nullable()->after('leadership_course');
            $table->text('experience_summary')->nullable()->after('professional_membership');
            $table->decimal('experience_years', 5, 1)->nullable()->after('experience_summary');
            $table->text('certifications_skills')->nullable()->after('experience_years');
            $table->json('profile_extraction')->nullable()->after('certifications_skills');
            $table->timestamp('profile_extracted_at')->nullable()->after('profile_extraction');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn([
                'nature_of_application',
                'nature_of_application_detail',
                'highest_qualification',
                'highest_qualification_detail',
                'management_course',
                'leadership_course',
                'professional_membership',
                'experience_summary',
                'experience_years',
                'certifications_skills',
                'profile_extraction',
                'profile_extracted_at',
            ]);
        });
    }
};
