<?php

namespace App\Services\Analysis;

use App\Models\Prompt;

/**
 * Prompts are managed in the admin (prompts / prompt_versions tables). The
 * files in resources/prompts are the defaults, used when the database has
 * no version yet. The version is a hash of the content, so every run and
 * verdict can be traced back to the exact prompt that produced it.
 */
class PromptRepository
{
    /** @var array<string, string> */
    private array $cache = [];

    public function get(string $name): string
    {
        return $this->cache[$name] ??= $this->fromDatabase($name) ?? $this->default($name);
    }

    public function version(string $name): string
    {
        return substr(sha1($this->get($name)), 0, 12);
    }

    public function default(string $name): string
    {
        return trim(file_get_contents(resource_path("prompts/{$name}.nl.md")));
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function fromDatabase(string $name): ?string
    {
        try {
            return Prompt::where('key', $name)->first()?->activeVersion?->content;
        } catch (\Throwable) {
            return null; // database not migrated yet
        }
    }
}
