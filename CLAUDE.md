# GeenStijl ModBot

Laravel 13 console app (no web UI yet). Entry point: `php artisan modbot:run` (app/Console/Commands/RunModeration.php) → `App\Services\ModerationPipeline`.

- `app/Services/GeenStijl` — API client + sync into `articles`/`comments`. GeenStijl timestamps are Europe/Amsterdam without offset; stored as UTC.
- `app/Services/Analysis` — `LanguageModel` interface (Anthropic implementation), `CommentAnalyzer` (per-article requests, structured output, validation), prompts in `resources/prompts/*.nl.md`.
- `app/Services/Reporting` — report built from DB; Slack (mrkdwn, escape user content) and AhaSend email (Blade views in `resources/views/emails`).
- Config: `config/modbot.php`, all via env.

Conventions:
- User-facing output (Slack, email, prompts) is Dutch; code and comments English.
- Never let the model produce URLs, dates or Slack markup; render those in code.
- Tests: `php artisan test` (sqlite in memory, HTTP faked, `Tests\Fakes\FakeLanguageModel`). Format with `vendor/bin/pint`.
- Local DB: `docker compose up -d db` (Postgres on port 54329).
