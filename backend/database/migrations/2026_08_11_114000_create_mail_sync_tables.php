<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('mailbox')->index();
            $table->string('sync_type', 32); // initial|incremental|manual|scheduled
            $table->string('status', 32)->default('pending')->index(); // pending|running|paused|completed|failed|cancelled
            $table->string('trigger', 32)->default('manual'); // manual|schedule|system
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('messages_discovered')->default(0);
            $table->unsignedInteger('messages_imported')->default(0);
            $table->unsignedInteger('messages_skipped')->default(0);
            $table->unsignedInteger('messages_failed')->default(0);
            $table->unsignedInteger('pages_processed')->default(0);
            $table->text('next_link')->nullable();
            $table->text('delta_link')->nullable();
            $table->text('error_summary')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['mailbox', 'created_at']);
        });

        Schema::create('mail_sync_states', function (Blueprint $table) {
            $table->id();
            $table->string('mailbox')->unique();
            $table->text('delta_link')->nullable();
            $table->boolean('is_paused')->default(false);
            $table->boolean('initial_sync_completed')->default(false);
            $table->foreignId('last_sync_run_id')->nullable()->constrained('mail_sync_runs')->nullOnDelete();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->string('graph_message_id')->charset('utf8mb4')->collation('utf8mb4_bin')->unique();
                $table->string('internet_message_id', 512)->nullable()->charset('utf8mb4')->collation('utf8mb4_bin');
            } else {
                $table->string('graph_message_id')->unique();
                $table->string('internet_message_id', 512)->nullable();
            }

            $table->string('conversation_id')->nullable()->index();
            $table->string('mailbox')->index();
            $table->string('sender_name')->nullable();
            $table->string('sender_email')->nullable()->index();
            $table->string('subject', 998)->nullable();
            $table->timestamp('received_at')->nullable()->index();
            $table->text('body_text')->nullable();
            $table->longText('body_html')->nullable();
            $table->boolean('has_attachments')->default(false);
            $table->text('web_link')->nullable();
            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->string('sync_status', 32)->default('imported')->index();
            $table->foreignId('mail_sync_run_id')->nullable()->constrained('mail_sync_runs')->nullOnDelete();
            $table->timestamps();

            $table->unique('internet_message_id');
        });

        Schema::create('mail_sync_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_sync_run_id')->constrained('mail_sync_runs')->cascadeOnDelete();

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->string('graph_message_id')->nullable()->index()->charset('utf8mb4')->collation('utf8mb4_bin');
            } else {
                $table->string('graph_message_id')->nullable()->index();
            }

            $table->string('stage', 64)->default('import');
            $table->text('error_message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['mail_sync_run_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_sync_errors');
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_sync_states');
        Schema::dropIfExists('mail_sync_runs');
    }
};
