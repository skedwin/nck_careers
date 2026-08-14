<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Graph/Outlook IDs are case-sensitive. utf8mb4_0900_ai_ci unique indexes
        // collapsed ~1k distinct messages into false duplicates.
        DB::statement('ALTER TABLE mail_messages MODIFY graph_message_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE mail_messages MODIFY internet_message_id VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
        DB::statement('ALTER TABLE mail_sync_errors MODIFY graph_message_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE mail_messages MODIFY graph_message_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NOT NULL');
        DB::statement('ALTER TABLE mail_messages MODIFY internet_message_id VARCHAR(512) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL');
        DB::statement('ALTER TABLE mail_sync_errors MODIFY graph_message_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci NULL');
    }
};
