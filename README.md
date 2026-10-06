# GeenStijl ModBot

Moderation assistant for GeenStijl comments. On a schedule it fetches recent comments, has an AI model flag the ones a moderator should look at, and posts a report to Slack (main message + thread, grouped per article) and by email (AhaSend). It only flags; it never removes anything.

It comes with an admin panel to see runs, flags and comments, start runs, manage schedules, prompts and settings.

Successor to the Make.com scenario "Geenstijl Comments AI moderation report".

---

## Installation (Docker, step by step)

This installs ModBot on a server (or your own machine) with Docker Compose: a Postgres database, the scheduler and the admin panel.

ModBot only makes **outgoing** connections (GeenStijl, the AI provider, Slack, AhaSend), so it works behind a firewall without port forwarding. Only the admin panel needs to be reachable for you, see [Reaching the admin behind a firewall](#reaching-the-admin-behind-a-firewall).

### 1. Requirements

- Docker with the Compose plugin (`docker compose version` should work)
- Git, with access to `github.com/arifje/geenstijl-modbot`
- API keys/tokens:
  - **AI provider**: an Anthropic API key (console.anthropic.com → API Keys), or OpenAI / another provider
  - **Slack**: a bot token (`xoxb-…`) with the `chat:write` scope (add `chat:write.customize` to post as "ModBot"). Invite the bot to the channel: `/invite @modbot`
  - **AhaSend**: API key and account id (used for email reports *and* login codes)

### 2. Clone the repository

```bash
git clone git@github.com:arifje/geenstijl-modbot.git
cd geenstijl-modbot
```

### 3. Create the configuration

```bash
cp .env.example .env
```

Open `.env` and fill in at least:

| Setting | What |
|---|---|
| `APP_URL` | The address you will open the admin on, e.g. `http://192.168.1.20:8080` |
| `MODBOT_PORT` | Port of the admin on this machine (default `8080`) |
| `DB_PASSWORD` | Choose a password for the database |
| `ANTHROPIC_API_KEY` | AI key (or configure another provider, see [AI providers](#ai-providers)) |
| `SLACK_BOT_TOKEN`, `SLACK_CHANNEL` | Slack bot token and channel. Keep the quotes around `"#channel"` |
| `AHASEND_API_KEY`, `AHASEND_ACCOUNT_ID` | AhaSend credentials |
| `AHASEND_RECIPIENTS` | Who receives the email report, e.g. `Arjan <arjan@example.com>, mods@example.com` |

`APP_KEY` is generated in step 5.

### 4. Build the image

```bash
docker compose --profile app build
```

### 5. Generate the application key

```bash
docker compose --profile app run --rm --no-deps web php artisan key:generate --show
```

Copy the output (`base64:…`) into `.env` as `APP_KEY=base64:…`.

### 6. Start everything

```bash
docker compose --profile app up -d
```

This starts the database, runs the database migrations, and then starts the scheduler and the admin. Check that everything is up:

```bash
docker compose --profile app ps
```

`db`, `scheduler` and `web` should be running (`web` becomes `healthy` after ~30 seconds); `migrate` has exited with code 0.

### 7. Create your admin user

```bash
docker compose --profile app exec web php artisan make:filament-user
```

Enter your name, email address and a password. Use a real email address: login codes are sent there.

### 8. Log in

Open `APP_URL` + `/admin`, e.g. `http://192.168.1.20:8080/admin`. Log in with your email and password; you then receive a 6-digit code by email (valid for 10 minutes).

### 9. Test the connections

In the admin, go to **Settings** and use:

- **Test AI connection** (AI provider section)
- **Send test message** (Slack section)
- **Send test email** (Email section)

Each shows a clear error with a hint when something is wrong (wrong key, bot not in channel, …).

### 10. First run

Go to **Runs → Run now**, switch on **Dry run** and submit. The run starts within a minute and analyses the comments of the last 12 hours without sending anything. Open the run to see the flags and a preview of the Slack message; from there you can still send it to Slack or by email.

Check **Schedules**: the default schedule runs at 08:00 and 20:00 (Europe/Amsterdam). Make sure it is enabled.

### 11. Optional: log in without passwords

Under **Settings → Login**, switch off **Password login**. Users then log in with only their email address and an emailed code. Test the email first. If login emails ever stop arriving, switch passwords back on from the server:

```bash
docker compose --profile app exec web php artisan modbot:password-login on
```

---

## Day-to-day operations

All commands are run in the project directory.

| Task | Command |
|---|---|
| Status | `docker compose --profile app ps` |
| Logs | `docker compose --profile app logs -f scheduler web` |
| Stop | `docker compose --profile app down` (data is kept) |
| Start | `docker compose --profile app up -d` |
| Run from the command line | `docker compose --profile app exec scheduler php artisan modbot:run --dry-run` |
| Add a user | `docker compose --profile app exec web php artisan make:filament-user` |

### Change the port

Set `MODBOT_PORT` in `.env` (and update `APP_URL`), then:

```bash
docker compose --profile app up -d
```

Only the `web` container is recreated.

### Update to a new version

```bash
git pull
docker compose --profile app up -d --build
```

Migrations run automatically on start.

### Backup and restore the database

```bash
docker compose exec db pg_dump -U modbot modbot > modbot-backup.sql
```

```bash
docker compose exec -T db psql -U modbot modbot < modbot-backup.sql
```

### Reaching the admin behind a firewall

No port forwarding is needed for ModBot itself. To reach the admin:

1. **Same network / VPN**: open `http://<server-ip>:<MODBOT_PORT>/admin`.
2. **SSH tunnel**: `ssh -L 8080:localhost:<MODBOT_PORT> user@server`, then open http://localhost:8080/admin.
3. **Tailscale**: install it on the server and your laptop (`curl -fsSL https://tailscale.com/install.sh | sh && sudo tailscale up`) and open `http://<tailscale-name>:<MODBOT_PORT>/admin`. No firewall changes needed.
4. **Port forward** (pfSense: Firewall → NAT → Port Forward): only if you must; restrict the source IP and put HTTPS in front.

The admin is excluded from search engines (`X-Robots-Tag: noindex` on every response) and requires an emailed code on every login.

---

## Admin

The admin panel at `/admin`:

- **Dashboard**: flags in the last 24 hours, last and next run, AI cost this month, latest flags.
- **Runs**: every run with status, trigger (schedule / manual / CLI), counts, tokens and cost. A run's page shows the sentiment, a Slack preview and its flags, and can (re)send the report. **Run now** queues a run (optionally a dry run); a queued run can be cancelled.
- **Flags**, **Comments**, **Articles**: everything that was synced and analysed, searchable and filterable, with links to the comment on GeenStijl.
- **Schedules**: cron schedules (window, Slack channel, email on/off). Changes apply within a minute.
- **Prompts**: edit the moderation and summary prompts. Every save is a new version; older versions can be viewed and reactivated, and each version shows how many runs and flags it produced.
- **Settings**: Slack channel, email recipients, AI provider and model, filters, login mode, retention. Stored in the database; they override `.env`. Secrets (keys, tokens) stay in `.env`.
- **Users**: who can log in.

## AI providers

Choose the provider under **Settings → AI provider**. Each provider keeps its own model, so switching is one click; the next run uses it.

| Provider | Key in `.env` | Notes |
|---|---|---|
| Anthropic (Claude) | `ANTHROPIC_API_KEY` | Default. `claude-opus-5-5` (best) or `claude-sonnet-5-5` (cheaper) |
| OpenAI | `OPENAI_API_KEY` | Any model with structured outputs |
| OpenAI-compatible | `LLM_COMPATIBLE_API_KEY` (optional for Ollama) | Set the base URL in the admin: Gemini `https://generativelanguage.googleapis.com/v1beta/openai`, Mistral `https://api.mistral.ai/v1`, Groq `https://api.groq.com/openai/v1`, Ollama `http://<host>:11434/v1` |

After adding a key to `.env`, restart: `docker compose --profile app up -d`.

## How it works

```
modbot:run (scheduler, per schedule in the admin; or "Run now")
 ├─ sync      articles published in the last MODBOT_ARTICLE_LOOKBACK_HOURS + their comments → Postgres
 ├─ analyse   approved, visible comments from the window that were not analysed before
 │            → one AI request per article (with parent comments as context), structured JSON output
 │            → flags are validated (comment id must exist, quote must appear in the comment) and stored
 ├─ summarise per-article summaries → "Sfeer en sentiment"
 └─ report    built from the database → Slack + email
```

- **Structured output.** The model returns JSON; links, dates, escaping and layout are rendered in code, so URLs can't be invented and formatting doesn't depend on the model.
- **Stored data.** Comments and verdicts live in Postgres, so a comment is analysed only once. Every run and flag records the provider, model and prompt version.
- **Removed comments drop out.** Comments a moderator removed in the meantime are not reported.

## Configuration reference

Secrets and defaults are environment variables, see [`.env.example`](.env.example) and [`config/modbot.php`](config/modbot.php). Most non-secret settings can be changed in the admin under **Settings**.

| Variable | Default | |
|---|---|---|
| `MODBOT_PORT` | `8080` | Port of the admin (Docker Compose) |
| `MODBOT_LLM_PROVIDER` | `anthropic` | `anthropic`, `openai`, `openai_compatible` |
| `MODBOT_LLM_EFFORT` | `medium` | `low`, `medium`, `high`, or `default` for models without effort support |
| `MODBOT_WINDOW_HOURS` | `12` | Default window for runs |
| `MODBOT_ARTICLE_LOOKBACK_HOURS` | `24` | Articles published in this period are checked. GeenStijl closes comments 12h after publication |
| `MODBOT_APPROVAL_FILTER` | `unapproved` | Which comments to analyse: `unapproved` (waiting for a moderator), `approved` or `all` |
| `MODBOT_REPORT_CRON` | `0 8,20 * * *` | Only seeds the first schedule |
| `MODBOT_PASSWORD_LOGIN` | `true` | `false`: log in with only an emailed code |
| `MAIL_MAILER` | `ahasend` | `log` writes mails (login codes) to `storage/logs` instead |
| `MODBOT_RETENTION_DAYS` | `90` | `modbot:prune` runs daily |

## Local development

Requirements: PHP 8.3+, Composer, Docker.

```bash
composer install
cp .env.example .env
```

In `.env` set `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost:8000` and `MAIL_MAILER=log` (login codes then appear in `storage/logs/laravel.log`). Then:

```bash
php artisan key:generate
docker compose up -d db
php artisan migrate
php artisan db:seed
php artisan serve
```

The admin runs at http://localhost:8000/admin; `db:seed` creates a local user (see `database/seeders/DatabaseSeeder.php`). Run the scheduler (scheduled runs and "Run now") in a second terminal with `php artisan schedule:work`.

```bash
php artisan modbot:run --dry-run              # print the Slack report, send nothing
php artisan modbot:run --no-email             # post to Slack only
php artisan modbot:run --reanalyze            # analyse the window again (e.g. after a prompt change)
php artisan test
vendor/bin/pint
```

## Kubernetes

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
  --set config.DB_HOST=<postgres host> \
  --set config.AHASEND_RECIPIENTS="..." \
  --set config.APP_URL=https://modbot.example.com \
  --set ingress.enabled=true --set ingress.host=modbot.example.com

# First admin user
kubectl exec -it deploy/modbot-modbot-web -- php artisan make:filament-user
```

If the GHCR package is private, add an image pull secret (`imagePullSecrets` in the chart values).

## Roadmap

1. ~~Parity with Make, with stored data~~
1. ~~Admin panel: runs, flags, comments, schedules, settings, prompts~~
1. ~~Multiple AI providers; email login codes~~
1. Incremental runs every 10–15 minutes, immediate Slack alert for high priority
1. Slack buttons (correct / incorrect / handled) for moderator feedback; author history in flags
1. Eval set from moderator feedback to test prompt/model changes
