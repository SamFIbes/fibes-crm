# Performance, access, and file scanning

`php artisan db:seed --class=Database\\Seeders\\LoadSeeder` inserts 30,000 customers and a share of services, tickets, jobs, invoices, and timeline rows. It refuses to run when `APP_ENV=production`. `FIBES_LOAD_CUSTOMERS` changes the count. It is not part of the normal seed.

`php artisan perf:pages` prints the server time for the customer list, a customer search, one customer page, and the dispatch week. `tests/load/pages.js` is the same path for k6. Meilisearch is not installed. Search uses the database, with Arabic letter folding, an exact account match, and one phone lookup instead of a lookup per customer.

Every upload is stored under `quarantine/` until the scanner finishes. `FIBES_FILE_SCAN_DRIVER=null` rejects the EICAR test string and accepts other files. `clamav` talks to clamd on `FIBES_CLAMAV_HOST` and `FIBES_CLAMAV_PORT`. An infected file is deleted, the upload is audited, and the person and the admins are told. Clean files are downloaded only through a signed link that expires. The Docker Compose files include a ClamAV service for dev and production.

`/health` checks the database and returns no secrets. A ZAP 2.16 quick scan of the local login page stopped on “Authorization required” and did not write a report. The live responses still send `X-Frame-Options: DENY` (except the sales floor), `nosniff`, a script nonce, and `SameSite=Lax` cookies. `X-Powered-By` is removed. Nginx no longer forces the frame header, so the sales floor can stay embeddable, and `/health` is proxied like `/up`. Each response carries `X-Request-Id`. Log lines drop email addresses and phone numbers. Set `SENTRY_DSN` to forward errors to Sentry or GlitchTip. Horizon is not installed: the current Laravel HTTP stack still conflicts with the packages that need the older Guzzle library, same as Reverb.

A technician stays signed in for the minutes in Settings (12 hours). Everyone else stays on the 60-minute idle limit. Cash collection and a job signature ask for the password again after the minutes in Settings (15).

Authenticator enrollment is required, after 7 days, for Super Admin, management, finance, NOC, the call-center supervisor, and the operations manager. Passwords stay at 12 characters. Ten wrong passwords pause sign-in for 15 minutes. Sessions can be listed and revoked. The frame header is `DENY` except on the sales-floor screen. Cookies are `SameSite=Lax`.
