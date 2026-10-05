# GeenStijl ModBot

Laravel 13 app: scheduler + Filament 5 admin at /admin. Entry point: `php artisan modbot:run` (app/Console/Commands/RunModeration.php) → `App\Services\ModerationPipeline`.

- `app/Services/GeenStijl` — API client + sync into `articles`/`comments`. GeenStijl timestamps are Europe/Amsterdam without offset; stored as UTC.
- `app/Services/Analysis` — `LanguageModel` interface (Anthropic implementation), `CommentAnalyzer` (per-article requests, structured output, validation), prompts in `resources/prompts/*.nl.md`.
- `app/Services/Reporting` — report built from DB; Slack (mrkdwn, escape user content) and AhaSend email (Blade views in `resources/views/emails`).
- `app/Services/ModerationRunner.php` — runs + delivers; shared by CLI (`modbot:run`), scheduler (schedules table, `routes/console.php`) and admin (`RunModerationJob`, queued; the scheduler runs `queue:work --stop-when-empty` every minute).
- `app/Filament` — admin resources (Runs, Flags, Comments, Articles, Schedules, Users) and the Settings page.
- Config: `config/modbot.php` via env; `App\Support\Settings` overrides keys from the `settings` table on boot.

Conventions:
- User-facing output (Slack, email, prompts) is Dutch; code and comments English.
- Never let the model produce URLs, dates or Slack markup; render those in code.
- Tests: `php artisan test` (sqlite in memory, HTTP faked, `Tests\Fakes\FakeLanguageModel`). Format with `vendor/bin/pint`.
- Local DB: `docker compose up -d db` (Postgres on port 54329). Admin: `php artisan serve`, user from `php artisan db:seed`.
- Admin UI is English; report output (Slack/email/prompts) is Dutch.
- Docker image (FrankenPHP) has two roles: `schedule:work` (default CMD) and `frankenphp php-server` (web).
