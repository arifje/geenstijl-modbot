<?php

namespace App\Services\Analysis;

/**
 * Prompts live in resources/prompts. The version is a hash of the content,
 * so every verdict can be traced back to the exact prompt that produced it.
 */
class PromptRepository
{
    /** @var array<string, string> */
    private array $cache = [];

    public function get(string $name): string
    {
        return $this->cache[$name] ??= trim(file_get_contents(resource_path("prompts/{$name}.nl.md")));
    }

    public function version(string $name): string
    {
        return substr(sha1($this->get($name)), 0, 12);
    }
}
