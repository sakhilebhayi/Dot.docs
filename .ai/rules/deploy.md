---
paths:
  - '.github/workflows/*.yml'
  - 'public/index.php'
  - 'public/.htaccess'
---

# Deployment

## doc.infodot.co.za's real docroot is NOT DEPLOY_PATH/public - it's DEPLOY_PATH's PARENT directory
Confirmed live via `uapi DomainInfo single_domain_data domain=doc.infodot.co.za` on the server: the configured document root for the domain is `/home/infodotc/doc.infodot.co.za`, and `DEPLOY_PATH` (`/home/infodotc/doc.infodot.co.za/doc`) is a SUBDIRECTORY of it, not the same thing. This is a shared-hosting "flattened" Laravel layout from the original manual deploy: a hand-written `index.php` sits directly at the docroot and boots DEPLOY_PATH's vendor/bootstrap (`require __DIR__.'/doc/vendor/autoload.php'` etc.) - a DIFFERENT relative path than this repo's own `public/index.php` (`__DIR__.'/../vendor/autoload.php'`), which assumes `public/` sits directly inside the Laravel root. Every other static file under `public/` (`build/`, favicons, `robots.txt`, `sw.js`, `images/`) has its OWN separate copy sitting directly at the docroot.

`deploy.yml`'s "Transfer public assets to the real docroot" step rsyncs there (`$(dirname "$DEPLOY_PATH")`), never `index.php` (overwriting it with this repo's version would break every request instantly - wrong relative paths), with `build/` getting its own `--delete`-scoped sync (so old content-hashed files don't accumulate) and everything else getting a `--delete`-free sync (the docroot also holds `doc/` itself, an old `Dot.docs.tar.gz`, and `.well-known` for ACME/SSL - none exist under this repo's `public/`, so a `--delete` sync of the whole docroot would wipe all three).

**The incident this fixed:** for days (PRs #5-#10), every deploy correctly updated `DEPLOY_PATH/public/build/` - a path the webserver never serves - so Laravel kept reading its own current `manifest.json` and emitting correct asset hashes, while the browser's request for those exact URLs 404'd against the real, untouched-since-April docroot copy. Looked exactly like a stale LiteSpeed cache (and `public/.htaccess`'s `CacheLookup off` never even reached the real docroot's `.htaccess` for the same reason, so that "fix" was never live either); it was a wrong rsync destination the whole time. Lesson: on shared hosting, never assume `DEPLOY_PATH/public` IS the docroot - verify with `uapi DomainInfo single_domain_data domain=<domain>` before trusting any path-based deploy assumption.
