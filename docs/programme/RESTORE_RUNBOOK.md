# Dot.docs restore runbook

Enterprise audit EA-6 (findings O-01..O-05). Every command below runs in the cPanel Terminal (or SSH) as the account user,
from the app folder:

```bash
cd ~/doc.infodot.co.za/doc
```

Nothing here is run by a deploy. Read a section to the end before starting it.

## What exists

| Copy | Where | When | Kept |
| --- | --- | --- | --- |
| Nightly database | `storage/backups/doc-YYYY-MM-DD.sqlite` (`.enc` when encrypted) | 02:35 every night (`doc:backup-database`) | newest 14 |
| Nightly uploads (`storage/app`) | `storage/backups/doc-files-YYYY-MM-DD.tar.gz` (`.enc` when encrypted) | with the database | newest 14 |
| Pre-deploy database | `storage/backups/predeploy-<time>-<commit>.sqlite` | before every deploy changes anything | newest 5 |

Each database copy is checked with `PRAGMA integrity_check` when it is made; a copy that fails is deleted and the run
fails. `/up/scheduler` answers 503 when the newest database backup is more than 26 hours old, and the GitHub
watchdog emails the owner.

**Encryption.** With `BACKUP_PASSPHRASE` in `.env` the nightly copies are encrypted (libsodium, Argon2id key) and the
plain files removed. **Without the passphrase they cannot be restored**: keep it in a password manager. Pre-deploy copies
are not encrypted (they are made by the deploy with `sqlite3`, outside the app); they stay on the host only.

**Off the host.** All of the above sits on the same disk as the live database. A copy somewhere else is the owner's step
(owner list in `docs/programme/ENTERPRISE_AUDIT.md`): cPanel's backup to a remote destination, or a bucket.

## 1. Drill: restore to a scratch file (do this once now, then every quarter)

Nothing live is touched.

```bash
php artisan doc:backup-database
ls -lt storage/backups | head -5
php artisan doc:restore-backup "$(ls -t storage/backups/doc-2*.sqlite* | head -1)"
```

Expected last line: `Restored to …/storage/restore/doc-YYYY-MM-DD.sqlite: integrity ok, N documents, M users.` Compare N and M
with the live database:

```bash
sqlite3 database/database.sqlite "SELECT count(*) FROM documents; SELECT count(*) FROM users;"
```

Record the date, how long it took and the counts in the audit's owner list (O-02). Then remove the scratch copy:

```bash
rm -rf storage/restore
```

## 2. Put a database backup live (data was lost or damaged)

1. **Restore to a scratch file first** (section 1, with the backup you want; for a failed deploy that is the newest
   `predeploy-…sqlite` the deploy log names). It must say `integrity ok`.
2. **Stop writes:**

   ```bash
   php artisan down --retry=60
   ```

3. **Keep the current file**, even if it is damaged:

   ```bash
   cp database/database.sqlite "storage/backups/before-restore-$(date -u +%Y%m%dT%H%M%SZ).sqlite"
   ```

4. **Swap it in** and remove SQLite's side files of the old one:

   ```bash
   cp storage/restore/<the restored file>.sqlite database/database.sqlite
   rm -f database/database.sqlite-wal database/database.sqlite-shm
   sqlite3 database/database.sqlite "PRAGMA journal_mode=WAL; PRAGMA integrity_check;"
   ```

   Expected: `wal` then `ok`.
5. **Match the code to the data.** If the backup predates a deploy that added migrations, either deploy the release the
   backup came from, or run `php artisan migrate --force` to bring it forward (check `php artisan migrate:status` first).
6. **Go live and check:**

   ```bash
   php artisan config:cache && php artisan up
   curl -s -o /dev/null -w '%{http_code}\n' https://doc.infodot.co.za/up
   ```

   Then open a document in the browser.

Anything written between the backup and the restore is lost; say so to the people affected.

## 3. Restore uploaded files

```bash
php artisan doc:restore-backup "$(ls -t storage/backups/doc-files-*.tar.gz* | head -1)"
mkdir -p storage/restore/files && tar -xzf storage/restore/doc-files-*.tar.gz -C storage/restore/files
```

Inspect `storage/restore/files`, then copy back only what is missing (for example `cp -n -R storage/restore/files/public/.
storage/app/public/`). `-n` never overwrites a file that is there.

## 4. A deploy failed

- **The deploy put the previous release back itself** (log: "Deploy failed; putting release … back"): the site is up
  on the old code. If the failure was in a migration, check `php artisan migrate:status`; if a migration ran part-way,
  restore the `predeploy-…` copy the log names (section 2).
- **The smoke check failed after going live** (assets or pages broken): roll the code back by hand to the previous
  commit, then redeploy it from GitHub so the assets match:

  ```bash
  git log --oneline -3
  ```

  then in GitHub: revert the merge on `main` (a new PR), which deploys the previous state with its own assets.
