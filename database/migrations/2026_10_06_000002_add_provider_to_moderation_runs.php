<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('trigger');
        });
    }

    public function down(): void
    {
        Schema::table('moderation_runs', function (Blueprint $table) {
            $table->dropColumn('provider');
        });
    }
};
