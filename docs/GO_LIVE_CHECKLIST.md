# Go-live checklist

Nothing in this repository deploys itself. Sam runs the hosting steps below on the FIBES machine after he approves a tag. Tick a box only when the evidence next to it is true.

## Checklist

- [ ] (1) Tests are green and the dependency audits are clean. Evidence: the CI run on the tag.
- [ ] (2) The production environment file is complete, every secret is new, the old development admin password is retired, `APP_KEY` is backed up offline, debug is off, and the site is served over HTTPS with the security headers. Evidence: a screenshot of the headers, not of the secrets.
- [ ] (3) The production seed has run. The super admin has signed in, changed the password, and turned on an authenticator. No load-seed customers (`LD-`) are in the database. Evidence: the account list filtered to `LD-` is empty.
- [ ] (4) Every staff member has a user and a role. Super Admin, management, finance, NOC, the call-center supervisor, and the operations manager have an authenticator after the 7-day grace. Evidence: the people list.
- [ ] (5) Settings are filled: company profile, logo, VAT, the day’s exchange rate, hours, holidays including the Islamic dates, SLA, approval amounts, billing, collections, and the Arabic and English templates. Evidence: Settings, and the figures in the next section.
- [ ] (6) Sam has reviewed the catalog. Evidence: his note on the price list.
- [ ] (7) Coverage is imported and ten real addresses were checked on the map. Evidence: the ten results.
- [ ] (8) Opening stock is counted at each location. Evidence: the count sheets.
- [ ] (9) Mail sends, SMS is verified if it is turned on, and a `wa.me` link was opened on a phone. Evidence: one received message and one phone test.
- [ ] (10) The nightly backup has run and one restore test landed in the scratch database, not the live one. Evidence: the restore-test output.
- [ ] (11) Monitoring and error tracking received a test. Evidence: the alert and the Sentry or GlitchTip event.
- [ ] (12) Each department signed UAT. Training is done. One-page drafts are in `docs/guides/`. Evidence: `docs/UAT.md`.
- [ ] (13) Cut-over is agreed: old tools freeze, about 3,000 customers are entered by renewal date, and a Krypton CSV prefill is optional. Evidence: the cut-over note.
- [ ] (14) Two weeks of hypercare are staffed. Evidence: the rota.
- [ ] (15) The rollback plan was read. Evidence: `docs/DEPLOY.md`.

## Secrets and environment variables Sam must set

Copy `.env.example` to `.env` on the server. Leave the real values only in that file and in the offline backup of `APP_KEY`. Do not commit them.

| Name | What Sam puts there |
| --- | --- |
| `APP_KEY` | A new key from `php artisan key:generate --show`. Store a copy offline. |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` |
| `APP_URL` | The public `https://` address |
| `APP_TIMEZONE` | `Asia/Beirut` |
| `DB_PASSWORD` | A new database password. The app refuses `change-me`, `secret`, and `password`. |
| `DB_DATABASE` | Database name, default `fibes` |
| `DB_USERNAME` | Database user, default `fibes` |
| `ADMIN_EMAIL` | The first super admin’s email |
| `ADMIN_PASSWORD` | A new password, at least 12 characters, with upper case, lower case, and a number. He must change it at first sign-in. |
| `ADMIN_NAME` | The first super admin’s name |
| `FIBES_BACKUP_KEY` | A long random string. Backups cannot be written without it. Keep a copy offline. |
| `FIBES_BACKUP_DIRECTORY` | Folder for encrypted backups. Empty uses `storage/app/backups`. |
| `FIBES_BACKUP_DESTINATION` | A folder on another disk or server. Each new backup is copied there. |
| `FIBES_BACKUP_SCRATCH` | The scratch database for the monthly restore test. It must not be the live database. |
| `FIBES_FILE_SCAN_DRIVER` | `clamav` in production. `null` only rejects the EICAR test string. |
| `FIBES_CLAMAV_HOST` | `clamav` on the production compose network |
| `FIBES_CLAMAV_PORT` | `3310` |
| `SENTRY_DSN` | The Sentry or GlitchTip DSN. Empty means errors stay in the log. |
| `FIBES_TELEPHONY_SECRET` | Empty until the Grandstream PBX is connected. Screen-pop stays refused while this is empty |
| `FIBES_TELEPHONY_URL` | Empty until click-to-dial. Example `https://pbx.example:8089` |
| `FIBES_TELEPHONY_USER` | Empty. UCM HTTP API user for click-to-dial |
| `FIBES_TELEPHONY_PASSWORD` | Empty. Never commit it |
| `FIBES_TELEPHONY_API_PATH` | `/api` |
| `FIBES_TELEPHONY_DIAL_ACTION` | `dialOutbound` until the UCM guide says otherwise |
| `FIBES_ERPNEXT_URL` | `https://erp.thehubholding.com` |
| `FIBES_ERPNEXT_API_KEY` | Empty until the Hub user has API Access |
| `FIBES_ERPNEXT_API_SECRET` | Empty. Never commit a real secret |
| `FIBES_ERPNEXT_ENABLED` | `false` until the key and secret exist |
| `FIBES_ERPNEXT_WEBHOOK_SECRET` | Empty until ERP can sign stock updates |
| `FIBES_ERPNEXT_COMPANY` | `Smart Network` |
| `FIBES_RADIUS` | `false` until Krypton should be called |
| `FIBES_RADIUS_URL` | Empty. A likely value is `https://rad.smartnetworkslb.net` once the vendor guide confirms it |
| `FIBES_RADIUS_API_KEY` | Empty |
| `FIBES_RADIUS_ALSO_TASK` | `false`. `true` also opens a NOC task after a live suspend or reactivate succeeds |
| `FIBES_RADIUS_PATH_HEALTH` | `/api/v1/health` (assumed) |
| `FIBES_RADIUS_PATH_USER` | `/api/v1/users/{username}` (assumed) |
| `FIBES_RADIUS_PATH_SUSPEND` | `/api/v1/users/{username}/suspend` (assumed) |
| `FIBES_RADIUS_PATH_REACTIVATE` | `/api/v1/users/{username}/reactivate` (assumed) |
| `FIBES_RADIUS_PATH_SESSION` | `/api/v1/users/{username}/session` (assumed) |
| `FIBES_WHISH` | `false` until Whish checkout should be called |
| `FIBES_WHISH_URL` | Empty until Whish gives the merchant base URL |
| `FIBES_WHISH_API_KEY` | Empty |
| `FIBES_WHISH_WEBHOOK_SECRET` | Empty until Whish can sign `POST /api/v1/hooks/whish` |
| `FIBES_WHISH_PATH_HEALTH` | `/api/v1/health` (assumed) |
| `FIBES_WHISH_PATH_CHECKOUT` | `/api/v1/checkouts` (assumed) |
| `FIBES_WHATSAPP` | `false`. Messages stay on `wa.me` |
| `FIBES_WHATSAPP_TOKEN` | Empty |
| `FIBES_WHATSAPP_PHONE_ID` | Empty. The numeric Meta phone number id |
| `FIBES_WHATSAPP_GRAPH_VERSION` | `v21.0` |
| `FIBES_WHATSAPP_VERIFY_TOKEN` | Empty. Falls back to `FIBES_META_VERIFY_TOKEN` |
| `FIBES_WHATSAPP_APP_SECRET` | Empty. Falls back to `FIBES_META_APP_SECRET` |
| `FIBES_WHATSAPP_TEMPLATE_RENEWAL` | `fibes_renewal_reminder` until Meta approves another name |
| `FIBES_WHATSAPP_TEMPLATE_TICKET` | `fibes_ticket_update` |
| `FIBES_WHATSAPP_TEMPLATE_PAYMENT` | `fibes_payment_reminder` |
| `FIBES_WEB_FORM_SECRET` | Empty until the public website form is connected |
| `FIBES_META_VERIFY_TOKEN` | Empty until Meta Lead Ads is connected |
| `FIBES_META_APP_SECRET` | Empty until Meta Lead Ads is connected |
| `FIBES_TURNSTILE_SECRET` | Empty until Turnstile is used |
| `FIBES_2FA_REQUIRED_ROLES` | `super_admin,management,finance,noc_engineer,cc_supervisor,ops_manager` |
| `FIBES_2FA_GRACE_DAYS` | `7` |
| `SESSION_SECURE_COOKIE` | `true` once the site is HTTPS |
| `SESSION_LIFETIME` | `60` (everyone except technicians; technicians use Settings) |
| `MAIL_HOST` | The company mail server |
| `MAIL_PORT` | The company mail port |
| `MAIL_USERNAME` | The mailbox user |
| `MAIL_PASSWORD` | The mailbox password |
| `MAIL_FROM_ADDRESS` | The address customers see |
| `MEILI_MASTER_KEY` | A new key if Meilisearch is used. Search does not depend on it yet. |
| `MINIO_ROOT_USER` | A new user if MinIO is used. Files still use the local disk. |
| `MINIO_ROOT_PASSWORD` | A new password if MinIO is used |
| `REDIS_PASSWORD` | Empty unless Redis itself requires one |

These can stay at the example values until Sam changes them: `APP_LOCALE`, `LOG_CHANNEL`, `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `QUEUE_CONNECTION`, `CACHE_STORE`, `BROADCAST_CONNECTION` (`log` until Reverb exists), `FILESYSTEM_DISK`, `FIBES_PDF_DRIVER`, `FIBES_SMS_DRIVER`, `FIBES_WHISH`, `FIBES_RADIUS`, `FIBES_RADIUS_ALSO_TASK`, the Krypton and Whish path variables, `FIBES_TELEPHONY_API_PATH`, `FIBES_TELEPHONY_DIAL_ACTION`, `FIBES_WHATSAPP`, `FIBES_WHATSAPP_GRAPH_VERSION`, the three WhatsApp template names, `FIBES_FIBERDESK`, `FIBES_MAP_TILES`.

## Figures still to confirm

An admin edits every row under Settings → Figures still to confirm. Saving writes the audit log. The day’s dollar rate is not here. Finance records it on Exchange rates.

| What | Current default | Where to change it |
| --- | --- | --- |
| VAT | 11% | Settings → Figures still to confirm |
| Fallback USD to LBP rate, used only when Finance has not recorded a rate | 89,500 | Settings → Figures still to confirm |
| Quote validity | 14 days | Settings → Figures still to confirm |
| Contract length | 12 months | Settings → Figures still to confirm |
| Early termination fee | $0.00 | Settings → Figures still to confirm |
| Install target | 5 business days | Settings → Figures still to confirm |
| Deposit required before scheduling | Off | Settings → Figures still to confirm |
| Equipment return charge | $0.00 | Settings → Figures still to confirm |
| Sales agent discount cap | 5% or $5 a line, 5% or $10 on the quote | Settings → Figures still to confirm |
| Sales manager discount cap | 15% or $25 a line, 15% or $50 on the quote | Settings → Figures still to confirm |
| Purchase that can go through without a second person | $200 | Settings → Figures still to confirm |
| Credit note that finance can issue without a second person | $20 | Settings → Figures still to confirm |
| Refund finance can approve | up to $50 | Settings → Figures still to confirm |
| Refund management can approve | up to $500 | Settings → Figures still to confirm |
| Quote discount approval deadline | 4 hours | Settings → Figures still to confirm |
| Discount that needs a comment when approved | above 15% | Settings → Figures still to confirm |
| Home plan price | $35 | Settings → Figures still to confirm. Saving adds a new price row and leaves the old one. |
| Business price for that plan | $40 | Settings → Figures still to confirm |
| Installation fee | $50 | Settings → Figures still to confirm |
| Billing | Prepaid monthly | Settings → Figures still to confirm |
| Service expiry time | 14:00 | Settings → Figures still to confirm |
| Grace after expiry | 3 days | Settings → Figures still to confirm |
| Mid-cycle price change | Daily share of the month | Settings → Figures still to confirm |
| Longest voluntary hold | 30 days | Settings → Figures still to confirm |
| Cash difference that needs a second person | $5.00 | Settings → Figures still to confirm |
| Technicians collecting cash | On | Settings → Figures still to confirm |
| Desk opening float | $0.00 | Settings → Figures still to confirm |
| How long a cash password stays valid | 15 minutes | Settings → Figures still to confirm |
| Renewal reminder | 3 days before expiry | Settings → Figures still to confirm |
| Expiry notice | On the expiry day | Settings → Figures still to confirm |
| Payment warning | 3 days after expiry | Settings → Figures still to confirm |
| Suspend request | 7 days after expiry, and not before the grace days | Settings → Figures still to confirm |
| Termination request | 30 days after expiry | Settings → Figures still to confirm |
| Renewal task | 7 days before expiry, when it does not renew itself | Settings → Figures still to confirm |
| Renewal list | 30 days ahead | Settings → Figures still to confirm |
| Reconnection fee | $0.00 | Settings → Figures still to confirm |
| Free months a supervisor can approve | 1 | Settings → Figures still to confirm |
| Win-back call | 75 days after the service ends | Settings → Figures still to confirm |
| Health score that means at risk | Below 50 | Settings → Figures still to confirm |
| Churn reasons | price, coverage, moved, competitor, financial, other | Settings → Figures still to confirm |
| Free month, discount, and free upgrade offers | All on | Settings → Figures still to confirm |
| Calls reviewed per agent each week | 5 | Settings → Figures still to confirm |
| Score when a critical quality item fails | 0% | Settings → Figures still to confirm |
| Passing quality score | 80% | Settings → Figures still to confirm |
| Commission on the first month | 10% | Settings → Figures still to confirm |
| Commission when a line is installed | $5.00 | Settings → Figures still to confirm |
| Commission on a collected payment | 0% | Settings → Figures still to confirm |
| Target level that raises the commission | 100% | Settings → Figures still to confirm |
| Commission after that target | 15% | Settings → Figures still to confirm |
| Clawback if the customer leaves | 90 days | Settings → Figures still to confirm |
| Big deal on the floor screen | $100.00 a month | Settings → Figures still to confirm |
| Floor screen event | A won deal (`won`; use `activated` for an installation) | Settings → Figures still to confirm |
| Dashboard refresh | 30 seconds | Settings → Figures still to confirm |
| Report download link | 24 hours | Settings → Figures still to confirm |
| Rows that queue a report | 500 | Settings → Figures still to confirm |
| Report downloads each hour | 10 | Settings → Figures still to confirm |
| Supervisor digest | 08:00, and Monday for the weekly one | Settings → Figures still to confirm |
| Days a dashboard looks back | 30 | Settings → Figures still to confirm |
| Digest recipients | Call-center supervisors and sales managers | Settings → Figures still to confirm |
| Pause every automation | Off | Settings → Figures still to confirm |
| How many times a rule may trigger itself | 3 | Settings → Figures still to confirm |
| Days a practice run looks back | 7 | Settings → Figures still to confirm |
| Times a new rule may run each hour | 30 | Settings → Figures still to confirm |
| Minutes before an urgent unassigned ticket is raised | 10 | Settings → Figures still to confirm |
| Hours until a follow-up call | 24 | Settings → Figures still to confirm |
| Webhook tries before it is given up | 5 | Settings → Figures still to confirm |
| Seconds between webhook tries | 30 | Settings → Figures still to confirm |
| Seconds a webhook signature stays valid | 300 | Settings → Figures still to confirm |
| Days a new API key stays valid | 90 | Settings → Figures still to confirm |
| Hours an import can be undone | 24 | Settings → Figures still to confirm |
| Daily backups to keep | 30 | Settings → Figures still to confirm |
| Monthly backups to keep | 12 | Settings → Figures still to confirm |
| Krypton file import | Off | Settings → Figures still to confirm |
| Minutes a technician stays signed in | 720 (12 hours) | Settings → Figures still to confirm |
| Minutes before cash or a signature asks for the password again | 15 | Settings → Figures still to confirm |

Islamic holiday dates stay on Settings → Holidays and stay marked to confirm until an admin confirms the moon-sighting date.

## External integrations still outstanding

Settings → Integrations is the desk for these. Only a super admin can open it. Secrets stay in the environment and are never shown again after they are set. See `docs/INTEGRATIONS.md`.

- Hub ERP (ERPNext at `https://erp.thehubholding.com`). The live company is Smart Network, in Lebanese pounds. Stock requests are draft Material Requests and stay off until `FIBES_ERPNEXT_ENABLED`, `FIBES_ERPNEXT_API_KEY`, and `FIBES_ERPNEXT_API_SECRET` are set. The Hub user does not have API Access yet. The signed webhook at `/api/v1/hooks/erpnext` stores a notice and does not change stock.
- Grandstream PBX. The screen-pop stays off until `FIBES_TELEPHONY_SECRET` is set. Click-to-dial stays off until that secret, `FIBES_TELEPHONY_URL`, `FIBES_TELEPHONY_USER`, `FIBES_TELEPHONY_PASSWORD`, and the desk switch are set. Abandoned calls stay an empty list until the PBX pushes a signed event. FIBES does not poll the UCM.
- Krypton. Live calls stay off until `FIBES_RADIUS`, `FIBES_RADIUS_API_KEY`, `FIBES_RADIUS_URL`, and the desk switch are on. Until then, suspension and reactivation only leave a task. A CSV import exists and stays off until `krypton_import` is turned on in Settings. The REST paths are assumed and documented in `docs/INTEGRATIONS.md`.
- Whish. Live checkout stays off until `FIBES_WHISH`, `FIBES_WHISH_API_KEY`, `FIBES_WHISH_URL`, and the desk switch are on. A payment reference is typed by hand until then. The webhook at `/api/v1/hooks/whish` stays refused until `FIBES_WHISH_WEBHOOK_SECRET` is set.
- WhatsApp. Links are `wa.me` only, and only when the customer has agreed, until `FIBES_WHATSAPP`, `FIBES_WHATSAPP_TOKEN`, `FIBES_WHATSAPP_PHONE_ID`, and the desk switch are on. Templates still require that consent. The inbound webhook stores delivery status and does not reply.
- Floor-screen sounds. The files in `public/tv/sounds` are placeholders.
- Reverb and Horizon. They are not installed. The current Laravel HTTP stack conflicts with the Guzzle library those packages need. The sales floor polls every 2 seconds, the bell every 30 seconds, and the queue container runs `php artisan queue:work`.
- Meilisearch, MinIO, and Gotenberg are in the production compose file and are not wired into the screens yet.
- FiberDesk and Meta Lead Ads stay off until their secrets are set.

## Numbered steps for hosting

Sam does these on the FIBES machine. This repository does not log in to that machine.

1. Pick the Ubuntu LTS server in the data center. CPU, RAM, and disk size are still open.
2. Install Docker Engine and the Compose plugin. Do not publish Postgres or Redis to the internet.
3. Choose remote access for field staff. Either a public name with TLS, only port 443 open, and fail2ban or a firewall, or a WireGuard VPN. See `docs/REMOTE_ACCESS.md`. Sam decides.
4. Point DNS at the server and install the certificate. Set `APP_URL` to that `https://` address and `SESSION_SECURE_COOKIE=true`.
5. Copy `.env.example` to `.env`. Generate a new `APP_KEY`, `DB_PASSWORD`, `ADMIN_PASSWORD`, and `FIBES_BACKUP_KEY`. Store `APP_KEY` and `FIBES_BACKUP_KEY` offline. Set `FIBES_BACKUP_DESTINATION` to another disk and `FIBES_BACKUP_SCRATCH` to a database path that is not the live one.
6. Set `FIBES_FILE_SCAN_DRIVER=clamav` and `SENTRY_DSN` when the error tracker exists.
7. Build and start the stack: `docker compose -p fibes-prod -f docker-compose.prod.yml up -d --build`. The app container runs additive migrations and the production seed (roles, Lebanon’s places, settings, and the first super admin). It does not run the 30,000-customer load seed.
8. Open `/health`. It should say the database is ok. Do not continue if it does not.
9. Sign in as the super admin, change the password, and turn on an authenticator.
10. Fill Settings, including the figures in this file and the Islamic holidays.
11. Create one user per staff member and check the authenticator roles.
12. Run one backup and one restore test into the scratch database. Read `docs/BACKUP_RESTORE.md`.
13. Run UAT for one week on a staging copy. See `docs/UAT.md`.
14. Import coverage, count stock, and agree the cut-over for the roughly 3,000 customers.
15. After Sam approves a git tag, on the server run `FIBES_DEPLOY_EXECUTE=1 scripts/deploy.sh <tag>`. If `/health` is not ok, the script restores the previous tag record and stops. See `docs/DEPLOY.md`.
16. Keep two weeks of hypercare. Roll back with `scripts/rollback.sh` on the server if a release must be undone.
