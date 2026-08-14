<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table): void {
            $table->boolean('is_pwd')->nullable()->after('county')->index();
            $table->text('pwd_details')->nullable()->after('is_pwd');
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->text('professional_qualifications')->nullable()->after('professional_membership');
            $table->text('computer_proficiency')->nullable()->after('certifications_skills');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table): void {
            $table->dropColumn(['is_pwd', 'pwd_details']);
        });

        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['professional_qualifications', 'computer_proficiency']);
        });
    }
};
