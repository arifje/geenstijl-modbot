# GeenStijl ModBot

Moderation assistant for GeenStijl comments. Twice a day it fetches recent comments, has Claude flag the ones a moderator should look at, and posts a report to Slack (main message + thread) and by email (AhaSend). It flags only; it never removes anything.

Successor to the Make.com scenario "Geenstijl Comments AI moderation report".

## How it works

```
modbot:run (Laravel scheduler, MODBOT_REPORT_CRON, Europe/Amsterdam)
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
```

Run it:

```bash
php artisan modbot:run --dry-run              # print the Slack report, send nothing
php artisan modbot:run --no-email             # post to SLACK_CHANNEL only
php artisan modbot:run --hours=6 --channel=#development
php artisan modbot:run --reanalyze            # analyse the window again (e.g. after a prompt change)
php artisan test
```

Run the scheduler in Docker like production does: `docker compose --profile app up --build`.

## Configuration

All settings are environment variables; see [`.env.example`](.env.example) and [`config/modbot.php`](config/modbot.php).

| Variable | Default | |
|---|---|---|
| `ANTHROPIC_API_KEY` | | Claude API key |
| `MODBOT_LLM_MODEL` / `MODBOT_LLM_EFFORT` | `claude-opus-5-5` / `medium` | |
| `MODBOT_WINDOW_HOURS` | `12` | Comments posted in this window are reported |
| `MODBOT_ARTICLE_LOOKBACK_HOURS` | `24` | Articles published in this period are checked. GeenStijl closes comments 12h after publication; raise this if that changes |
| `MODBOT_ONLY_APPROVED` | `true` | Same as Make: skip comments that are not approved yet |
| `MODBOT_REPORT_CRON` | `0 8,20 * * *` | Europe/Amsterdam |
| `SLACK_BOT_TOKEN` / `SLACK_CHANNEL` | / `#development` | Bot needs `chat:write` (+ `chat:write.customize` for username/icon) and must be in the channel |
| `AHASEND_API_KEY` / `AHASEND_ACCOUNT_ID` / `AHASEND_RECIPIENTS` | | Recipients: comma separated, `Name <email>` allowed |
| `MODBOT_RETENTION_DAYS` | `90` | `modbot:prune` runs daily |

## Deployment (Kubernetes)

CI ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) runs the tests and pushes the image to `ghcr.io/arifje/geenstijl-modbot` on every push to `main` (tags: commit sha and `latest`; a `v1.2.3` git tag also publishes `1.2.3`, which is the chart's default image tag).

The Helm chart in [`deploy/helm/modbot`](deploy/helm/modbot) runs a single-replica Deployment with `php artisan schedule:work`; an init container runs the migrations. Postgres is external.

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
  --set config.AHASEND_RECIPIENTS="..."
```

## Roadmap

1. ~~Parity with Make, with stored data~~
2. Incremental runs every 10–15 minutes, immediate Slack alert for high priority, digest from stored verdicts
3. Slack buttons (correct / incorrect / handled) for moderator feedback; author history in flags
4. Eval set from moderator feedback to test prompt/model changes; dashboard
