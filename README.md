# FIBES CRM

Bilingual CRM for FIBES, a Lebanese ISP. Sign-in, people and roles, Arabic and English, and the application shell use the approved FIBES look. Customer, ticket, and billing work comes in later steps.

The application runs on Laravel 13 and PHP 8.3. See [CHANGELOG.md](CHANGELOG.md) for the upgrade from Laravel 11 and the security pass that followed it.

The Provider Topology screen at `/topology` draws the payment rails, lead channels, and other services this CRM already uses. There is no providers table. `App\Services\Integrations\ProviderTopology` reads those settings. A row marked sample is a stand-in until a live check replaces that method.

## Run locally with Docker

These steps assume a Linux server with Docker Engine and the Docker Compose plugin.

1. Install Docker Engine and the Compose plugin, then confirm both work:

   ```bash
   docker --version
   docker compose version
   ```

2. Clone this repository and change into it.

3. Create the environment file:

   ```bash
   cp .env.example .env
   ```

4. Edit `.env` and set:

   - `DB_PASSWORD` — any password other than `change-me`, `secret`, or `password` (those three are refused in staging and production).
   - `ADMIN_EMAIL` — the first administrator’s address.
   - `ADMIN_PASSWORD` — at least 12 characters, with upper case, lower case, and a number.
   - `ADMIN_NAME` and `ADMIN_LOCALE` (`en` or `ar`) if you want something other than the defaults.

   The password in `ADMIN_PASSWORD` is only the first sign-in. The administrator must choose a new one before the workspace opens. It is not written into the database seed as a fixed value.

   Leave `APP_KEY` empty. The development entrypoint generates one and writes it back into `.env`. Do not commit `.env`.

   If you are updating an `.env` that was created before the Laravel 13 upgrade, also set:

   - `SESSION_COOKIE=fibes_session`
   - `CACHE_PREFIX=fibes` (already in the example file)
   - `REDIS_PREFIX=fibes_database_`

   Those names are pinned so the upgrade does not rename the session cookie or Redis keys. Sessions are stored as JSON in the database (`SESSION_DRIVER=database`), so a person can see and sign out other browsers. Redis still holds the cache and the queue. A quiet browser is signed out after 60 minutes, and every browser after 12 hours. Anyone who was signed in before the upgrade must sign in again.

5. Build the PHP image:

   ```bash
   docker compose build
   ```

6. Compile the frontend once (nginx serves the built files):

   ```bash
   docker compose --profile build run --rm assets
   ```

7. Start PostgreSQL, Redis, PHP, and nginx:

   ```bash
   docker compose up -d
   ```

8. Open [http://localhost:8088](http://localhost:8088) and sign in with `ADMIN_EMAIL` and `ADMIN_PASSWORD`. The next screen asks for a new password. The health check is [http://localhost:8088/up](http://localhost:8088/up). If you changed `APP_PORT`, use that port instead.

Useful commands:

```bash
docker compose logs -f app
docker compose exec app php artisan migrate --force
docker compose exec app php artisan db:seed --force
docker compose down
```

`migrate:fresh`, `db:wipe`, and rollback are refused unless `APP_ENV` is `local` or `testing`. Do not run them against staging or production.

## Staging and production

Use a different Compose project name so the databases do not collide. Set `APP_ENV`, `APP_DEBUG=false`, a real `APP_KEY`, `APP_URL`, `DB_PASSWORD`, and `ADMIN_PASSWORD` before the first boot. The production entrypoint exits if `APP_KEY`, `DB_PASSWORD`, or `ADMIN_PASSWORD` is missing, and if `DB_PASSWORD` is a placeholder.

```bash
docker compose -p fibes-staging -f docker-compose.yml -f docker-compose.staging.yml up -d --build
docker compose -p fibes-prod -f docker-compose.yml -f docker-compose.prod.yml up -d --build
```

Put TLS in front of nginx. Set `SESSION_SECURE_COOKIE=true` when `APP_URL` is `https`.

## Deploy on Railway

Railway builds `Dockerfile.render` (see `railway.toml`). That image listens on `PORT`, waits for Postgres, migrates, seeds, and serves `/health`. Redis is not required: sessions, cache, and the queue use the database.

1. Create a Railway project and add a PostgreSQL database.
2. Add a service from this repository. Railway reads `railway.toml`, builds `Dockerfile.render`, and uses `/health` as the health check.
3. Set the variables below. Reference the Postgres plugin for `DATABASE_URL` (or `DATABASE_PRIVATE_URL`). Leave `APP_URL` empty to use `https://$RAILWAY_PUBLIC_DOMAIN`.
4. Deploy. The first boot creates the tables and the first administrator. That person must choose a new password at first sign-in.

The image refuses to start unless `APP_KEY`, `ADMIN_PASSWORD`, and the database are set, and it refuses the placeholder database passwords `change-me`, `secret`, and `password`.

| Name | Required | Value |
| --- | --- | --- |
| `APP_KEY` | yes | `base64:...` from `php artisan key:generate --show`, or `echo "base64:$(openssl rand -base64 32)"`. Keep it. Changing it signs everyone out. |
| `ADMIN_EMAIL` | yes | Email of the first administrator. |
| `ADMIN_PASSWORD` | yes | At least 12 characters, with upper case, lower case, and a number. First sign-in only. |
| `DATABASE_URL` | yes | Railway Postgres connection string. `DATABASE_PRIVATE_URL` is used when `DATABASE_URL` is empty. |
| `ADMIN_NAME` | no | Defaults to `FIBES Admin`. |
| `ADMIN_LOCALE` | no | `en` or `ar`. Defaults to `en`. |
| `APP_ENV` | no | Image sets `production`. |
| `APP_DEBUG` | no | Image sets `false`. |
| `APP_TIMEZONE` | no | Set `Asia/Beirut` if you want the app zone instead of UTC. |
| `APP_URL` | no | Public `https://` origin. Filled from `RAILWAY_PUBLIC_DOMAIN` when empty. |
| `SESSION_DRIVER` | no | Image sets `database`. |
| `CACHE_STORE` | no | Image sets `database`. |
| `QUEUE_CONNECTION` | no | Image sets `database`. |
| `SESSION_SECURE_COOKIE` | no | Image sets `true`. |
| `LOG_CHANNEL` | no | Image sets `stderr`. |
| `FIBES_FILE_SCAN_DRIVER` | no | Image sets `null` (built-in check, no ClamAV). |

Optional connection switches (`FIBES_WHISH`, `FIBES_RADIUS`, `FIBES_TELEPHONY_SECRET`, `FIBES_WEB_FORM_SECRET`, `FIBES_META_VERIFY_TOKEN`, `FIBES_META_APP_SECRET`, and the rest in `.env.example`) change what Provider Topology marks active. They are not required to boot.

## Tests on the host

PHP 8.3 (with `pdo_sqlite`), Composer, and Node 22. Laravel 13 also runs on PHP 8.4 and 8.5. This project stays on 8.3 so the Docker image, CI, and local setup stay the same.

```bash
composer install
cp .env.example .env
php artisan key:generate
vendor/bin/pint --test
php artisan test
composer audit
npm ci
npm run typecheck
npm test
npm run build
npm audit --omit=dev
```

Pest uses an in-memory SQLite database. It does not touch PostgreSQL.

## What this step includes

Authentication (login, logout, password reset, optional authenticator-app 2FA, rate limiting), light and dark brand tokens, Arabic (RTL) and English, and a shell with a collapsible sidebar, command palette, toasts, and error pages. Module screens are placeholders.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the module layout and the Laravel 13 notes.
