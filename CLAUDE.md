# GeenStijl ModBot

Laravel 13 app: scheduler + Filament 5 admin at /admin. Entry point: `php artisan modbot:run` (app/Console/Commands/RunModeration.php) → `App\Services\ModerationPipeline`.

- `app/Services/GeenStijl` — API client + sync into `articles`/`comments`. GeenStijl timestamps are Europe/Amsterdam without offset; stored as UTC.
- `app/Services/Analysis` — `LanguageModel` interface with Anthropic, OpenAI (Responses API) and OpenAI-compatible (chat completions) implementations, built by `LanguageModelFactory` from `modbot.llm.provider`; `CommentAnalyzer` (per-article requests, structured output, validation). Prompts are versioned in the `prompts`/`prompt_versions` tables (admin → Prompts); `resources/prompts/*.nl.md` are the defaults that seed version 1.
- `app/Services/Reporting` — report built from DB; Slack (mrkdwn, escape user content) and AhaSend email (Blade views in `resources/views/emails`).
- `app/Services/ModerationRunner.php` — runs + delivers; shared by CLI (`modbot:run`), scheduler (schedules table, `routes/console.php`) and admin. All runs are queued (`RunModerationJob`; the scheduler queues schedules with `modbot:run --queue`) and run by the `worker` container (`queue:work`, one at a time; `modbot:recover` on start).
- `app/Filament` — admin resources (Runs, Flags, Comments, Articles, Schedules, Prompts, Users), the Settings page and a custom login (`Pages/Auth/Login.php`: password + emailed code, or emailed code only when `modbot.auth.password_login` is off).
- Mail: `MAIL_MAILER=ahasend` uses `app/Mail/AhaSendTransport.php` (AhaSend API). Login codes (`app/Notifications/LoginCode.php`) are sent synchronously, never queued.
- Config: `config/modbot.php` via env; `App\Support\Settings` overrides keys from the `settings` table on boot.

Conventions:
- User-facing output (Slack, email, prompts) is Dutch; code and comments English.
- Never let the model produce URLs, dates or Slack markup; render those in code.
- Tests: `php artisan test` (sqlite in memory, HTTP faked, `Tests\Fakes\FakeLanguageModel`). Format with `vendor/bin/pint`.
- Local DB: `docker compose up -d db` (Postgres on port 54329). Admin: `php artisan serve`, user from `php artisan db:seed`, `MAIL_MAILER=log` so login codes land in `storage/logs/laravel.log`.
- Admin UI is English; report output (Slack/email/prompts) is Dutch.
- Docker image (FrankenPHP) has three roles: `schedule:work` (default CMD), `queue:work` (worker) and `frankenphp php-server` (web).
