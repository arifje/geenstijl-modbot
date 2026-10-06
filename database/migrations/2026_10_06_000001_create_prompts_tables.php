<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique(); // moderation, summary
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->timestamps();
        });

        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            // Short content hash; stored on runs and verdicts as prompt_version.
            $table->string('version', 12)->index();
            $table->string('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        // Start from the prompt files, so existing runs keep matching versions.
        $prompts = [
            'moderation' => ['Moderation', 'Judges the comments of one article and returns the flags (JSON). The output format is enforced by the API, so editing the wording cannot break the report.'],
            'summary' => ['Sentiment summary', 'Combines the per-article summaries into the two sentences under "Sfeer en sentiment".'],
        ];

        foreach ($prompts as $key => [$name, $description]) {
            $content = trim(file_get_contents(resource_path("prompts/{$key}.nl.md")));
            $promptId = DB::table('prompts')->insertGetId([
                'key' => $key, 'name' => $name, 'description' => $description,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $versionId = DB::table('prompt_versions')->insertGetId([
                'prompt_id' => $promptId,
                'content' => $content,
                'version' => substr(sha1($content), 0, 12),
                'note' => 'Initial version (from resources/prompts)',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('prompts')->where('id', $promptId)->update(['active_version_id' => $versionId]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('prompts');
    }
};
