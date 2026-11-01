# Architecture

FIBES CRM is a Laravel 13 application on PHP 8.3. Inertia serves React and TypeScript pages. PostgreSQL is the database. Redis holds sessions, cache, and queues. Each domain module is a service provider with its own route file. The core app keeps authentication, the design system, and the shell.

## Modules

| Module | Code | Owns |
| --- | --- | --- |
| CRM | `app/Modules/Crm` | Leads, accounts, contacts, and opportunities. Retail and reseller pipelines stay in this module. |
| Surveys | `app/Modules/Surveys` | Operations scheduling and technician site surveys. |
| Quotes | `app/Modules/Quotes` | Quote builder, USD and LBP, approvals, and branded PDFs. |
| Settings | `app/Modules/Settings` | Sources, products, pipelines, SLA policy, and other working rules. |

`App\Modules\ModuleServiceProvider` loads `routes.php` inside the `web` middleware. The four providers are registered in `bootstrap/providers.php`. Step 1 route files are empty on purpose.

Navigation placeholders live in the core app (`ModuleController`) until a module has real screens. There is no Excel import. The CRM starts empty.

Shared configuration is `config/fibes.php`: supported locales, the env-only admin seed, the module map, service types, and SLA policy. Sam’s answers are written in [DECISIONS.md](DECISIONS.md).

Access is deny-by-default. Roles, permissions, and teams live in the database. A person may hold several roles; the widest scope wins (`own`, then `team`, then `all`). `App\Support\Access\AccessGate` answers permission checks and limits list queries. `FieldPolicy` hides phone, email, address, money, cost, internal notes, and RADIUS passwords. The Super Admin role is locked. A person cannot change their own roles, and the last active Super Admin cannot be removed.

## Step 1 boundaries

Built now:

- Session authentication. There is no public registration and no API token guard.
- Password reset through a database token, not a temporary signed URL.
- Optional TOTP two-factor with one-time recovery codes.
- Rate limits on login, password reset, and the two-factor challenge.
- Light and dark tokens from the approved design (`resources/css/tokens.css`), Tailwind 4, self-hosted Inter and IBM Plex Sans Arabic, and logical properties for RTL. Official logo files are in `public/brand` and are never redrawn or mirrored.
- Translation files in `lang/en` and `lang/ar`.
- One administrator, created by `AdminUserSeeder` from `ADMIN_EMAIL` and `ADMIN_PASSWORD`. The seed password is temporary: the account must replace it at first sign-in.
- Authenticator policy by role. Super Admin is required after a 7-day grace. Other roles are off until `FIBES_2FA_REQUIRED_ROLES` lists them.
- A sessions page for the signed-in person, and for Super Admin the other open sessions.
- An append-only `audit_log` for sign-in, password, authenticator, and session events. A database trigger rejects updates and deletes.
- Security headers on every page, including a script nonce and HSTS when the request is HTTPS.

Lebanon’s governorates, districts, and starter towns live in the database and can be edited. Phone numbers are stored as E.164. Company profile, support hours, service targets, and holidays are rows in `settings` and `holidays`, not hard-coded. USD and LBP are currencies; the LBP rate is an append-only list. Files sit on the private disk and are downloaded through a short-lived signed link. Notices are stored for the bell in the shell and emailed on the `notifications` queue, with three tries. A private broadcast channel is ready for Laravel Reverb; the Reverb package itself is not installed yet because it conflicts with the locked HTTP library.

Pipelines are live for retail, business, and reseller. A qualified lead opens one prospect and one deal. Stage history is append-only. See [PIPELINES.md](PIPELINES.md).

Leads are live: a shared queue, claim, sales handoff, and clocks that skip holidays, including the Islamic dates an admin confirms each year. The public website and Meta Lead Ads post signed webhooks. See [LEADS.md](LEADS.md).

The catalog holds plans, fees, and promotions. A price is never edited in place: a change adds a new row, and a service keeps the price it was given until an approved migration says otherwise. List prices are visible to sales and the call center. Cost is visible to finance, management, and admin. See [CATALOG.md](CATALOG.md).

Approvals are one inbox. A person cannot approve their own request. Some requests need more than one person, and a deadline moves the request up once. See [APPROVALS.md](APPROVALS.md).

Coverage is live: cabinets, buildings, and wireless sectors, file import, a check from a pin or a map link, and a site survey on the technician screen. The dispatch board draws the day’s stops. Tests use SQLite, so the distance and point-in-polygon math runs in PHP against a latitude/longitude index. On PostgreSQL the migration adds a GiST index when PostGIS is installed. See [COVERAGE.md](COVERAGE.md).

Orders are live. Winning a deal opens one order and one requested field job. A price change or a termination waits in the approval inbox. Cancelling gives reserved stock back. Activation needs a RADIUS username and leaves a task for Krypton. Purchase orders above the inbox limit wait for someone else, and receiving adds stock without editing older rows. See [ORDERS.md](ORDERS.md).

Billing is live. Activation opens the subscription and the first invoice, and that invoice closes the order check. A daily run issues the next invoices. Credit notes, write-offs, and refunds wait in the approval inbox when they are above the limit. The customer balance is the last ledger row. Figures Sam has not signed off are edited in Settings and listed in [to-confirm.md](to-confirm.md). See [BILLING.md](BILLING.md).

Payments are live. Cash, Whish, OMT, bank, card, and cheque are recorded against the same ledger. Whish and a bank transfer wait until Finance confirms them. A reversal is a new ledger row. Office cash sits in a desk session and field cash in the technician’s session. A difference above the limit in Settings needs someone else. See [PAYMENTS.md](PAYMENTS.md).

Collections are live. Reminders follow the dates in Settings. WhatsApp is used only when the customer has agreed. Suspension and turning a line back on leave a task for Krypton, because Krypton is not connected. A payment that clears a suspended balance asks for that task. A promise that is missed moves the next day. See [COLLECTIONS.md](COLLECTIONS.md).

Retention is live. A termination, including the thirty-day collections request, waits in the retention queue before the service can be closed. Offers use the catalog price and the discount caps. Anything over the cap, or more than one free month, waits in the approvals inbox. A free month posts a credit. A saved customer and a decline feed the save-rate report. See [RETENTION.md](RETENTION.md).

Quality scoring is live. Supervisors score calls, tickets, escalations, and retention talks. A critical miss scores zero unless Settings says otherwise. Agents see only their own scores. The weekly sample size is in Settings. Call-center figures on the same page link back to the call, ticket, lead, or retention record. Abandoned calls stay blank until a phone system is connected. See [QA.md](QA.md).

Go-live is documented in [GO_LIVE_CHECKLIST.md](GO_LIVE_CHECKLIST.md). Production compose, deploy, and rollback stay on the FIBES server. Nothing in CI logs in to that machine. Horizon and Reverb are still not installed.

Page speed, file scanning, and the health check are in [HARDENING.md](HARDENING.md). Customer search and the dispatch board were tightened against a 30,000-customer seed. Uploads sit in quarantine until the scanner marks them clean. Technicians keep a longer session, and cash or a signature asks for the password again.

Imports and backups are live. An admin can check a CSV file, save it, and undo it for a day. A renamed column saves nothing. Nightly backups are encrypted. The monthly restore test refuses to load a dump into the live database. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md).

Automations are live. An admin edits versioned rules. A practice run does not change records. A pause stops every action, and a rule that triggers itself stops at the limit in Settings. Outbound webhooks are signed, retried, and stored without their secret. See [AUTOMATIONS.md](AUTOMATIONS.md).

Each role has a home page of numbers. The report list is filtered by the person’s work, and a download is refused unless that person is allowed to export. A large download is a queued job. The link lasts 24 hours. A daily note goes out at the hour in Settings, and a weekly note on the weekday in Settings. The page refreshes on a timer because Reverb is not installed. See [REPORTS.md](REPORTS.md).

Targets and commissions are live. A won deal, an activation, and a confirmed payment can each post a commission. The rows are append-only. A clawback or an adjustment waits in the approvals inbox and then adds a new row. The sales-floor screen is read-only and refreshes every 2 seconds. Reverb is not installed: its current release needs an older Guzzle library than Laravel 13. See [PERFORMANCE.md](PERFORMANCE.md).

Quotes and contracts are live. A quote is priced from the catalog in dollars or Lebanese pounds, and a discount over the person’s limit waits in the approval inbox. Printing is a branded HTML page, or a PDF when Chrome is configured. A signed contract keeps its own snapshot and fingerprint. Winning a deal needs that signed contract, or the existing sign-at-install approval. See [QUOTES.md](QUOTES.md). Role dashboards and saved reports are still later. A running Reverb server waits on a release that accepts Guzzle’s newer HTTP library. `branch_id` is not on any table yet.

A breached lead clock notifies the call-center supervisor and the sales manager once. The same count is on the supervisor call board.

## Request path

`bootstrap/app.php` appends `SetLocale` and `HandleInertiaRequests` to the web stack. Locale comes from the session, then the signed-in user’s `users.locale`, then the application default. `POST /locale` updates both when a user is signed in.

Inertia shares the locale, direction, translation tree, the current user, and flash messages. Pages live in `resources/js/Pages`. Authenticated pages wrap `AppShell`. Guest pages wrap `GuestLayout`.

Destructive database commands are prohibited unless `APP_ENV` is `local` or `testing`.

## Laravel 13

The application is on Laravel 13.39 (PHP 8.3–8.5; this repo’s image and CI stay on 8.3). Laravel 11 security support ended on 12 March 2026. Laravel 13 receives security fixes until 17 March 2028. `composer.json` does not ignore advisories.

Upgrade choices, from the Laravel 12 and 13 upgrade guides:

- Cache refuses to unserialize PHP objects (`cache.serializable_classes` is `false`). The app does not store objects in cache.
- Sessions serialize as JSON. A deployment that already has PHP-serialized sessions will sign everyone out once.
- `CACHE_PREFIX`, `REDIS_PREFIX`, and `SESSION_COOKIE` are set in `.env.example` to the names this app already used (`fibes`, `fibes_database_`, `fibes_session`). Laravel 13 would otherwise generate hyphenated names and orphan existing keys.
- The local disk already uses `storage/app/private`, which is the Laravel 12 default.
- CSRF protection is Laravel’s `PreventRequestForgery` middleware, including the `Sec-Fetch-Site` check. There is no custom CSRF class to rename.
- Pest 4 and PHPUnit 12 match the Laravel 13 upgrade guide. Pest 5 needs PHP 8.4, so it is not used while the image stays on 8.3.

Still true, and still worth keeping when an API is added:

- The session guard is the only guard. There is no API token guard yet, so Fortify, Sanctum, Horizon, and Reverb are not installed.
- `APP_DEBUG` is false outside local.
- `App\Rules\SafeEmail` rejects control characters instead of Laravel’s email rule.
- Password reset uses a custom notification and a database token, not Laravel’s temporary signed URL.

## Sign-in and sessions

`config/fibes.php` holds the two-factor role list, the 7-day grace, the idle and absolute session limits, and the rate limits. Login allows 5 attempts a minute for the same email and address, then pauses the account for 15 minutes after 10 failures and emails the account. The route also keeps a looser per-minute cap so the person still sees the translated message.

Sessions use the `sessions` table. `EnforceAccountSecurity` signs out an idle or over-long session, sends a first administrator to the password screen, and, after the grace period, keeps a required role on the authenticator setup page.

Nginx forwards `/up` to `index.php`. An exact location that only turned off the access log was returning nginx’s own 404.
