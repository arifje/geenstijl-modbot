<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Articles published in the last X hours; window_hours stays the comment window.
        Schema::table('schedules', function (Blueprint $table) {
            $table->unsignedInteger('article_window_hours')->default(24)->after('window_hours');
        });

        // Null for runs from before this change (no article filter).
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->timestamp('article_window_start')->nullable()->after('window_end');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', fn (Blueprint $table) => $table->dropColumn('article_window_hours'));
        Schema::table('moderation_runs', fn (Blueprint $table) => $table->dropColumn('article_window_start'));
    }
};
