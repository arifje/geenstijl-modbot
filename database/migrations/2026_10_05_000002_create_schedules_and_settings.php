<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('cron');
            $table->string('timezone')->default('Europe/Amsterdam');
            $table->unsignedInteger('window_hours')->default(12);
            $table->boolean('send_slack')->default(true);
            $table->boolean('send_email')->default(true);
            // Overrides the default Slack channel from the settings.
            $table->string('slack_channel')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->string('trigger')->default('cli')->after('status'); // schedule, manual, cli
            $table->foreignId('schedule_id')->nullable()->after('trigger')->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->after('schedule_id')->constrained()->nullOnDelete();
            $table->boolean('dry_run')->default(false)->after('user_id');
        });

        // Carry over the schedule from the environment so a fresh install
        // behaves like before.
        DB::table('schedules')->insert([
            'name' => 'Moderatierapport',
            'cron' => env('MODBOT_REPORT_CRON', '0 8,20 * * *'),
            'timezone' => 'Europe/Amsterdam',
            'window_hours' => (int) env('MODBOT_WINDOW_HOURS', 12),
            'send_slack' => true,
            'send_email' => true,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropConstrainedForeignId('user_id');
            $table->dropColumn(['trigger', 'dry_run']);
        });

        Schema::dropIfExists('settings');
        Schema::dropIfExists('schedules');
    }
};
