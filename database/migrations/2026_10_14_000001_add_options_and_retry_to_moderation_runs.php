<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            // The run's options (windows, delivery, dry run), for "Retry".
            $table->json('options')->nullable()->after('dry_run');
            $table->foreignId('retry_of_id')->nullable()->after('options')->constrained('moderation_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retry_of_id');
            $table->dropColumn('options');
        });
    }
};
