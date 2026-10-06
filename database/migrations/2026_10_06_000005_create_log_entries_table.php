<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('log_entries', function (Blueprint $table) {
            $table->id();
            $table->string('level', 20);            // info, warning, error, ...
            $table->unsignedSmallInteger('level_value'); // Monolog level, for "warning and up"
            $table->string('category', 20)->index(); // app, queue, scheduler, run
            $table->text('message');
            $table->json('context')->nullable();
            $table->string('source')->nullable();    // web, or the artisan command
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_entries');
    }
};
