<?php

return [

    // Version of this installation (the VERSION file, updated with each release).
    'version' => trim((string) @file_get_contents(base_path('VERSION'))) ?: 'dev',

    // Who maintains this installation; shown under System → About & updates.
    'maintainer' => [
        'name' => env('MODBOT_MAINTAINER_NAME', 'pixio'),
        'email' => env('MODBOT_MAINTAINER_EMAIL', 'info@pixio.media'),
        'url' => env('MODBOT_MAINTAINER_URL') ?: null,
    ],

    /*
    |--------------------------------------------------------------------------
    | GeenStijl sources
    |--------------------------------------------------------------------------
    |
    | Timestamps from both APIs are local Amsterdam time without an offset.
    |
    */

    'geenstijl' => [
        'content_api_url' => env('MODBOT_CONTENT_API_URL', 'https://content-api.prod.platform.gstech.nl'),
        'comments_api_url' => env('MODBOT_COMMENTS_API_URL', 'https://comments-api.prod.platform.gstech.nl'),
        'site_url' => env('MODBOT_SITE_URL', 'https://www.geenstijl.nl'),
        'timezone' => 'Europe/Amsterdam',
        'page_size' => (int) env('MODBOT_ARTICLES_PAGE_SIZE', 20),
        'max_pages' => (int) env('MODBOT_ARTICLES_MAX_PAGES', 5),
        'timeout' => (int) env('MODBOT_HTTP_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Windows (defaults; schedules and "Run now" set their own)
    |--------------------------------------------------------------------------
    |
    | window_hours: comment window, comments posted in the last X hours.
    | article_lookback_hours: article window, articles published in the last
    | X hours. GeenStijl currently closes comments 12 hours after publication,
    | so 24 hours covers every article that can receive comments inside a
    | 12-hour comment window.
    |
    */

    'window_hours' => (int) env('MODBOT_WINDOW_HOURS', 12),
    'article_lookback_hours' => (int) env('MODBOT_ARTICLE_LOOKBACK_HOURS', 24),

    // Which visible comments are analysed and reported:
    //   unapproved  not yet approved by a moderator (default)
    //   approved    only approved comments (what the Make scenario did)
    //   all         both
    'approval_filter' => env('MODBOT_APPROVAL_FILTER', 'unapproved'),
    'statuses' => ['visible'],

    /*
    |--------------------------------------------------------------------------
    | Analysis (LLM)
    |--------------------------------------------------------------------------
    */

    'llm' => [
        // anthropic, openai or openai_compatible. Can be switched in the admin.
        'provider' => env('MODBOT_LLM_PROVIDER', 'anthropic'),
        // low, medium, high, or "default" to let the provider/model decide.
        'effort' => env('MODBOT_LLM_EFFORT', 'medium'),
        // Max length of one answer (thinking + JSON). Also under Settings.
        'max_tokens' => (int) env('MODBOT_LLM_MAX_TOKENS', 32000),
        'timeout' => (int) env('MODBOT_LLM_TIMEOUT', 600),

        // API keys stay in the environment; models and the base URL can be
        // changed in the admin.
        'providers' => [
            'anthropic' => [
                'api_key' => env('ANTHROPIC_API_KEY'),
                'model' => env('MODBOT_LLM_MODEL', 'claude-opus-5-5'),
            ],
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'model' => env('OPENAI_MODEL'),
                'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            ],
            // Any provider with an OpenAI-compatible chat completions API
            // (Google Gemini, Mistral, Groq, Ollama, ...).
            'openai_compatible' => [
                'api_key' => env('LLM_COMPATIBLE_API_KEY'),
                'model' => env('LLM_COMPATIBLE_MODEL'),
                'base_url' => env('LLM_COMPATIBLE_BASE_URL'),
            ],
        ],

        // Comments per request. Comments are grouped per article; larger
        // articles are split into chunks of this size.
        'batch_size' => (int) env('MODBOT_LLM_BATCH_SIZE', 400),
        // Built-in prices: config/llm_prices.php. Prices added under
        // Settings → AI provider → Model prices (stored here) override them.
        'price_overrides' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */

    'report' => [
        'max_items' => (int) env('MODBOT_REPORT_MAX_ITEMS', 50),
        // Only used to seed the first schedule; schedules are managed in the admin.
        'cron' => env('MODBOT_REPORT_CRON', '0 8,20 * * *'),
    ],

    'slack' => [
        'enabled' => (bool) env('SLACK_ENABLED', true),
        'bot_token' => env('SLACK_BOT_TOKEN'),
        'channel' => env('SLACK_CHANNEL') ?: '#development',
        // Needs the chat:write.customize scope; leave empty to use the app's own name/icon.
        'username' => env('SLACK_USERNAME', 'ModBot'),
        'icon_url' => env('SLACK_ICON_URL'),
    ],

    'email' => [
        'enabled' => (bool) env('AHASEND_ENABLED', true),
        'api_key' => env('AHASEND_API_KEY'),
        'account_id' => env('AHASEND_ACCOUNT_ID'),
        'from_email' => env('AHASEND_FROM_EMAIL', 'no-reply@platform.geenstijl.nl'),
        'from_name' => env('AHASEND_FROM_NAME', 'GeenStijl Moderatierapport'),
        // Comma separated, optionally "Name <email>".
        'recipients' => env('AHASEND_RECIPIENTS', ''),
        'sandbox' => (bool) env('AHASEND_SANDBOX', false),
    ],

    'retention_days' => (int) env('MODBOT_RETENTION_DAYS', 90),
    // Log entries shown in the admin (System → Logs).
    'log_retention_days' => (int) env('MODBOT_LOG_RETENTION_DAYS', 14),

    'auth' => [
        // false: log in with only a code sent by email (no password).
        // Switch in the admin under Settings, or: php artisan modbot:password-login on|off
        'password_login' => (bool) env('MODBOT_PASSWORD_LOGIN', true),
        // Failed logins (wrong password, wrong code, unknown address) before an
        // email address is locked; an IP address may fail 4x as often.
        'max_failed_logins' => (int) env('MODBOT_MAX_FAILED_LOGINS', 5),
        'lockout_minutes' => (int) env('MODBOT_LOGIN_LOCKOUT_MINUTES', 15),
    ],

];
