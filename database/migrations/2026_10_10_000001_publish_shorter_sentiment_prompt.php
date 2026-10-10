<?php

use App\Models\Prompt;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * The first summary prompt let smaller models list a summary per article.
     * Publish the new default as a new version, but only when the active
     * version is still that first default: edits made in the admin stay.
     */
    private const FIRST_DEFAULT = 'a78c0dc062c5';

    public function up(): void
    {
        $prompt = Prompt::where('key', 'summary')->first();

        if ($prompt?->activeVersion?->version !== self::FIRST_DEFAULT) {
            return;
        }

        $prompt->publish(
            file_get_contents(resource_path('prompts/summary.nl.md')),
            'One recap for all articles, max 30 words (v1.9.2)',
        );
    }

    public function down(): void
    {
        //
    }
};
