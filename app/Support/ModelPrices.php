<?php

namespace App\Support;

/**
 * USD per million tokens [input, output] per model, for cost estimates.
 * Built-in prices live in config/llm_prices.php; prices added in the admin
 * (Settings → AI provider → Model prices) override and extend them.
 */
class ModelPrices
{
    /**
     * @return array<string, array{0: float, 1: float}>
     */
    public static function all(): array
    {
        $prices = config('llm_prices.models', []);

        foreach (config('modbot.llm.price_overrides', []) as $row) {
            $model = trim((string) ($row['model'] ?? ''));

            if ($model !== '' && is_numeric($row['input'] ?? null) && is_numeric($row['output'] ?? null)) {
                $prices[$model] = [(float) $row['input'], (float) $row['output']];
            }
        }

        return $prices;
    }

    /**
     * Exact match first; otherwise the id without a date suffix, so
     * "claude-haiku-4-5-20251001" and "gpt-4o-2024-08-06" get the price of
     * their model. Other unknown ids (e.g. a newer model) have no price.
     *
     * @return array{0: float, 1: float}|null
     */
    public static function for(?string $model): ?array
    {
        if (blank($model)) {
            return null;
        }

        $prices = self::all();
        // Gemini's OpenAI-compatible API may report "models/gemini-…".
        $model = preg_replace('#^models/#', '', $model);

        return $prices[$model]
            ?? $prices[preg_replace('/-(\d{8}|\d{4}-\d{2}-\d{2})$/', '', $model)]
            ?? null;
    }
}
