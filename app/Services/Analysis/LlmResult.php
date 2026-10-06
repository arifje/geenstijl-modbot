<?php

namespace App\Services\Analysis;

final readonly class LlmResult
{
    public function __construct(
        public string $text,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function json(): mixed
    {
        return json_decode($this->text, true, flags: JSON_THROW_ON_ERROR);
    }
}
