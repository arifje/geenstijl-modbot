<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Settings editable in the admin. Each key maps to a config path; stored
 * values override the environment defaults on boot. Secrets stay in the
 * environment.
 */
class Settings
{
    /** setting key => config path */
    public const KEYS = [
        'slack_enabled' => 'modbot.slack.enabled',
        'slack_channel' => 'modbot.slack.channel',
        'email_enabled' => 'modbot.email.enabled',
        'email_recipients' => 'modbot.email.recipients',
        'only_approved' => 'modbot.only_approved',
        'article_lookback_hours' => 'modbot.article_lookback_hours',
        'llm_model' => 'modbot.llm.model',
        'llm_effort' => 'modbot.llm.effort',
        'report_max_items' => 'modbot.report.max_items',
        'retention_days' => 'modbot.retention_days',
    ];

    /**
     * Apply stored settings to the config. Silently skipped when the
     * database is not available yet (image build, first migration).
     */
    public static function apply(): void
    {
        try {
            $stored = Setting::whereIn('key', array_keys(self::KEYS))->pluck('value', 'key');
        } catch (\Throwable) {
            return;
        }

        foreach ($stored as $key => $value) {
            if ($value !== null) {
                config([self::KEYS[$key] => $value]);
            }
        }
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
