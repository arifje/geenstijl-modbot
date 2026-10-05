<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            // GeenStijl article id
            $table->unsignedBigInteger('id')->primary();
            $table->string('slug');
            $table->string('title');
            $table->timestamp('published_at')->index();
            $table->unsignedInteger('comments_count')->default(0);
            $table->boolean('comments_closed')->default(false);
            $table->timestamp('comments_synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('moderation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('running'); // running, succeeded, partial, failed
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('window_start');
            $table->timestamp('window_end');
            $table->unsignedInteger('articles_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('analyzed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('flagged_count')->default(0);
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->text('sentiment')->nullable();
            $table->string('slack_channel')->nullable();
            $table->string('slack_ts')->nullable();
            $table->string('email_message_id')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            // GeenStijl comment uuid
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('article_id');
            $table->uuid('parent_id')->nullable()->index();
            $table->string('author_id')->nullable()->index();
            $table->string('author_username')->nullable();
            $table->boolean('author_premium')->default(false);
            $table->boolean('author_newbie')->default(false);
            $table->text('content');
            $table->timestamp('posted_at')->index();
            $table->string('status');
            $table->boolean('approved')->default(false);
            $table->unsignedInteger('kudos')->default(0);
            $table->unsignedInteger('reports')->default(0);
            $table->timestamp('analyzed_at')->nullable()->index();
            $table->foreignId('analysis_run_id')->nullable()->constrained('moderation_runs')->nullOnDelete();
            $table->timestamps();

            $table->foreign('article_id')->references('id')->on('articles')->cascadeOnDelete();
        });

        Schema::create('comment_verdicts', function (Blueprint $table) {
            $table->id();
            $table->uuid('comment_id')->unique();
            $table->foreignId('moderation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('priority'); // high, medium
            $table->string('category');
            $table->text('reason');
            $table->text('quote');
            $table->string('model');
            $table->string('prompt_version');
            $table->timestamps();

            $table->foreign('comment_id')->references('id')->on('comments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_verdicts');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('moderation_runs');
        Schema::dropIfExists('articles');
    }
};
