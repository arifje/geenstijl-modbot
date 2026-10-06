<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            // Articles whose comments could not be fetched in this run.
            $table->unsignedInteger('sync_failed_count')->default(0)->after('failed_count');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->dropColumn('sync_failed_count');
        });
    }
};
