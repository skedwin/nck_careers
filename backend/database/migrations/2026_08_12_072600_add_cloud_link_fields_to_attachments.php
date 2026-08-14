<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_attachments', function (Blueprint $table): void {
            $table->string('source', 32)->default('graph_file')->after('graph_attachment_id')->index();
            // graph_file|graph_reference|body_link
            $table->string('provider', 32)->nullable()->after('source')->index();
            // onedrive|sharepoint|google_drive|dropbox|other
            $table->text('external_url')->nullable()->after('provider');
            $table->string('odata_type')->nullable()->after('external_url');
        });

        Schema::table('application_documents', function (Blueprint $table): void {
            $table->string('path')->nullable()->change();
            $table->text('external_url')->nullable()->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('application_documents', function (Blueprint $table): void {
            $table->dropColumn('external_url');
        });

        // Revert path to non-null only if no nulls remain — keep nullable on rollback for safety.
        Schema::table('mail_attachments', function (Blueprint $table): void {
            $table->dropColumn(['source', 'provider', 'external_url', 'odata_type']);
        });
    }
};
