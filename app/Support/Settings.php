<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Settings editable in the admin. Each key maps to a config path; stored
 * values override the environment defaults on boot.
 *
 * API keys (SECRETS) are stored encrypted with APP_KEY and are never sent to
 * the browser. When none is stored, the key from .env is used; the .env
 * value stays available as "<path>_env" for display.
 */
class Settings
{
    /** setting key => config path */
    public const KEYS = [
        'slack_enabled' => 'modbot.slack.enabled',
        'slack_channel' => 'modbot.slack.channel',
        'email_enabled' => 'modbot.email.enabled',
        'email_recipients' => 'modbot.email.recipients',
        'approval_filter' => 'modbot.approval_filter',
        'article_lookback_hours' => 'modbot.article_lookback_hours',
        'llm_provider' => 'modbot.llm.provider',
        'llm_model' => 'modbot.llm.providers.anthropic.model',
        'openai_model' => 'modbot.llm.providers.openai.model',
        'compatible_model' => 'modbot.llm.providers.openai_compatible.model',
        'compatible_base_url' => 'modbot.llm.providers.openai_compatible.base_url',
        'llm_effort' => 'modbot.llm.effort',
        'llm_max_tokens' => 'modbot.llm.max_tokens',
        'llm_batch_size' => 'modbot.llm.batch_size',
        'llm_price_overrides' => 'modbot.llm.price_overrides',
        'report_max_items' => 'modbot.report.max_items',
        'retention_days' => 'modbot.retention_days',
        'log_retention_days' => 'modbot.log_retention_days',
        'password_login' => 'modbot.auth.password_login',
        'max_failed_logins' => 'modbot.auth.max_failed_logins',
        'lockout_minutes' => 'modbot.auth.lockout_minutes',
    ];

    /** setting key => config path of an API key */
    public const SECRETS = [
        'anthropic_api_key' => 'modbot.llm.providers.anthropic.api_key',
        'openai_api_key' => 'modbot.llm.providers.openai.api_key',
        'compatible_api_key' => 'modbot.llm.providers.openai_compatible.api_key',
    ];

    /**
     * Apply stored settings to the config. Silently skipped when the
     * database is not available yet (image build, first migration).
     */
    public static function apply(): void
    {
        try {
            $stored = Setting::whereIn('key', [...array_keys(self::KEYS), ...array_keys(self::SECRETS)])->pluck('value', 'key');
        } catch (\Throwable) {
            return;
        }

        // Remember the .env key once, and start from it on every apply.
        foreach (self::SECRETS as $path) {
            config(["{$path}_env" => config("{$path}_env", config($path))]);
            config([$path => config("{$path}_env")]);
        }

        foreach ($stored as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (isset(self::SECRETS[$key])) {
                try {
                    config([self::SECRETS[$key] => Crypt::decryptString($value)]);
                } catch (DecryptException) {
                    // APP_KEY changed: the stored key is unreadable; .env is used.
                    Log::warning("Stored {$key} cannot be decrypted (APP_KEY changed?); using .env instead.");
                }

                continue;
            }

            config([self::KEYS[$key] => $value]);
        }
    }

    public static function saveSecret(string $key, string $value): void
    {
        Setting::updateOrCreate(['key' => $key], ['value' => Crypt::encryptString(trim($value))]);
        config([self::SECRETS[$key] => trim($value)]);
    }

    /**
     * Remove a stored API key; the key from .env is used again.
     */
    public static function forgetSecret(string $key): void
    {
        Setting::whereKey($key)->delete();
        config([self::SECRETS[$key] => config(self::SECRETS[$key].'_env')]);
    }

    /** AI provider => its API key setting */
    public const PROVIDER_SECRETS = [
        'anthropic' => 'anthropic_api_key',
        'openai' => 'openai_api_key',
        'openai_compatible' => 'compatible_api_key',
    ];

    /**
     * The key a provider uses, masked, with its source:
     * "sk-ant-api…1AAA (from .env)". Safe for logs and the admin.
     */
    public static function keyHint(?string $provider = null): string
    {
        $provider ??= config('modbot.llm.provider');
        $key = self::PROVIDER_SECRETS[$provider] ?? null;
        $value = config("modbot.llm.providers.{$provider}.api_key");

        if ($key === null || blank($value)) {
            return 'none';
        }

        $source = rescue(fn () => self::hasStoredSecret($key), false, false) ? 'saved in ModBot' : 'from .env';

        return self::mask($value)." ({$source})";
    }

    /**
     * First 10 and last 4 characters ("sk-ant-api…1AAA"): the last 4 match how
     * the provider consoles list keys; the rest of the key stays hidden.
     */
    public static function mask(string $secret): string
    {
        $secret = trim($secret);

        return strlen($secret) <= 16
            ? substr($secret, 0, 2).'…'.substr($secret, -2)
            : substr($secret, 0, 10).'…'.substr($secret, -4);
    }

    public static function hasStoredSecret(string $key): bool
    {
        return Setting::whereKey($key)->whereNotNull('value')->exists();
    }

    /**
     * Current effective values (stored or environment default).
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return collect(self::KEYS)->map(fn (string $path) => config($path))->all();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (array_intersect_key($values, self::KEYS) as $key => $value) {
            Setting::updateOrCreate(['key' => $key], ['value' => $value]);
            config([self::KEYS[$key] => $value]);
        }
    }
}
