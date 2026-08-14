<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->unsignedSmallInteger('vacancies')->default(1)->after('status');
            $table->string('grade')->nullable()->after('department');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('vacancies');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['vacancies', 'grade', 'sort_order']);
        });
    }
};
