# Deploy and rollback

Run these scripts on the FIBES server, after Sam approves a tag. They do not run from CI. A copy of the script that is not on that server, and that does not have `FIBES_DEPLOY_EXECUTE=1`, prints “Nothing was contacted” and exits.

## Deploy

1. Record the tag that is running now. The script stores it in `FIBES_DEPLOY_STATE_DIR` (default `/var/lib/fibes-deploy`).
2. `docker compose -p fibes-prod -f docker-compose.prod.yml up -d --build` builds the new app image and starts the stack. Migrations are additive. The queue and the scheduler do not run migrations (`FIBES_RUN_MIGRATIONS=false`).
3. `curl` the health URL (`FIBES_DEPLOY_HEALTH_URL`, default `http://127.0.0.1/health`).
4. If the status is not 200, the previous tag is written back and the script exits. Bring that tag back with `scripts/rollback.sh` and the compose project you already have. Do not run `migrate:fresh`, `db:wipe`, or a down that deletes volumes.

```
FIBES_DEPLOY_EXECUTE=1 FIBES_DEPLOY_HEALTH_URL=http://127.0.0.1/health scripts/deploy.sh 2026-11-01
```

## Rollback

`scripts/rollback.sh` reads `previous-tag` and, on the server, you check that tag out or point the compose project back at the previous image, then start the stack again. The health page must be ok before staff use the site. Volumes are kept, so the database is not dropped.

## What was tested here

The automated test sets `FIBES_DEPLOY_SIMULATE=1` and a health URL that does not answer. The script writes the new tag, sees the failed health check, puts the previous tag back, and exits. No server is contacted.
