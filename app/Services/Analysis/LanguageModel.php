<?php

namespace App\Services\Analysis;

interface LanguageModel
{
    /**
     * @param  array<string, mixed>|null  $schema  JSON schema for structured output
     *
     * @throws LlmException
     */
    public function complete(string $system, string $input, ?array $schema = null): LlmResult;

    public function name(): string;
}
