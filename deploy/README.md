# Deploying Dot.Doc to doc.infodot.co.za

This mirrors the pattern already live for Dot.Mines and Dot.Memory: GitHub
Actions connects over SSH to an already-provisioned server and runs a plain
`git`-based release. Nothing deploys automatically — every run is a manual
click (or `gh workflow run`) with a typed confirmation, so a bad merge to
`main` never reaches production by itself.

## What you need to do once, before the first bootstrap run

These are steps only you can do — they touch real credentials, DNS, and
server administration that should never pass through an AI assistant.

1. **Confirm the server is ready.** PHP 8.5, Composer, the `sqlite3` PHP
   extension (bundled with most PHP installs), `sudo` access for the
   deploy user (to install the two systemd units), and a way to serve the
   app over HTTPS (nginx/Apache reverse-proxying to php-fpm, plus
   proxying `wss://` traffic to `127.0.0.1:8080` for Reverb — see
   `deploy/reverb.service`'s comment). No database server to provision —
   production runs SQLite, a single file inside the deploy path that
   `bootstrap.yml` creates for you.
2. **Point DNS.** `doc.infodot.co.za` needs an A/AAAA record at your
   server's IP (or a CNAME if it's behind a load balancer).
3. **Generate a deploy SSH keypair** dedicated to this pipeline (don't
   reuse your personal key):
   ```bash
   ssh-keygen -t ed25519 -f dot_doc_deploy -N "" -C "dot-doc-deploy"
   ```
   Add `dot_doc_deploy.pub` to the deploy user's `~/.ssh/authorized_keys`
   on the server. Get the server's host key for `known_hosts`:
   ```bash
   ssh-keyscan -H doc.infodot.co.za
   ```
4. **Add the repository secrets** (Settings → Secrets and variables →
   Actions, in the `sakhilebhayi/Dot.docs` repo). Use `gh secret set` from
   your own terminal, or the GitHub web UI — either way, only you ever
   see or type the actual values:
   ```bash
   gh secret set DEPLOY_SSH_KEY < dot_doc_deploy          # the private key
   gh secret set DEPLOY_KNOWN_HOSTS < known_hosts_output   # from ssh-keyscan above
   gh secret set DEPLOY_USER --body "<the deploy user, e.g. deploy>"
   gh secret set DEPLOY_HOST --body "doc.infodot.co.za"
   gh secret set DEPLOY_PATH --body "/var/www/doc.infodot.co.za"
   gh secret set PROD_ANTHROPIC_API_KEY --body "<optional — leave unset to stay in mock mode>"
   ```

## Running it

1. **Bootstrap once, on a brand-new deploy path:**
   ```bash
   gh workflow run bootstrap.yml -f confirm=bootstrap
   ```
   Clones the repo, builds and ships frontend assets, creates the SQLite
   database file and `.env` (only if one doesn't already exist — safe to
   re-run), runs migrations and the two required seeders (document
   styles, starter templates), and installs + starts the
   `dot-doc-reverb` and `dot-doc-queue-worker` systemd units.

2. **Every deploy after that:**
   ```bash
   gh workflow run deploy.yml -f confirm=deploy
   ```
   Pulls `main`, reinstalls dependencies, runs any new migrations, ships
   fresh built assets, gracefully restarts the queue worker and Reverb,
   and takes the app in and out of maintenance mode only for the brief
   migration window.

## What CI (`.github/workflows/ci.yml`) already covers, separately

Every push to `main` and every pull request runs the full PHP + JS test
suite, Pint, PHPStan (against `phpstan-baseline.neon` — only *new* static
analysis findings fail the build, not the pre-existing ones), and a
`composer audit` / `npm audit` pass. The two audit jobs report known
advisories but never block the merge gate — upgrading a flagged dependency
is a deliberate decision to make separately, not something a routine PR
should be forced into.

## Known gaps, on purpose

- **No blue-green / zero-downtime code swap.** `git reset --hard` plus a
  brief `artisan down` during migrations is the same trade-off Dot.Mines
  and Dot.Memory already make in production.
- **No automatic rollback.** If a deploy goes wrong, redeploy the last
  good commit: `git -C <path> reset --hard <sha>` on the server, or
  re-run `deploy.yml` after reverting `main`.
- **AI stays in mock mode until `PROD_ANTHROPIC_API_KEY` is set.** Every
  AI feature works with deterministic mock output in the meantime — see
  `.ai/rules/ai.md`.
- **SQLite instead of a dedicated Postgres database, for now.** Two real
  trade-offs come with that, both already-supported fallback paths rather
  than bugs:
  - Full-text search runs on the `LIKE`-based fallback in
    `App\Search\DocumentSearch` instead of Postgres `tsvector`/`ts_rank`
    ranking — functional, but lower-quality relevance ranking at scale.
    See `.ai/rules/search.md`.
  - A couple of `pgsql`-only partial unique indexes (e.g. the system
    document style's key uniqueness in
    `2026_09_07_000003_create_document_styles_table.php`) are skipped on
    SQLite, so that one constraint is enforced at the application layer
    only, not the database layer, in production. The shared Dot.Files
    tree's uniqueness constraint already has a working SQLite path and is
    unaffected.
  - Moving to a dedicated Postgres database later is a config-only change
    (`DB_CONNECTION`/`DB_*` in `.env` plus re-running the two guarded
    migrations) — no application code depends on SQLite specifically.
