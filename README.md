# GeenStijl ModBot

Moderation assistant for GeenStijl comments. Twice a day it fetches recent comments, has Claude flag the ones a moderator should look at, and posts a report to Slack (main message + thread) and by email (AhaSend). It flags only; it never removes anything.

Successor to the Make.com scenario "Geenstijl Comments AI moderation report".

## Admin

The admin panel at `/admin` (Filament) shows:

- **Dashboard**: flags in the last 24 hours, last and next run, Claude cost this month, latest flags.
- **Runs**: every run with status, trigger (schedule / manual / CLI), counts, tokens and cost. A run's page shows the sentiment, a Slack preview, its flags, and buttons to (re)send the report to Slack or by email. **Run now** queues a run, optionally as a dry run.
- **Flags**, **Comments**, **Articles**: everything that was synced and analysed, searchable and filterable, with links to the comment on GeenStijl.
- **Schedules**: cron schedules for the report (window, Slack channel, email on/off). The scheduler reads them every minute, so changes need no restart.
- **Settings**: default Slack channel, email recipients, Claude model/effort, filters, retention. Stored in the database; they override the environment defaults. Secrets stay in the environment.
- **Users**: who can log in.

## How it works

```
modbot:run (Laravel scheduler, per schedule in the admin)
 ├─ sync      articles published in the last MODBOT_ARTICLE_LOOKBACK_HOURS + their comments → Postgres
 ├─ analyse   approved, visible comments from the last MODBOT_WINDOW_HOURS that were not analysed before
 │            → one Claude request per article (with parent comments as context), structured JSON output
 │            → flags are validated (comment id must exist, quote must appear in the comment) and stored
 ├─ summarise per-article summaries → "Sfeer en sentiment"
 └─ report    built from the database → Slack + email
```

Compared with the Make scenario:

- **Structured output.** Claude returns JSON; links, dates, escaping and layout are rendered in code, so URLs can't be invented and the Slack/email formatting doesn't depend on the model.
- **Stored data.** Comments and verdicts live in Postgres, so a comment is analysed only once and later features (feedback, author history, trends) can build on it.
- **Paging.** Articles are paged until the lookback period is covered, instead of a fixed 15.
- **Removed comments drop out.** Comments a moderator removed in the meantime are no longer reported.

The prompt lives in [`resources/prompts/moderation.nl.md`](resources/prompts/moderation.nl.md). Every verdict stores the prompt version (a hash of the file) and model.

## Local development

Requirements: PHP 8.3+, Composer, Docker.

```bash
composer install
cp .env.example .env        # then fill in the tokens
php artisan key:generate
docker compose up -d db     # Postgres on localhost:54329
php artisan migrate
php artisan db:seed         # local admin user, see database/seeders/DatabaseSeeder.php
php artisan serve           # admin at http://localhost:8000/admin
php artisan schedule:work   # scheduler + queue worker for "Run now"
```

Run it:

```bash
php artisan modbot:run --dry-run              # print the Slack report, send nothing
php artisan modbot:run --no-email             # post to SLACK_CHANNEL only
php artisan modbot:run --hours=6 --channel=#development
php artisan modbot:run --reanalyze            # analyse the window again (e.g. after a prompt change)
php artisan test
```

Run the scheduler and admin in Docker like production does: `docker compose --profile app up --build` (admin on http://localhost:8080/admin).

## Configuration

Secrets and defaults are environment variables; see [`.env.example`](.env.example) and [`config/modbot.php`](config/modbot.php). Most non-secret settings can be changed in the admin under **Settings**, which overrides the environment.

| Variable | Default | |
|---|---|---|
| `ANTHROPIC_API_KEY` | | Claude API key |
| `MODBOT_LLM_MODEL` / `MODBOT_LLM_EFFORT` | `claude-opus-5-5` / `medium` | |
| `MODBOT_WINDOW_HOURS` | `12` | Comments posted in this window are reported |
| `MODBOT_ARTICLE_LOOKBACK_HOURS` | `24` | Articles published in this period are checked. GeenStijl closes comments 12h after publication; raise this if that changes |
| `MODBOT_ONLY_APPROVED` | `true` | Same as Make: skip comments that are not approved yet |
| `MODBOT_REPORT_CRON` | `0 8,20 * * *` | Only seeds the first schedule; manage schedules in the admin |
| `SLACK_BOT_TOKEN` / `SLACK_CHANNEL` | / `#development` | Bot needs `chat:write` (+ `chat:write.customize` for username/icon) and must be in the channel |
| `AHASEND_API_KEY` / `AHASEND_ACCOUNT_ID` / `AHASEND_RECIPIENTS` | | Recipients: comma separated, `Name <email>` allowed |
| `MODBOT_RETENTION_DAYS` | `90` | `modbot:prune` runs daily |

## Deployment (Kubernetes)

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs the tests and pushes the image to `ghcr.io/arifje/geenstijl-modbot` on every push to `main` (tags: commit sha and `latest`; a `v1.2.3` git tag also publishes `1.2.3`, which is the chart's default image tag).

The Helm chart in [`deploy/helm/modbot`](deploy/helm/modbot) runs one image in two roles:

- `scheduler`: single replica, `php artisan schedule:work` (scheduled runs + queue worker for runs started from the admin); an init container runs the migrations.
- `web`: FrankenPHP serving the admin on port 8080, behind a Service and an optional Ingress.

Postgres is external.

```bash
kubectl create secret generic modbot-secrets \
  --from-literal=APP_KEY=base64:... \
  --from-literal=DB_USERNAME=modbot --from-literal=DB_PASSWORD=... \
  --from-literal=ANTHROPIC_API_KEY=... --from-literal=SLACK_BOT_TOKEN=... \
  --from-literal=AHASEND_API_KEY=... --from-literal=AHASEND_ACCOUNT_ID=...

helm upgrade --install modbot deploy/helm/modbot \
  --set existingSecret=modbot-secrets \
  --set image.tag=<sha> \
  --set config.DB_HOST=<postgres host> \
  --set config.AHASEND_RECIPIENTS="..." \
  --set config.APP_URL=https://modbot.example.com \
  --set ingress.enabled=true --set ingress.host=modbot.example.com

# First admin user
kubectl exec -it deploy/modbot-modbot-web -- php artisan make:filament-user
```

## Roadmap

1. ~~Parity with Make, with stored data~~
1. ~~Admin panel: runs, flags, comments, schedules, settings~~
2. Incremental runs every 10–15 minutes, immediate Slack alert for high priority, digest from stored verdicts
3. Slack buttons (correct / incorrect / handled) for moderator feedback; author history in flags
4. Eval set from moderator feedback to test prompt/model changes; dashboard
