<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            // Masked API key and its source, e.g. "sk-ant-api…1AAA (from .env)".
            $table->string('api_key')->nullable()->after('provider');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }
};
