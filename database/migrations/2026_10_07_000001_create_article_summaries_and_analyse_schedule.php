<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-article tone summaries from each analysis, so a report can describe
        // the whole window even when the comments were analysed by earlier runs.
        Schema::create('article_summaries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id');
            $table->foreignId('moderation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->text('summary');
            $table->unsignedInteger('comments_count')->default(0);
            $table->timestamp('created_at')->index();

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
        });

        // Analyse often, report twice a day: comments are judged while they wait
        // for approval. Disabled until switched on in the admin.
        if (! DB::table('schedules')->where('name', 'Analyse (no report)')->exists()) {
            DB::table('schedules')->insert([
                'name' => 'Analyse (no report)',
                'cron' => '*/15 * * * *',
                'timezone' => 'Europe/Amsterdam',
                'window_hours' => 12,
                'send_slack' => false,
                'send_email' => false,
                'enabled' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('schedules')->where('name', 'Analyse (no report)')->delete();
        Schema::dropIfExists('article_summaries');
    }
};
