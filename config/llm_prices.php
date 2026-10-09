<?php

/*
|--------------------------------------------------------------------------
| Model prices
|--------------------------------------------------------------------------
|
| USD per million tokens [input, output] at the standard rate, for the cost
| estimates in the admin. Prices added under Settings → AI provider → Model
| prices override and extend this list.
|
| Where a provider charges more for long prompts, the short-prompt price is
| listed; ModBot's per-article requests stay well below those thresholds.
| Ids with a date suffix ("claude-haiku-4-5-20251001", "gpt-4o-2024-08-06")
| use the price of the id without the date.
|
| Checked on 2026-10-09 against the providers' pricing pages.
|
*/

return [

    'models' => [

        // Anthropic — https://platform.claude.com/docs/en/about-claude/pricing
        'claude-fable-5-1' => [10.0, 50.0],
        'claude-mythos-5-1' => [10.0, 50.0],
        'claude-fable-5' => [10.0, 50.0],
        'claude-mythos-5' => [10.0, 50.0],
        'claude-opus-5-5' => [4.0, 20.0],
        'claude-opus-5' => [5.0, 25.0],
        'claude-opus-4-8' => [5.0, 25.0],
        'claude-opus-4-7' => [5.0, 25.0],
        'claude-opus-4-6' => [5.0, 25.0],
        'claude-opus-4-5' => [5.0, 25.0],
        'claude-opus-4-1' => [15.0, 75.0],
        'claude-opus-4' => [15.0, 75.0],
        'claude-sonnet-5-5' => [2.0, 10.0],
        'claude-sonnet-5' => [2.0, 10.0],
        'claude-sonnet-4-6' => [3.0, 15.0],
        'claude-sonnet-4-5' => [3.0, 15.0],
        'claude-sonnet-4' => [3.0, 15.0],
        'claude-haiku-5-5' => [0.10, 0.50],   // prompts over 100k tokens: 0.50 / 2.50
        'claude-haiku-4-5' => [1.0, 5.0],
        'claude-3-5-haiku' => [0.80, 4.0],

        // OpenAI — https://developers.openai.com/api/docs/pricing (prompts up to 272k tokens)
        'gpt-6-astra' => [10.0, 50.0],
        'gpt-6.1-sol' => [2.0, 10.0],
        'gpt-6-sol' => [2.0, 10.0],
        'gpt-6-luna' => [0.10, 0.50],
        'gpt-5.6-sol' => [4.0, 20.0],
        'gpt-5.6-terra' => [2.0, 12.0],
        'gpt-5.6-luna' => [0.20, 1.20],
        'gpt-5.5' => [5.0, 30.0],
        'gpt-5.5-pro' => [30.0, 180.0],
        'gpt-5.4' => [2.50, 15.0],
        'gpt-5.4-mini' => [0.75, 4.50],
        'gpt-5.4-nano' => [0.20, 1.25],
        'gpt-5.4-pro' => [30.0, 180.0],
        'gpt-5.3-codex' => [1.75, 14.0],
        'gpt-5.2' => [1.75, 14.0],
        'gpt-5.2-pro' => [21.0, 168.0],
        'gpt-5.1' => [1.25, 10.0],
        'gpt-5' => [1.25, 10.0],
        'gpt-5-mini' => [0.25, 2.0],
        'gpt-5-nano' => [0.05, 0.40],
        'gpt-5-pro' => [15.0, 120.0],
        'chat-latest' => [5.0, 30.0],
        'gpt-4.1' => [2.0, 8.0],
        'gpt-4.1-mini' => [0.40, 1.60],
        'gpt-4.1-nano' => [0.10, 0.40],
        'gpt-4o' => [2.50, 10.0],
        'gpt-4o-2024-05-13' => [5.0, 15.0],
        'gpt-4o-mini' => [0.15, 0.60],
        'o4-mini' => [1.10, 4.40],
        'o3' => [2.0, 8.0],
        'o3-pro' => [20.0, 80.0],
        'o3-mini' => [1.10, 4.40],
        'o1' => [15.0, 60.0],
        'o1-pro' => [150.0, 600.0],
        'gpt-4-turbo' => [10.0, 30.0],
        'gpt-4' => [30.0, 60.0],
        'gpt-3.5-turbo' => [0.50, 1.50],

        // Google Gemini — https://ai.google.dev/gemini-api/docs/pricing (paid tier, text)
        'gemini-3.8-flash' => [0.75, 3.75],   // from 2027-01-01: 1.50 / 7.50
        'gemini-3.7-flash' => [0.75, 3.75],   // from 2027-01-01: 1.50 / 7.50
        'gemini-3.6-flash' => [0.75, 3.75],   // from 2027-01-01: 1.50 / 7.50
        'gemini-3.5-flash' => [1.50, 9.0],
        'gemini-3.5-flash-lite' => [0.30, 2.50],
        'gemini-3.1-pro-preview' => [2.0, 12.0],   // prompts over 200k tokens: 4.00 / 18.00
        'gemini-3.1-flash-lite' => [0.25, 1.50],
        'gemini-3-flash-preview' => [0.50, 3.0],
        'gemini-2.5-pro' => [1.25, 10.0],   // prompts over 200k tokens: 2.50 / 15.00
        'gemini-2.5-flash' => [0.30, 2.50],
        'gemini-2.5-flash-lite' => [0.10, 0.40],

    ],

];
