# Backups and restore tests

A nightly job writes an encrypted backup at 02:00. On the first day of the month it also keeps a monthly copy. Settings decide how many daily copies (30) and monthly copies (12) to keep. If `FIBES_BACKUP_DESTINATION` is a folder on this machine, the new file is copied there. Put that folder on another disk or another server.

`FIBES_BACKUP_KEY` is the encryption key. It stays in the environment. A backup file is not readable SQL.

On PostgreSQL, point the nightly job at `pg_dump` and turn on WAL archiving so you can restore to a point in time:

```
wal_level = replica
archive_mode = on
archive_command = 'test ! -f /var/backups/wal/%f && cp %p /var/backups/wal/%f'
```

The monthly restore test loads the latest backup into `FIBES_BACKUP_SCRATCH` and emails the row counts to the admin. It refuses to run in production, and it refuses if the scratch database is the live one. `scripts/restore-test.sh` checks that before it starts.

The import screen is an admin tool. It is not part of go-live. A file with a renamed or missing column is rejected and nothing is saved. A practice check and the real save report the same counts. An import can be undone for 24 hours, which archives the rows it added.

The Krypton file import stays off until `krypton_import` is turned on in Settings. It only reads a CSV file. It does not connect to Krypton. People already in the database but missing from that file are listed, with a phone suggestion when the last six digits match a row in the file. Excel workbooks are not accepted.

Customer downloads follow the same visibility rules as the customer list and are written in the audit log. A download at or above the report row threshold is queued. The link stops working after 24 hours.
