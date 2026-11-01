# Monitoring

`scripts/monitor.sh` prints disk use and, when `FIBES_HEALTH_URL` is set, the health status. It does not log in to another machine. Postgres size, the Redis queue length, and certificate expiry are reported only when those tools are on the server and the variables are set. An alert line is printed when the disk is at 90% or health is not 200.

Check, at least daily:

- `/health` is ok.
- Free disk on the database volume and on `FIBES_BACKUP_DESTINATION`.
- Postgres size (`SELECT pg_database_size(current_database());`) once the server is up.
- Queue length and failed jobs (`php artisan queue:monitor redis:default` or `php artisan queue:failed`). Horizon is not installed.
- The TLS certificate expiry date.
- That `SENTRY_DSN` received a test error after go-live.

Send the alert to the super admins. The script itself only prints. Wire the printout to mail on the server when Sam chooses the mailbox.
