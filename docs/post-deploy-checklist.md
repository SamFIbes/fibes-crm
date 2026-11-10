# Post-deploy checklist

Run this after every deploy of fibes.io (or staging). It takes about five minutes.
Nothing here writes to production. The script sends no credentials.

## 1. Automatic checks (2 minutes)

From your own computer (not from inside Railway):

```sh
scripts/smoke-check.sh                # https://www.fibes.io
scripts/smoke-check.sh staging.host   # another host
```

It prints `PASS` / `FAIL` / `WARN` per line and exits `1` if anything failed. It checks:

| Check | Expected |
| --- | --- |
| `http://<host>/login` | `301`/`308` to `https://…` |
| HSTS on `https://<host>/login` | `Strict-Transport-Security: max-age=…` (missing means the app does not see the request as https — see `TRUSTED_PROXIES` in `docs/DEPLOY.md`) |
| `/login` | `200`, Inertia page, no `host:port` links |
| `/up`, `/health` | `200`; `/health` says `"status":"ok"` (database reachable) |
| `/build`, `/storage/` redirects | never to `http://` or a `:port` |
| Webhooks with bad/unsigned input: web-form, Meta (verify + POST), ERPNext, Whish, WhatsApp (verify + POST), telephony | a `4xx`. A `503` is a `WARN`: that integration is switched off because no secret is set, which is expected until it is connected |
| Automations ping without a key, TV feed without a token, export download with a made-up token | a `4xx` |

The bad webhook requests are rejected by the signature check before any database work, so they create nothing.

## 2. Manual: sign-in (1 minute)

Use a private/incognito window.

1. Open `https://<host>/login`. The page is styled (no plain HTML), the padlock shows, and there are no console errors about mixed content.
2. Sign in with a **wrong** password once: you see "Those credentials do not match our records." — not a 500.
3. Sign in with your real account. You land on your home page (dashboard). The page shows your name.
4. Open two other sections from the menu (e.g. Customers, Tickets). Both load.
5. Sign out. You are back on `/login`, and reloading a dashboard URL sends you to `/login` again.

## 3. Manual: two-factor (2 minutes)

Use an account that has 2FA on (a staff test account, not a customer).

1. Sign in with email and password. You are asked for the 6-digit code.
2. Enter a **wrong** code once: you see an error, not a 500, and can try again.
3. Enter the current code from the authenticator app: you are signed in.
4. Sign out, sign in again and reuse the **same** code within 30 seconds: it is refused (codes work once — #22).
5. Optional, only with a spare recovery code you are willing to burn: sign in with a recovery code instead. It works once; the same code is refused the second time.

## 4. Logs (1 minute)

In Railway → the fibes-crm service → **Deploy logs** for the new deployment:

- `Database seeding skipped (FIBES_RUN_SEED is not true).` appears (unless you deliberately seeded).
- No `ERROR` or stack traces after start-up.
- The queue worker is running (no repeated `queue:work` crashes).

## If something fails

- `HSTS missing`, unstyled pages or `http://` links: set `TRUSTED_PROXIES=*` on the service as a temporary fix and report it.
- `/health` is `503`: the database is unreachable — check the Postgres service.
- Anything worse: roll back (Railway → Deployments → the previous successful deployment → **Redeploy**). See `docs/deploy-branch.md`.
