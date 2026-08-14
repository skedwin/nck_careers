<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->timestamp('duplicate_hidden_at')->nullable()->after('profile_extracted_at')->index();
            $table->foreignId('duplicate_hidden_by')->nullable()->after('duplicate_hidden_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('duplicate_of_application_id')->nullable()->after('duplicate_hidden_by')
                ->constrained('applications')->nullOnDelete();
            $table->string('duplicate_of_reference', 64)->nullable()->after('duplicate_of_application_id');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('duplicate_hidden_by');
            $table->dropConstrainedForeignId('duplicate_of_application_id');
            $table->dropColumn(['duplicate_hidden_at', 'duplicate_of_reference']);
        });
    }
};
