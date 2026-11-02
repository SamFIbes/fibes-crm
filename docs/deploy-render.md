# Deploying FIBES CRM on Render (free plan)

One Docker web service runs everything: nginx, php-fpm, the queue worker and the scheduler
(`Dockerfile.render`, started by `docker/render/start.sh` under supervisord). Data lives in a
Render Postgres 16 database. No Redis is needed: sessions, cache and queue use the database.

## Option A: Blueprint (fastest)
1. Render dashboard > **New** > **Blueprint** > connect GitHub > pick `SamFIbes/fibes-crm`.
2. Render reads `render.yaml` and proposes the web service `fibes-crm` and the database `fibes-db`.
3. Fill in the three secrets it asks for: `APP_KEY`, `ADMIN_EMAIL`, `ADMIN_PASSWORD`.
4. Click **Apply**. The first build takes about 10 minutes.

## Option B: by hand
1. **New > Postgres**: name `fibes-db`, region **Frankfurt (EU Central)**, PostgreSQL **16**, plan **Free**.
2. **New > Web Service** > the GitHub repo:
   - Branch: `main` · Language/Runtime: **Docker** · Region: **Frankfurt** · Plan: **Free**
   - Dockerfile path: `./Dockerfile.render` · Docker build context: `.`
   - Health check path (Advanced): `/health`
3. Environment variables:

| Name | Value |
|---|---|
| `APP_KEY` | `base64:...` (see below) |
| `DATABASE_URL` | the database's **Internal Database URL** |
| `ADMIN_EMAIL` | the first admin's email |
| `ADMIN_PASSWORD` | 12+ characters with upper case, lower case and a number |
| `ADMIN_NAME` | e.g. `Sam Al-Nakhel` |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `SESSION_DRIVER` / `CACHE_STORE` / `QUEUE_CONNECTION` | `database` (already the image default) |
| `SESSION_SECURE_COOKIE` | `true` (image default) |
| `FIBES_FILE_SCAN_DRIVER` | `null` (no ClamAV on Render free) |

`APP_URL` is optional: the start script uses Render's `RENDER_EXTERNAL_URL` when it is not set.
Instead of `DATABASE_URL` you may set `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

### APP_KEY
Generate once and keep it secret (changing it logs everyone out and breaks encrypted data):
`php artisan key:generate --show` on any machine with the project, or
`echo "base64:$(openssl rand -base64 32)"`.

## What happens at start
Wait for Postgres > `migrate --force` (enables PostGIS when available) > `db:seed --force`
(roles, settings, first admin) > config/route/view caches > nginx on `$PORT`.
The admin must choose a new password at first sign-in.

## Free plan limits
- The web service sleeps after 15 minutes without visits; the first visit then takes ~1 minute.
  The queue worker and scheduler sleep with it, so scheduled jobs only run while it is awake.
- Render free Postgres expires after 30 days unless upgraded. Back it up or upgrade before then.
- The disk is wiped on every deploy: uploaded files are lost. Use object storage (S3/MinIO) for real use.
- No ClamAV, Meilisearch or Gotenberg: file scanning is off, search uses Postgres, PDFs use HTML.
