# Moving Railway from `railway` to `updates` — plan

Status on 2026-11-10: **plan only. Nothing in Railway has been changed.**

## What each branch has today

- **`railway`** (live; tip `b15d0b2`) = `main` (`dd59a77`) **plus five commits**:

  | Commit | Date (UTC) | What |
  | --- | --- | --- |
  | `a65dffc` | 2026-11-02 00:01 | Adds `Dockerfile.railway` (Apache + PHP 8.3 single container), `docker/railway/apache.conf`, `railway.json` (build from `Dockerfile.railway`, health check `/up`). The entrypoint itself is the one `main` already has. |
  | `f05d23c` | 2026-11-02 00:21 | Railway: force `mpm_prefork`, log the enabled MPMs. |
  | `ab8b83b` | 2026-11-02 00:31 | Railway: remove stray MPM symlinks at start. |
  | `6e124f3` | 2026-11-08 16:56 | Merge `main` into `railway`. |
  | `b15d0b2` | 2026-11-08 23:10 | Merge `main` into `railway` again (brings #10 seed guard and #12 sign-in). |

  Net effect: `railway` differs from `main` only by three deploy files — `Dockerfile.railway`, `docker/railway/apache.conf`, `railway.json`. No app code.

- **`main`** has 12 commits that `updates` does not: the fibes.net brand restyle, the attention-desk shell, provider topology view, dashboard first-paint speed-up, Symfony advisory fixes, the #10 seed guard and the #12 sign-in refresh. `railway.toml` (which points at `Dockerfile.render`) is also only on `main`.
- **`updates`** has 3 commits `main` does not (#11, the same sign-in refresh as #12 made on the other branch) and none of the Railway files.

## What would go live if Railway deployed `updates` today

Do **not** do this yet:

1. **No `railway.json` / `Dockerfile.railway`** on `updates`, so Railway would not build today's Apache image. Depending on the service settings it would fail the build or build something else. Either way it is not the image that is live now.
2. The app would **lose** everything that is only on `main`: the brand restyle, the attention desk, topology and the dashboard speed-up. The UI would visibly go back.
3. The open fixes (#18–#34) would not be there yet. Without #31, `docker/render/start.sh` on `updates` still runs `db:seed` on every boot.

## Safe sequence

1. **Merge the PR chain into `updates`** in order, with merge commits (not squash): #18 → #19 → #20 → #22 → #23 → #21 → #24 → #25 … #31 → #33 → #34 → the PRs that follow this checklist. CI must be green on each.
2. **Merge #17, then #32.** #17 brings all of `main` into `updates`, which closes item 2 above. The only expected conflict is `CHANGELOG.md`: keep both entries.
3. **Bring in the Railway deploy files.** The `/storage` redirect PR adds `Dockerfile.railway`, `docker/railway/apache.conf` (with the redirect fix) and `railway.json` to `updates`, identical to `railway` apart from the fix. Check `git diff origin/railway origin/updates -- Dockerfile.railway railway.json docker/railway/` shows only that fix.
4. **Check the variables (read them yourself in Railway; no change is expected):**
   - `QUEUE_CONNECTION` is `database` or unset (#28).
   - `FIBES_RUN_SEED` is unset.
   - `TRUSTED_PROXIES` is unset (#34's default fits Railway).
   - `APP_KEY` and `DB_PASSWORD` are set; the entrypoint refuses to start without them.
5. **Verify before going live** (pick one):
   - **Preferred:** in Railway, create a temporary environment or service from `updates`. It needs its own empty or copied database, **never** the live one with `FIBES_RUN_SEED`. Run `scripts/smoke-check.sh <its domain>` and the manual steps in `docs/post-deploy-checklist.md`.
   - **Lighter:** `docker build -f Dockerfile.railway .` locally and boot it against a throwaway Postgres.
6. **Go live**, in a quiet hour:
   - **Option A (recommended): fast-forward `railway`.** First merge `railway` into `updates` with a PR; only the three deploy files plus the apache fix overlap. Then `git push origin updates:railway`, a plain fast-forward with no force. Railway settings stay untouched.
   - **Option B: switch the service's source branch** to `updates` in Railway → Settings → Source. One click, but it changes a service setting and every future `updates` merge then deploys straight away.
7. **Right after the deploy:** run `docs/post-deploy-checklist.md`.

## Rollback

- **Fastest (1 minute):** Railway → Deployments → the previous successful deployment → **Redeploy**. This reuses the old image; no git change is needed.
- **Then stop new deploys from the bad commit:**
  - Option A: move `railway` back to the old tip. That needs a force push (`git push --force-with-lease origin b15d0b2:railway`) and an explicit OK.
  - Option B: switch the source branch back to `railway`.
- **Database:** none of #18–#34 change the schema, so the old code runs against the same database. Do not run `migrate:rollback` or seed during a rollback.
