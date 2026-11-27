# EA-9 measurement

Enterprise audit EA-9 (findings M-01, P-14): measure Dot.docs under load before any claim about scale. The audit
scored performance 4.0 with the note "nothing has ever been measured".

**Status (2026-11-27):** measured on a laptop, not yet on the host. The owner chose "local now, staging later":
section 4 is the run on a staging copy of the real host, still to do.

## 1. Method

`php artisan doc:bench` (app/Console/Commands/Benchmark.php). It refuses to run when `APP_ENV=production`.

- `doc:bench seed --documents=N` adds a "Benchmark" company: one owner and ten writers (all `@bench.invalid`, random
  passwords nobody knows), N numbered documents of about 4 KB each (every 100th one carries a rare search word), one
  document with 300 versions, and one ~25 KB working document per writer. Running it again tops up to N.
- `doc:bench run` drives the real HTTP endpoints through the web server, every middleware, the session store and the
  one SQLite file. Signed-in sessions are written straight into the app's session store with the app's own key, so it
  needs no password and no central sign-in.
  - **Typing:** K writers (1, 3, 10), each in their own document. Every round all K autosave **at the same instant**
    (the worst alignment for SQLite's single write lock) and poll `/sync`, then wait for the editor's 1.5 s cadence.
    30 rounds per K, so 30·K saves. Each save changes the text, so each one is a real write.
  - **Reads**, one person, one request at a time: the documents list, a rare-word and a common-word search, the
    history page of the 300-version document, an editor page, and Markdown, Word and PDF exports.
  - Every response is checked: a save must answer JSON and a page must be a whole HTML page. A PHP error served as 200
    counts as `bad`, never as a fast success.
- Times are measured at the client, end to end. Lock waits are not visible on their own (SQLite's `busy_timeout`
  waits inside the request), so they show up as the growth from 1 to 10 writers.

**Local machine:** Apple M4 (10 cores), PHP 8.5, PHP's built-in server with 12 workers, SQLite in WAL mode on the
local SSD, database sessions and cache (as on the host). This is not LiteSpeed on shared hosting: the host's own
response time was about 1 s from outside when the audit was written, so treat these numbers as a floor and as
**before and after** comparisons, not as what a customer sees.

**Before EA-5** is commit `27c097c` (the last commit before EA-5 merged) with its own database, migrated by its own
migrations, so without EA-5's indexes. **After** is `main` today, with and without the fix in section 3.

## 2. Results (local)

### Typing: autosave and sync poll, milliseconds

| Writers at once | Code | Save p50 | p95 | p99 | max | Poll p50 | p95 | p99 | Failed |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | before EA-5 | 32 | 44 | 97 | 97 | 31 | 50 | 102 | 0 |
| 1 | now | 27 | 36 | 43 | 43 | 22 | 33 | 38 | 0 |
| 3 | before EA-5 | 41 | 87 | 117 | 117 | 51 | 99 | 113 | 0 |
| 3 | now | 31 | 45 | 54 | 54 | 27 | 48 | 68 | 0 |
| 10 | before EA-5 | 90 | 231 | 303 | 424 | 82 | 213 | 362 | 0 |
| 10 | now | 57 | 168 | 230 | 263 | 47 | 124 | 155 | 0 |

Before EA-5: 1,000 documents. Now: 10,000 documents. At 10,000 documents the before-EA-5 code gave the same picture
(10 writers: save p50 93, p95 224, p99 268). The number of documents does not change the cost of a save.

Read: with ten people typing **at the same instant**, every save landed, none waited near the 5 s lock timeout,
and 99 in 100 finished within a quarter of a second. EA-5 took about a third off saves and polls under load.

### Reads at 10,000 documents, milliseconds

| Request | Before EA-5 p50 | Now, before fix p50 | Now, with fix p50 | p95 with fix |
| --- | ---: | ---: | ---: | ---: |
| Documents list | 2,390 | 2,379 | **87** | 415 (one cold first request; the rest under 100) |
| Search, rare word | 2,323 | 2,446 | **110** | 116 |
| Search, common word | 2,499 | 2,512 | **212** | 223 |
| History, 300 versions | 36 | 27 | 22 | 26 |
| Editor page | 29 | 29 | 25 | 27 |
| Export Markdown | 17 | 13 | 16 | 17 |
| Export Word | 25 | 19 | 21 | 24 |
| Export PDF | 57 | 55 | 55 | 62 |

At 1,000 documents the list took about 210 ms on both old and current code. It grew in step with the number of
documents, to about 2.4 s at 10,000.

## 3. What the measurement found, and the fix in this PR

**The documents list loaded every document in the folder.** To show the current folder's subfolders, the list asked
for *all* of the folder's children, with each document's full content (`select *`), and then kept the folders.
A document nobody files in a folder sits at the top of the company's tree, so a company with 10,000 unfiled
documents read 10,000 whole documents into PHP on every visit to the list, and on every search (the search runs on the
same page). SQL took a tenth of the time; turning those rows into objects took the
rest. EA-5's narrower list query did not reach it, because the load came from the subfolder strip and not from the
list itself.

Fixed:

- `FilesService::childFolders()` asks the database for folders only. The documents list and the Move picker
  (`LocationPicker`) use it.
- `UniqueName`, which runs on every new folder, upload and rename, read every sibling with its content just to
  compare names. It now reads document siblings' id and title only: 0.4 s in a 10,000-document folder, and still adds
  "(2)" on a clash.

Not changed here, recorded for later:

- **The Files page lists a whole folder** (`FilesService::children()`), by design: it is the tree view. A folder with
  thousands of documents makes that page as slow as the list was. It needs paging, which is a product change
  (the Files page is shared with Dot.Files).
- **A common-word search** is still about 0.2 s at 10,000 documents (`LIKE` over every document's text, P-07).
  Acceptable at this size; a full-text index is the step after.

## 4. On the host (owner step, still to do)

The audit asks for the same run on the real host type. It needs a **staging copy**: its own subdomain, folder and
database on the same hosting, so the load never touches production data. It does share the server's CPU with
production while it runs (about 5 minutes), so run it at a quiet time.

Every command below runs in the **cPanel Terminal**, as the account user. Nothing here touches the production
folder except reading `vendor/` and `public/build/` from it.

1. **cPanel → Domains → Create a new domain:** `staging-doc.infodot.co.za`, document root
   `staging-doc.infodot.co.za/doc/public`. Leave "Share document root" off.
2. **Copy the code** of the release that is live:

   ```bash
   cd ~ && git clone https://github.com/sakhilebhayi/Dot.docs.git staging-doc.infodot.co.za/doc
   cd ~/staging-doc.infodot.co.za/doc && git checkout "$(git -C ~/doc.infodot.co.za/doc rev-parse HEAD)"
   cp -R ~/doc.infodot.co.za/doc/vendor . && cp -R ~/doc.infodot.co.za/doc/public/build public/
   ```

3. **Its own settings and database.** Start from production's `.env`, then point everything at staging:

   ```bash
   cp ~/doc.infodot.co.za/doc/.env .env
   sed -i -e 's#^APP_ENV=.*#APP_ENV=staging#' -e 's#^APP_URL=.*#APP_URL=https://staging-doc.infodot.co.za#' \
     -e 's#^DB_DATABASE=.*#DB_DATABASE='"$HOME"'/staging-doc.infodot.co.za/doc/database/database.sqlite#' \
     -e 's#^MAIL_MAILER=.*#MAIL_MAILER=log#' -e '/^BACKUP_PASSPHRASE=/d' \
     -e '/^DOTSSO_ENABLED=/d' -e '/^DOCS_ENTITLEMENT_CHECK=/d' .env
   printf '\nDOTSSO_ENABLED=false\nDOCS_ENTITLEMENT_CHECK=false\n' >> .env
   php artisan key:generate --force
   touch database/database.sqlite && php artisan migrate --force
   grep -E '^(APP_ENV|APP_URL|DB_DATABASE)=' .env
   ```

   Expected: `APP_ENV=staging`, the staging URL, and a `DB_DATABASE` path under `staging-doc.infodot.co.za`. If
   any line still names the production folder, stop there.
4. **Seed and run** (about 5 minutes):

   ```bash
   php artisan doc:bench seed --documents=10000
   php artisan doc:bench run --typists=1,3,10 --out=storage/bench-host.json
   ```

   Paste the two tables it prints. A row of `403` or a page of firewall HTML means the host's edge firewall
   blocked the burst; say so and we lower the load.
5. **Remove it** once the numbers are in: delete the domain in cPanel, then
   `rm -rf ~/staging-doc.infodot.co.za`.

No cron entry is added for staging, so it never sends mail, backs up or runs the queue.

## 5. 2026-11-27: the full-text index on every typing save (R2-9)

An independent audit asked whether the SQLite FTS5 index (`documents_fts`, R2-5) costs too much inside the write lock:
its trigger `documents_fts_au` re-indexes the whole document (delete the old text, add the new) on every save that
changes the title or `search_text`, which is every save that changes the words.

**Method.** A throwaway script outside the repository, not part of the app: it boots the app against a new file-backed
SQLite database in WAL mode (journal_mode answered `wal`, the app's own connection settings: busy timeout, IMMEDIATE
transactions), migrates, seeds the styles, creates 300 filler documents so the index is not empty, and one working
document of about 25 KB of text (25,190 bytes of `search_text`, 34 KB of JSON). It then times `DocumentStore::save()`
of that document 300 times, each with different words (so each save rewrites `search_text` and fires the trigger), with
the triggers, after `FullTextIndex::uninstall()`, and again after `install()`. Each round also times the bare
`UPDATE documents SET search_text = ?` on its own, which is the part the trigger is added to. 20 warm-up saves first;
two runs. Laptop: Apple M4, PHP 8.5, SQLite 3.53, local SSD.

| Per save, milliseconds | p50 | p95 |
| --- | ---: | ---: |
| `DocumentStore::save()`, with the triggers | 2.64 - 2.67 | 2.88 - 4.24 |
| `DocumentStore::save()`, without | 2.43 - 2.44 | 2.55 - 2.62 |
| `DocumentStore::save()`, with again | 2.63 - 2.64 | 2.87 - 3.23 |
| bare `UPDATE ... search_text`, with the triggers | 0.32 - 0.33 | 0.40 - 0.70 |
| bare `UPDATE ... search_text`, without | 0.14 | 0.17 - 0.22 |

**Read.** Re-indexing a 25 KB document adds about 0.2 ms to a save at the median and at most about 1.6 ms at p95,
under the 5 ms the audit set as material. No change made: the index stays trigger-maintained. It already fires only
when it must: the trigger is `AFTER UPDATE OF title, search_text`, and Eloquent puts a column in the UPDATE only when
its value changed, so a save that changes formatting but no words (same `search_text`) never re-indexes. Hosts are
slower than this laptop (section 1); the ratio, not the absolute number, is what carries over.

**Also in R2-9** (no new numbers; behaviour pinned by tests):

- The sync poll no longer writes the session row when it changed nothing in it (`App\Http\Middleware\StartSession`,
  `QuietSession` on `POST /documents/{uuid}/sync`); it still writes once every 5 minutes to keep the session alive.
  With database sessions that was one write-lock acquisition per poll per open tab (`SyncQuietSessionTest`).
- When the document has changed, the outline and canvas CSS a poll returns are built once per state of the document
  and shared by every co-editor's poll for 60 s (`App\Documents\Outline\SyncDerived`, `SyncDerivedCacheTest`).
- New SQLite databases are created with incremental auto-vacuum; the existing file is converted by hand
  (`docs/programme/RESTORE_RUNBOOK.md`, section 5).
