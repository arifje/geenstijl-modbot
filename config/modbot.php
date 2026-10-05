<?php

return [

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
    | Window
    |--------------------------------------------------------------------------
    |
    | window_hours: comments posted in this window are analysed and reported.
    | article_lookback_hours: articles published within this period are checked
    | for new comments. GeenStijl currently closes comments 12 hours after
    | publication, so 24 hours covers every article that can receive comments
    | inside a 12-hour window. Raise it if GeenStijl keeps comments open longer.
    |
    */

    'window_hours' => (int) env('MODBOT_WINDOW_HOURS', 12),
    'article_lookback_hours' => (int) env('MODBOT_ARTICLE_LOOKBACK_HOURS', 24),

    // Same filters as the Make scenario: only approved, visible comments.
    'only_approved' => (bool) env('MODBOT_ONLY_APPROVED', true),
    'statuses' => ['visible'],

    /*
    |--------------------------------------------------------------------------
    | Analysis (LLM)
    |--------------------------------------------------------------------------
    */

    'llm' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MODBOT_LLM_MODEL', 'claude-opus-5-5'),
        'effort' => env('MODBOT_LLM_EFFORT', 'medium'),
        'max_tokens' => (int) env('MODBOT_LLM_MAX_TOKENS', 16000),
        // Comments per request. Comments are grouped per article; larger
        // articles are split into chunks of this size.
        'batch_size' => (int) env('MODBOT_LLM_BATCH_SIZE', 400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting
    |--------------------------------------------------------------------------
    */

    'report' => [
        'max_items' => (int) env('MODBOT_REPORT_MAX_ITEMS', 50),
        'cron' => env('MODBOT_REPORT_CRON', '0 8,20 * * *'),
    ],

    'slack' => [
        'enabled' => (bool) env('SLACK_ENABLED', true),
        'bot_token' => env('SLACK_BOT_TOKEN'),
        'channel' => env('SLACK_CHANNEL', '#development'),
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

];
