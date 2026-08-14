<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('reference_code')->unique();
            $table->text('description')->nullable();
            $table->string('department')->nullable();
            $table->string('status', 32)->default('open')->index(); // draft|open|closed
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->timestamps();
        });

        Schema::create('position_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('position_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('label');
            $table->text('description')->nullable();
            $table->boolean('is_mandatory')->default(true);
            $table->unsignedSmallInteger('weight')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['position_id', 'code']);
        });

        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('full_name');
            $table->string('email')->nullable()->index();
            $table->string('phone')->nullable();
            $table->string('registration_number')->nullable()->index();
            $table->string('national_id')->nullable();
            $table->string('gender', 32)->nullable();
            $table->string('county')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('mail_message_id')->constrained('mail_messages')->cascadeOnDelete();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->string('graph_attachment_id')->charset('utf8mb4')->collation('utf8mb4_bin');
            } else {
                $table->string('graph_attachment_id');
            }

            $table->string('name');
            $table->string('content_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_inline')->default(false);
            $table->string('sha256_hash', 64)->nullable()->index();
            $table->string('disk', 32)->default('private');
            $table->string('path')->nullable();
            $table->string('download_status', 32)->default('pending')->index(); // pending|downloaded|failed|skipped
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->unique(['mail_message_id', 'graph_attachment_id'], 'mail_attachments_message_graph_unique');
        });

        Schema::create('applications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('application_reference')->unique();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('position_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mail_message_id')->nullable()->unique()->constrained('mail_messages')->nullOnDelete();
            $table->string('subject')->nullable();
            $table->string('status', 32)->default('received')->index();
            // received|under_review|eligible|not_eligible|needs_review|shortlisted|rejected|withdrawn
            $table->string('screening_status', 32)->default('pending')->index();
            // pending|in_progress|passed|failed|needs_review
            $table->string('source', 32)->default('email');
            $table->timestamp('received_at')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('application_documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mail_attachment_id')->nullable()->constrained('mail_attachments')->nullOnDelete();
            $table->string('document_type', 64)->default('attachment');
            $table->string('original_name');
            $table->string('disk', 32)->default('private');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('sha256_hash', 64)->nullable()->index();
            $table->timestamps();
        });

        Schema::create('application_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('screening_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('criteria_code', 64);
            $table->string('label');
            $table->string('result', 32)->default('unknown'); // pass|fail|unknown
            $table->text('evidence')->nullable();
            $table->string('scored_by', 32)->default('system'); // system|user
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['application_id', 'criteria_code']);
        });

        Schema::create('ai_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('application_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 64)->default('mock');
            $table->string('status', 32)->default('pending')->index();
            $table->decimal('confidence', 5, 4)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('mail_messages', function (Blueprint $table) {
            $table->string('attachments_status', 32)->default('pending')->after('sync_status')->index();
            // pending|queued|downloaded|partial|failed|none
            $table->boolean('application_created')->default(false)->after('attachments_status')->index();
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn(['attachments_status', 'application_created']);
        });

        Schema::dropIfExists('ai_extractions');
        Schema::dropIfExists('screening_results');
        Schema::dropIfExists('application_status_history');
        Schema::dropIfExists('application_documents');
        Schema::dropIfExists('applications');
        Schema::dropIfExists('mail_attachments');
        Schema::dropIfExists('applicants');
        Schema::dropIfExists('position_criteria');
        Schema::dropIfExists('positions');
    }
};
