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

Check **Schedules**. There are two defaults:

- **Moderatierapport**: analyses new comments and sends the report at 08:00 and 20:00 (Europe/Amsterdam). Enabled.
- **Analyse (no report)**: analyses new comments every 15 minutes without sending anything. Disabled by default.

Moderators approve comments in batches every few hours, and ModBot analyses comments that are *not yet approved*. Enable **Analyse (no report)** to judge comments while they wait for approval; the 08:00/20:00 report then lists everything found in between that is still waiting. Each comment is analysed only once, so this costs about the same as analysing at report time.

### 11. Optional: log in without passwords

Under **Settings → Login**, switch off **Password login**. Users then log in with only their email address and an emailed code. Test the email first. If login emails ever stop arriving, switch passwords back on from the server:

```bash
docker compose --profile app exec web php artisan modbot:password-login on
```

Failed logins are capped: after 5 failed attempts (wrong password, wrong code or unknown address) an email address is locked for 15 minutes, and one IP address after 20. Both numbers can be changed under **Settings → Login**; lockouts appear under **System → Logs**.

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
| Update | `bin/update` (see below) |

### Change the port

Set `MODBOT_PORT` in `.env` (and update `APP_URL`), then:

```bash
docker compose --profile app up -d
```

Only the `web` container is recreated.

### Update to a new version

**System → About & updates** shows whether PHP, Laravel, PostgreSQL and the packages need updates. Those are applied by a new ModBot release (dependencies) or by `bin/update`.


```bash
bin/update
```

This fetches the latest version, shows what changed, warns about new settings in `.env.example` that are missing from your `.env`, builds the image, restarts (migrations run automatically), waits until the admin is healthy and removes old ModBot images. If the build fails, the running version keeps running.

To go to a specific version, or back to an earlier one: `bin/update v1.6.2`.

### Cleanup

Every night at 04:00 a cleanup removes comments, flags and runs older than 90 days, log entries older than 14 days, old failed jobs and expired cache entries. Both periods can be changed under **Settings → Report & data**, which also has **Run cleanup now**. Log entries can be cleared by hand on the **Logs** page.

Log files (`LOG_STACK=daily`) are kept for `LOG_DAILY_DAYS` days, and Docker keeps at most 3 × 10 MB of output per container.

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
4. **Public HTTPS address without port forwarding** (others only need a browser):
   - **Tailscale Funnel**: `sudo tailscale funnel --bg <MODBOT_PORT>` gives `https://<server>.<tailnet>.ts.net`.
   - **Cloudflare Tunnel**: your own domain, e.g. `https://modbot.example.com`, via `cloudflared`.

   Set `APP_URL` to the https address and `MODBOT_PORT=127.0.0.1:<port>`, so the admin is only reachable through the tunnel.
5. **Port forward** (pfSense: Firewall → NAT → Port Forward): only if you must; restrict the source IP and put HTTPS in front.

The admin is excluded from search engines (`X-Robots-Tag: noindex` on every response) and requires an emailed code on every login.

---

## Admin

The admin panel at `/admin`:

- **Dashboard**: flags in the last 24 hours, last and next run, AI cost this month, latest flags.
- **Runs**: every run with status, trigger (schedule / manual / CLI), counts, tokens and cost. A run's page shows the sentiment, a Slack preview and its flags, and can (re)send the report. **Run now** queues a run (optionally a dry run); a queued run can be cancelled.
- **Flags**, **Comments**, **Articles**: everything that was synced and analysed, searchable and filterable, with links to the comment on GeenStijl.
- **Schedules**: when to run, chosen in plain terms (every few minutes, every few hours, every day at set times, or on certain days; a cron expression under "Advanced" for anything else), with an article window (articles published in the last X hours) and a comment window (comments posted in the last X hours), Slack channel and email on/off. A schedule with Slack and email off only analyses (e.g. every 15 minutes); a report schedule then reports everything in its window. Changes apply within a minute.
- **Prompts**: edit the moderation and summary prompts. Every save is a new version; older versions can be viewed and reactivated, and each version shows how many runs and flags it produced.
- **Settings**: Slack channel, email recipients, AI provider, model, API key (stored encrypted; replaces the key from `.env`), max output tokens, comments per request, model prices, filters, login mode, retention. Stored in the database; they override `.env`. Each provider has a link to its API key page.
- **Users**: who can log in.
- **System → About & updates**: installed versions of ModBot, PHP, Laravel, PostgreSQL, FrankenPHP, Alpine and the main PHP packages, with the latest versions, end-of-support dates and known security issues (checked daily, or with "Check now"). The menu shows a count when something needs attention; "Email maintainer" sends the list to the maintainer (pixio, info@pixio.media).
- **System → Queue**: whether the scheduler is running (the menu shows a red **!** when it has not been seen for 3 minutes), the queue worker, waiting and running jobs (a waiting run can be cancelled), and failed jobs (view the error, retry, delete). When scheduled runs don't happen, look here first.

  The queue repairs itself. When the scheduler container starts, it clears the locks a previous container left behind, and marks runs that were interrupted by the restart as failed, so they can be retried. Every minute it also checks the queue. If jobs have been waiting 3 minutes while no worker is running, it releases the old worker's lock. A queued run whose job is gone is marked failed after 10 minutes. Both show up under **System → Logs**. A blocked worker can also be released by hand with **Release worker**.
- **System → Logs**: application, queue, scheduler and run logs.

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
 ├─ sync      articles published in the article window + their comments → Postgres
 ├─ analyse   comments from the comment window, on articles from the article window,
 │            matching the approval filter and not analysed before
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
| `MODBOT_LLM_MAX_TOKENS` | `32000` | Max length of one AI answer; when hit, the batch is split in two and retried |
| `MODBOT_LLM_BATCH_SIZE` | `400` | Comments per AI request |
| `MODBOT_LLM_EFFORT` | `medium` | `low`, `medium`, `high`, or `default` for models without effort support |
| `MODBOT_WINDOW_HOURS` | `12` | Default comment window: comments posted in the last X hours |
| `MODBOT_ARTICLE_LOOKBACK_HOURS` | `24` | Default article window: articles published in the last X hours. GeenStijl closes comments 12h after publication |
| `MODBOT_APPROVAL_FILTER` | `unapproved` | Which comments to analyse: `unapproved` (waiting for a moderator), `approved` or `all` |
| `MODBOT_REPORT_CRON` | `0 8,20 * * *` | Only seeds the first schedule |
| `MODBOT_PASSWORD_LOGIN` | `true` | `false`: log in with only an emailed code |
| `MODBOT_MAX_FAILED_LOGINS` | `5` | Failed logins before an email address is locked (an IP address: 4×) |
| `MODBOT_LOGIN_LOCKOUT_MINUTES` | `15` | How long a lockout lasts |
| `MAIL_MAILER` | `ahasend` | `log` writes mails (login codes) to `storage/logs` instead |
| `MODBOT_RETENTION_DAYS` | `90` | Days to keep comments, flags and runs (cleanup runs daily at 04:00) |
| `MODBOT_LOG_RETENTION_DAYS` | `14` | Days to keep log entries in the admin |
| `LOG_STACK` | `daily,database` | Where logs go: `daily` files, `database` (admin → Logs), `stderr` (container output) |

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
php artisan modbot:run --article-hours=6 --hours=3   # articles of the last 6h, comments of the last 3h
php artisan test
vendor/bin/pint
```

## Deploying on Kubernetes

This section is for the party that hosts ModBot in a Kubernetes cluster. Questions: pixio, info@pixio.media.

### What gets deployed

| Part | What it is | Notes |
|---|---|---|
| `modbot-scheduler` (Deployment) | Runs the schedules, the analyses and the queue (`php artisan schedule:work`). An init container runs the database migrations first. | **Exactly 1 replica** (strategy `Recreate`). |
| `modbot-web` (Deployment + Service) | The admin panel at `/admin` (FrankenPHP on port 8080). Health check: `/up`. | Can run with more replicas. |
| Ingress | HTTPS access to the admin. | Optional; only the admin needs to be reachable. |
| PostgreSQL 17 | The only state: comments, flags, runs, settings, users, logs. | Not part of the chart: managed, existing, or CloudNativePG (below). |

The containers run as a non-root user (uid 82), need no volumes and keep no local state. The image is `ghcr.io/arifje/geenstijl-modbot:<version>`; CI publishes a version for every release tag. The Helm chart is in [`deploy/helm/modbot`](deploy/helm/modbot); pixio can also provide it as a packaged `.tgz` (`helm package deploy/helm/modbot`).

**Network.** Nothing calls ModBot from outside except users of the admin. Outgoing HTTPS (443) is needed to:

| Host | For |
|---|---|
| `content-api.prod.platform.gstech.nl`, `comments-api.prod.platform.gstech.nl` | GeenStijl articles and comments |
| `api.anthropic.com` (or the OpenAI / other AI provider in use) | the analysis |
| `slack.com` | Slack reports |
| `api.ahasend.com` | email reports and login codes |
| `endoflife.date`, `repo.packagist.org`, `packagist.org`, `api.github.com` | the daily update check (System → About & updates) |

Admin users' browsers also load a font from `fonts.bunny.net`.

**Resources.** About 50m CPU and 150–300 MB memory per pod (the values file sets requests and limits). The database stays small: well under 1 GB with the default retention of 90 days.

### Requirements

- Kubernetes 1.27 or newer, `kubectl` and Helm 3
- An ingress controller and TLS certificates (e.g. ingress-nginx + cert-manager)
- PostgreSQL 17: a managed database, an existing server, or [CloudNativePG](https://cloudnative-pg.io) in the cluster
- From pixio: a token to pull the image from ghcr.io, and the API keys and tokens (Anthropic, Slack, AhaSend)

### 1. Namespace

```bash
kubectl create namespace modbot
```

### 2. Database

**Option A — managed or existing PostgreSQL 17.** Create a database and a user:

```sql
CREATE USER modbot WITH PASSWORD '<strong password>';
CREATE DATABASE modbot OWNER modbot;
```

Use its hostname as `DB_HOST` in step 5, and the password in step 4.

**Option B — CloudNativePG in the cluster** (with the operator installed). Save as `modbot-db.yaml`:

```yaml
apiVersion: postgresql.cnpg.io/v1
kind: Cluster
metadata:
  name: modbot-db
  namespace: modbot
spec:
  instances: 2
  imageName: ghcr.io/cloudnative-pg/postgresql:17
  storage:
    size: 5Gi
  bootstrap:
    initdb:
      database: modbot
      owner: modbot
```

```bash
kubectl apply -f modbot-db.yaml
kubectl -n modbot get cluster modbot-db          # wait for "Cluster in healthy state"
```

The database host is then `modbot-db-rw`. CloudNativePG generates the password in the Secret `modbot-db-app`:

```bash
kubectl -n modbot get secret modbot-db-app -o jsonpath='{.data.password}' | base64 -d
```

Set up backups for this database (CloudNativePG supports scheduled backups to object storage); it is the only state of ModBot.

### 3. Access to the image

The image is private. With the token from pixio:

```bash
kubectl -n modbot create secret docker-registry ghcr-pull \
  --docker-server=ghcr.io --docker-username=arifje --docker-password='<token from pixio>'
```

### 4. Secrets

Generate an application key once. **Keep it, and never change it**: it encrypts sessions and the API keys that can be saved in the admin.

```bash
echo "base64:$(openssl rand -base64 32)"
```

Create the Secret with that key, the database credentials and the keys from pixio:

```bash
kubectl -n modbot create secret generic modbot-secrets \
  --from-literal=APP_KEY='base64:...' \
  --from-literal=DB_USERNAME=modbot \
  --from-literal=DB_PASSWORD='<database password>' \
  --from-literal=ANTHROPIC_API_KEY='<from pixio>' \
  --from-literal=SLACK_BOT_TOKEN='<from pixio>' \
  --from-literal=AHASEND_API_KEY='<from pixio>' \
  --from-literal=AHASEND_ACCOUNT_ID='<from pixio>'
```

If your cluster manages secrets with Sealed Secrets or External Secrets, create a Secret named `modbot-secrets` with these keys that way. The chart only reads it.

### 5. Values

Copy [`deploy/helm/modbot/values-production.example.yaml`](deploy/helm/modbot/values-production.example.yaml) to `values-production.yaml` and fill in the `<...>` placeholders:

- `image.tag`: the version to install (e.g. `1.13.1`)
- `config.APP_URL` and `ingress.host`: the address of the admin, e.g. `https://modbot.example.com`
- `config.DB_HOST`: the database host (`modbot-db-rw` with CloudNativePG)
- `config.TRUSTED_PROXIES`: include the pod network of your ingress controller (e.g. `192.168.0.0/16` with Calico), so client IPs and HTTPS are recognised
- `ingress.className`, the TLS annotation and secret name: as used in your cluster
- `config.SLACK_CHANNEL`, `config.AHASEND_RECIPIENTS`: where reports go (can be changed in the admin later)

All available settings and their defaults are in [`deploy/helm/modbot/values.yaml`](deploy/helm/modbot/values.yaml).

### 6. Install

From a checkout of this repository:

```bash
helm upgrade --install modbot deploy/helm/modbot -n modbot -f values-production.yaml
```

Or with the packaged chart from pixio: `helm upgrade --install modbot modbot-1.13.1.tgz -n modbot -f values-production.yaml`.

Check that everything runs:

```bash
kubectl -n modbot get pods                                    # modbot-scheduler-… and modbot-web-… Running
kubectl -n modbot logs deploy/modbot-scheduler -c migrate     # "Running migrations" / "Nothing to migrate"
curl -fsS https://modbot.example.com/up                       # 200
```

### 7. First user and checks

Create the first admin user (use a real email address: login codes are sent there):

```bash
kubectl -n modbot exec -it deploy/modbot-web -- php artisan make:filament-user
```

Then log in at `https://modbot.example.com/admin` and check under **Settings**: **Test AI connection**, **Send test message** (Slack) and **Send test email**. Start a dry run under **Runs → Run now** and enable the schedules under **Schedules**.

### Updates

Set `image.tag` in `values-production.yaml` to the new version and run the same `helm upgrade --install` command. The scheduler is replaced by the new version, whose init container first runs the database migrations; the web pods are replaced one by one at the same time (migrations only add to the database, so the old web pods keep working meanwhile). Take a database backup before an update. **System → About & updates** in the admin shows whether PHP, Laravel, PostgreSQL or packages need attention.

To go back: `helm rollback modbot -n modbot` (database changes are not undone; restore the backup if needed).

### Operations

| Task | Command |
|---|---|
| Logs | `kubectl -n modbot logs deploy/modbot-scheduler` (also in the admin under System → Logs) |
| Run from the command line | `kubectl -n modbot exec deploy/modbot-scheduler -- php artisan modbot:run --dry-run` |
| Add a user | `kubectl -n modbot exec -it deploy/modbot-web -- php artisan make:filament-user` |
| Password login back on | `kubectl -n modbot exec deploy/modbot-web -- php artisan modbot:password-login on` |
| Database backup (without CloudNativePG backups) | `kubectl -n modbot exec <postgres pod> -- pg_dump -U modbot modbot > modbot.sql` |

Keep the scheduler at **1 replica**: two schedulers would run every schedule twice. The web Deployment can be scaled.

### Troubleshooting

| Symptom | Check |
|---|---|
| `ImagePullBackOff` | The `ghcr-pull` Secret and token (step 3) |
| Scheduler stuck in `Init` | The migrate container's log: database host, user, password, network policy to PostgreSQL |
| `/admin` redirects to `http://` or shows "Page expired" | `config.APP_URL` must be the https address; `config.TRUSTED_PROXIES` must include the ingress controller's pod network |
| No login code by email | `MAIL_MAILER: ahasend` and the AhaSend keys; Settings → Send test email |
| Runs fail | The run page shows the cause (and which API key was used); Settings → Test AI connection |

## Roadmap

1. ~~Parity with Make, with stored data~~
1. ~~Admin panel: runs, flags, comments, schedules, settings, prompts~~
1. ~~Multiple AI providers; email login codes~~
1. Incremental runs every 10–15 minutes, immediate Slack alert for high priority
1. Slack buttons (correct / incorrect / handled) for moderator feedback; author history in flags
1. Eval set from moderator feedback to test prompt/model changes
