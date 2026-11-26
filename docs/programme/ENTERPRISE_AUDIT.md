# Dot.docs enterprise readiness audit — Phase 1 baseline and remediation plan

Brief: the owner's reusable enterprise-readiness audit, `~/Dot/briefs/ENTERPRISE_READINESS_AUDIT.md` (2026-11-26), second run after
InfoDot R-72 (`InfoDot/docs/programme/R72_ENTERPRISE_AUDIT.md`, whose structure this report copies). Phases, severity scale and
scorecard weights are the brief's, unchanged.

Audited: `main` at `2ff240e` (2026-11-24), which is what production runs (last deploy run 45473846797, green).
Method: five read-only audits in parallel (security and tenancy; email and notifications; performance, database and code
quality; operations and CI; functional inventory, UX and accessibility), the latest CI run on `main`, read-only GitHub API checks,
direct checks of the live site (`https://doc.infodot.co.za`), TLS and DNS, and a second reading of every High finding against the
code. Nothing was changed in production, no real data was touched, nothing was downloaded or installed.

**Not done in this baseline (and therefore not scored as verified):** a rendered browser pass at three widths, automated axe scans,
keyboard and screen-reader passes, a live email send, any load or soak test, and a restore drill. Each has a phase below.

**Public repository.** This repository is public. Unfixed, exploitable security findings are listed here by ID, area and
severity only; their reproduction detail is held outside the repository for the owner (`~/Dot/docs-audit-private/SECURITY_DETAILS.md`)
and moves into this file once each fix is deployed.

## 1. Measured baseline

| Measure | Value | Source |
| --- | --- | --- |
| Size | 35 web/API route definitions plus Fortify, Jetstream and SSO-package routes; 17 controllers, 17 Livewire components, 21 models, 126 Blade views, 45 migrations, ~18k lines of PHP in `app/`, ~13.6k lines of JS in `resources/js` | file counts |
| PHP tests | 1,024 tests, 6,809 assertions, 38.8 s, PHP 8.5 + SQLite `:memory:`. Result line: **69 passed, 955 passed with warnings** (`--compact` hides what the warnings are) | CI run 45473846811 |
| JS tests | 1,096 of 1,096 pass (`node --test`: sync, merge, pagination, toolbar) | same run |
| Browser / accessibility tests | **none** (no Dusk, Playwright, axe or pa11y anywhere) | `composer.json`, `package.json` |
| Static analysis | PHPStan level 5 on `app/`, 0 new errors; baseline 69 entries (77 errors) | `phpstan-baseline.neon` |
| Dependencies | `composer audit`: no advisories. `npm audit --audit-level=high`: 0. Neither can block CI (`continue-on-error`) | CI run 45473846811, `ci.yml:90-135` |
| CI on `main` | CI 39 of last 40 runs passed; deploy 39 of 40; required check "Quality gate", strict, admins enforced, 1 review | `gh run list`, branch protection API |
| Scheduler watchdog | 283 of last 300 passed; 8 failures in a row on 2026-11-23 13:12–14:49 (HTTP 000, ~1.5 h unreachable); 2 on 2026-11-26 (HTTP 403 from the host firewall) | `scheduler-watchdog.yml` runs |
| Live TLS | TLS 1.3 only (1.0 and 1.1 refused), Let's Encrypt, expires 2027-01-14 | `openssl s_client` |
| Live headers | **no HSTS, no CSP, no X-Frame-Options / frame-ancestors, no nosniff, no Referrer-Policy, no Permissions-Policy** on `/`, `/login` or `/up`. Session cookie `secure; httponly; samesite=lax`. The fleet security-headers PR for this repo (#103) has been open since 2026-10-30 and now conflicts | `curl -I`, `gh pr view 103` |
| Live endpoints | `/` 200, `/up` 200, `/login` and `/register` 302 to central sign-in, `/.well-known/security.txt` 404, no `/ready` | `curl` |
| Live response time | time to first byte **0.8–1.8 s even for `robots.txt` and `/up`**; first homepage load 7.2 s. Not load-tested at any data size | `curl -w` (5 samples) |
| Document root | already moved to `doc/public` (deploy log: "document root is …/public (standard layout)"); `/doc/artisan` answers Laravel's 404 | deploy run 45473846797, `curl` |
| Mail DNS | `infodot.co.za` (the From domain): SPF `~all`, DKIM `default` present, **DMARC `p=none` with no reporting address**. `doc.infodot.co.za`: SPF and DKIM, no DMARC record | `dig` |

## 2. Initial scorecard (brief's weights)

| Area | Weight | Initial | Basis |
| --- | ---: | ---: | --- |
| Security and data protection | 20% | **6.0** | Mature defences (OIDC with PKCE, signed InfoDot webhooks, SSRF guard, file containment, sanitising renderer, scoped Livewire actions, throttles, immutable audit log). Lost on two verified High authorisation gaps around publishing (S-01, S-02), account deletion destroying company documents (S-03), no security headers in production (S-06), local sign-in reachable around SSO (S-05), raw AI output (S-04), all-access API tokens (S-07). |
| Functional correctness and regression stability | 15% | **6.5** | Static integrity complete (every route, view, component and route name resolves) and a large, green suite. Lost on "Suggest instead of editing" doing nothing (F-01), AI accept replacing the whole document (F-02), silent feedback paths (F-05, F-06, F-08, F-11), and 955 tests ending in unread warnings (O-08). |
| UX/UI quality and usability | 15% | **6.5** (partially verified) | Consistent Fair Copy system, good empty states, phone overlays. Code read only: no rendered pass yet. Lost on lost feedback after redirects, invisible AI progress, contradictory copy, unconfirmed destructive actions, a mock AI presented as real. |
| Accessibility | 10% | **6.0** (partially verified) | Skip link, focus ring, labels, one h1, reduced motion, text contrast AA by computation. Lost on dialogs without focus traps or Escape (A-01), control borders below 3:1 (A-02), errors not tied to fields (A-03), palette options not announced (A-04); no automated or manual scan has ever run. |
| Email and notification reliability | 10% | **5.0** | Comment mail is carefully designed and tested (delayed, cancelled if seen or access lost, capped, escaped). Lost on link injection in invitation mail (E-01), failures that reach nobody and no proven delivery (E-02), unverified email change (E-03), no mail for share/role/access/publish changes (E-04), synchronous invitation mail (E-05), no preferences (E-06). |
| Performance and scalability | 10% | **4.0** | The single write path is correct, but every autosave does heavy work inside SQLite's only write lock (P-01), versions grow without limit and co-editing defeats coalescing (P-02), each save moves the document about four times (P-03), foreign keys are unindexed (P-04); the host itself answers in about 1 s; **nothing has ever been measured**. |
| Code quality and architecture | 10% | **6.5** | One write path (`DocumentStore`), careful comments, scoped policies, no dead PHP classes, PHPStan gate. Lost on a README and wiki that describe a different platform (Q-01), two search implementations (Q-02), very large files (Q-03), PHPStan level 5 with a baseline (Q-04), no N+1 guard (Q-05), dead realtime and lodash code shipped to every page (P-09). |
| Infrastructure and operational reliability | 10% | **6.0** | Gated deploy with concurrency control, maintenance mode, `APP_DEBUG` guard, asset smoke test, flock-protected crontab, scheduler/queue watchdog. Lost on backups that stay on the production disk unencrypted (O-01), no restore runbook or drill (O-02), no backup before migrations (O-03), a failed migration leaving the site down (O-04), no error tracker (O-06), an unexplained 1.5 h outage (O-07). |
| **Weighted total** | 100% | **5.9 / 10** | |

No Critical issue was found. Unlike InfoDot's baseline, this one has **High security findings** (S-01, S-02, E-01); under the brief, no
overall score can be accepted while they are open, so EA-1 comes first.

## 3. Findings register

Severity per the brief. **Owner** = needs the owner's access, credentials, DNS, money or a decision.

### Security and data protection
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| S-01 | **High** | Access to a published document is wider than its share link's password and expiry intend (detail held privately) | `DocumentPolicy.php:31-35` |
| S-02 | **High** | The published state can be changed by a role that should not have that right, without an audit row (detail held privately) | `DocumentSettings.php:116-126` vs `ShareManager.php:117-127` |
| S-03 | **High** | Deleting one user account hard-deletes every document they own in company teams, every version they authored on others' documents and their comments (FK cascades skip soft delete, observers and audit). Recorded as an open product decision in `.ai/rules/files.md:68` | `config/jetstream.php:67`; `create_documents_table.php:19`; `create_document_versions_table.php:19` — **Owner decision** |
| S-04 | Medium | AI assistant output is rendered without sanitising (detail held privately) | `ai-assistant.blade.php:51` |
| S-05 | Medium | Local password sign-in can still be reached while central sign-in is on (shared SSO package; detail held privately) | `packages/dot-sso-client/.../InterceptLocalAuth.php` |
| S-06 | Medium | No security headers in production or in the app (CSP, HSTS, frame, nosniff, referrer, permissions); fleet PR #103 open since 2026-10-30, now conflicting | live `curl -I`; `bootstrap/app.php` |
| S-07 | Medium | Jetstream API tokens carry every ability and never expire | `routes/web.php:107`; `config/sanctum.php:53` |
| S-08 | Low | Passwords: 8 characters, no breached-password check (matters while S-05 is open) | `PasswordValidationRules.php` |
| S-09 | Low | Two-factor available but never required; no new-sign-in alert | `config/fortify.php` |
| S-10 | Low | InfoDot signed requests: 5-minute window, no nonce, HMAC omits method and path, one secret for three endpoints | `SignedRequest.php:18-29` |
| S-11 | Low | Outbound webhook SSRF check resolves before the fetch (DNS-rebinding race); deliveries run inside the save request | `WebhookService.php:37,71-74` |
| S-12 | Low | Collaborator invite and ownership transfer reveal whether any address has an account; transfer can hand a company document to an outsider | `ShareManager.php:91`; `DocumentSettings.php:243-246` |
| S-13 | Low | Images in private documents are on the public disk (unguessable UUID URL, no auth) | `DocumentImageController.php:35-38` |
| S-14 | Low | AI chat history is client-editable with no size cap; token budget checked before, not during, a call | `AiChat.php`; `app/Ai/Guard.php` |
| S-15 | Low | Dependency audits never block CI; Dependabot alerts off on a public repo | `ci.yml:90-135`; GitHub API |
| S-16 | Low | `.env.example`: `SESSION_SECURE_COOKIE` unset, `SESSION_ENCRYPT=false`, `REVERB_APP_SECRET=infodot-secret`, `APP_DEBUG=true` (production overrides confirmed only for the cookie) | `.env.example` |
| S-17 | Low | Document search ranks every tenant's public documents among the candidates before filtering, so a user's own hits can drop out | `DocumentSearch.php` |
| — | Needs a test | dompdf remote resources (possible SSRF from an image `src`); dompdf PHP-enabled header/footer escaping (looks correct, fuzz it); legacy stored HTML printed raw on shared/history pages; claim-code attempt count race | `HtmlRenderer`, `PrintRenderer.php:104,140-145`, `shared.blade.php:37` |

### Functional correctness
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| F-01 | **High** | "Suggest instead of editing" toggles a flag nothing reads: edits in suggesting mode go straight into the document | `Editor.php:45,221-224`; `editor.blade.php:902-905` |
| F-02 | **High** | Accepting any AI result replaces the whole document, including "Continue writing", the rate-limit message and the error text (recoverable from history) | `editor.blade.php:659`; `AiAssistant.php:108-110,139,182` |
| F-03 | Medium | With the default mock provider every result is `[mock:role] …` and nothing tells the user the assistant is not real | `config/ai.php:16`; `app/Ai/Client.php:152-159` |
| F-04 | Medium | Every AI run is stored as a pending suggestion, including dropped and accepted ones | `AiAssistant.php:131,186` |
| F-05 | Medium | Files → Import creates an empty document and drops the instruction; a stray empty document remains | `Navigator.php:146-159` |
| F-06 | Medium | Flashed `status` messages (import, save to Files, move, template saved) are never shown in the editor; import validation errors are never shown | `components/banner.blade.php:2`; `DocumentImportController.php:67-115` |
| F-07 | Medium | Webhook signing secret is only ever shown truncated, so receivers cannot verify signatures | `webhook-manager.blade.php:66` |
| F-08 | Medium | Webhook errors dispatch a `notify` event nobody listens to | `WebhookManager.php:64,86,105,111` |
| F-09 | Medium | Document trash exists but has no restore screen; Files says "can be restored", Settings says "no undo" | `navigator.blade.php:122`; `document-settings.blade.php:196` |
| F-10 | Medium | Version compare strips tags (block boundaries vanish); left/right follows click order | `VersionHistory.php:79-97` |
| F-11 | Medium | "Send the invitation" sends nothing (adds a collaborator row, no message to anyone) | `ShareManager.php:87-105` |
| F-12 | Low | Mentions break on apostrophes, highlight only the first word, no autocomplete in replies | `comment-thread.blade.php:42,67,122` |
| F-13 | Low | Brand kits exist only as a model; print preview has a server method and no caller; orphaned Jetstream views | `BrandKit.php`; `Editor.php:232` |
| F-14 | Low | Documented co-editing limits users can meet: Enter/join during a same-paragraph clash can duplicate words; block moves can be undone silently | `.ai/rules/editor.md` "Known limits of Phase 2" |
| O-08 | Medium | 955 of 1,024 PHP tests end with warnings nobody reads (`--compact`) | CI run 45473846811 |

### UX/UI
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| U-01 | Medium | AI "Working"/"Thinking" indicators never render (set and cleared in one request, no `wire:loading`) | `AiChat.php:55-80` |
| U-02 | Low | No confirmation for removing a collaborator, stopping a published link, or Import (which replaces the document); import accepts `.txt` the label does not mention | `share-manager.blade.php:26,155`; `editor.blade.php:964` |
| U-03 | Low | Share page status shows only inside the public block; no copy-link button | `share-manager.blade.php:61` |
| U-04 | Low | Design docs drifted from code (fonts, breakpoints, "Two inks" leftovers) | `views.md`; `shell.css:55`; `dock.blade.php:60` |
| U-05 | Low | Error pages use their own palette and pill buttons, not Fair Copy tokens | `_ecosystem-layout.blade.php:70-75` |
| U-06 | Low | Shared page: top bar after `<main>`, no Print; "Request access" page cannot request access | `shared.blade.php:33-55`; `request-access.blade.php` |
| U-07 | Low | 51 inline `style=` one-offs in document views | `resources/views/livewire/documents/*` |

### Accessibility
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| A-01 | Medium | Ten `aria-modal` sheets have no focus trap and their `autofocus` does nothing after a morph; three have no Escape | `index.blade.php:229,277,303`; `navigator.blade.php:132-215` |
| A-02 | Medium | Input, checkbox, tag and button borders 1.2–1.4:1 against their surface (WCAG 1.4.11 needs 3:1); the contrast gate checks text only | `shell.css:24,111,1567` |
| A-03 | Medium | Validation errors not tied to fields (no `aria-invalid`/`aria-describedby`, no announcement) | `share-manager.blade.php` `@error` blocks |
| A-04 | Medium | ⌘K palette options lack `aria-selected`/`aria-activedescendant`; the `/` menu has no roles | `ui/list.js:69,104`; `ui/slash.js` |
| A-05..A-08 | Low | AI error dismiss is a click-only `<span>`; `aria-label` on generic elements; published/shared pages have no `<h1>`, welcome page has no `<main>`/skip link; editor paper `outline:none`, 25–30px targets | `editor.blade.php:765,816`; `published.blade.php:58`; `paper.css:77` |

### Email and notifications
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| E-01 | **High** | User-controlled text can become a live link in invitation mail sent from the app's domain (detail held privately) | `emails/team-invitation.blade.php:2` |
| E-02 | **High** | Mail failures reach nobody: `notifySafely()` swallows errors unlogged, `failed_jobs` is unmonitored and pruned after 7 days; no comment email has ever been shown to reach a real inbox | `CommentThread.php:295-301`; `routes/console.php:29` |
| E-03 | Medium | Email address can be changed with no verification and no notice to the old address (email verification is switched off) | `config/fortify.php:150`; `UpdateUserProfileInformation.php:31-38` |
| E-04 | Medium | No mail (or bell) for: shared with you, role changed, access removed, link published, password changed, email changed, new sign-in, account deleted, removed from team | `ShareManager.php:87-126`; `RemoveTeamMember.php:26` |
| E-05 | Medium | Invitation and claim-code mail sent inside the request; SMTP timeout unset (~60 s) | `InviteTeamMember.php:32-37`; `config/mail.php:48` |
| E-06 | Medium | No notification preferences, unsubscribe or `List-Unsubscribe` | — |
| E-07 | Low | Bell rows never pruned; stale titles stay after access is lost | `NotificationBell.php` |
| E-08 | Low | Clicking a bell link may not mark it read (request cancelled by navigation), so the email still goes | `notification-bell.blade.php` |
| E-09 | Low | Mail link colour about 2.6:1 on white | `vendor/mail/html/themes/default.css:37` |
| E-10 | Low | Hourly mail cap spends a slot before sending; no sent-mail record, so a retry can duplicate | `CommentEmailNotification.php:98-103` |
| E-11 | Low | `.env.example` mail and broadcast defaults are stale (`hello@example.com`, `reverb`) | `.env.example:36,66` |
| E-12 | Medium | DMARC `p=none` with no reporting address on `infodot.co.za`; SPF `~all` | `dig` — **Owner** (DNS) |

### Performance and scalability
| ID | Sev | Finding | Evidence |
| --- | --- | --- | --- |
| P-01 | **High** | Every autosave builds the outline, renders full HTML, extracts text and counts words inside SQLite's single write lock; every other write in the app waits; a wait over 5 s fails the save | `DocumentStore.php:120-153,321-338`; `config/database.php:44-48` |
| P-02 | **High** | Version rows grow without limit; two people editing together cut a new full-copy version on nearly every save (`shouldCut()` cuts whenever the author alternates); 14 daily backups multiply it | `DocumentStore.php:307-319` |
| P-03 | **High** | Each Livewire save carries the whole document about four times and re-renders the 82 KB editor view, every ~1.5 s per typing tab | `Editor.php:38,149,195,459-481` |
| P-04 | **High** | Foreign keys have no indexes on SQLite: `documents.owner_id/team_id/folder_id`, `document_versions.document_id`, `comments.document_id`, `document_webhooks.document_id` (read on every save), `team_user.user_id` | migrations `2026_04_10_*` |
| P-05 | Medium | Every `/sync` poll writes (session, presence upsert and sweep) and runs ~10–12 queries | `DocumentSyncController.php:53-83`; `PresenceService.php` |
| P-06 | Medium | `on_save` webhooks run synchronously on every autosave (DNS check + 5 s POST) | `WebhookService.php:21-75` |
| P-07 | Medium | Search is `LIKE '%q%'` over whole bodies on SQLite; header search checks the policy per result | `DocumentSearch.php:95-122` |
| P-08 | Medium | Document list selects every column (HTML, JSON, search text) and "Load more" re-queries and re-counts everything | `Index.php:276-316` |
| P-09 | Medium | The editor (TipTap/ProseMirror), Echo/pusher-js and full lodash load on every page, including guest pages; unused `alpinejs`/`@hotwired/turbo` deps | `resources/js/app.js:1-7`; `bootstrap.js` |
| P-10 | Medium | Entitlement check makes an outbound call to InfoDot on the request path once per company per minute (up to 3 s) | `EntitlementClient.php:22,40-69` |
| P-11 | Low | `DocumentUpdated` broadcast carries the full document synchronously (would exceed Pusher limits if ever switched on) | `DocumentUpdated.php:40-52` |
| P-12 | Low | No pruning of notifications, audit logs (`document.viewed` per open) or orphaned presence rows | `Editor.php:77` |
| P-13 | Low | WAL mode is not enforced in config (a recreated database file falls back to rollback journal) | `config/database.php:47` |
| P-14 | Medium | Host answers in 0.8–1.8 s to first byte even for static files | `curl -w` — **Owner** (hosting plan / LiteSpeed) |
| M-01 | **High** | No response time, save latency, lock wait or concurrency limit has ever been measured; tests run on `:memory:` and cannot reproduce "database is locked" | no load-test artefacts; `phpunit.xml:32` |

Estimate from the code (not measured): one typing tab with company writes roughly 5,000–8,000 serialized transactions an hour to the
one SQLite file (about 2,400 document updates, 4,000+ session writes, ~720 presence upserts, versions).

### Code quality
| ID | Sev | Finding |
| --- | --- | --- |
| Q-01 | Medium | README and wiki describe another platform: PostgreSQL/tsvector, Reverb live cursors, Sanctum handoff SSO, `docs.infodot.app`, "21 platforms"; `.env.example` defaults to pgsql and reverb; `.ai/rules/deploy.md` still says the docroot move is not done |
| Q-02 | Medium | Two search implementations with different visibility rules (`DocumentSearch` vs `CompanyDocuments`) |
| Q-03 | Low | Very large files: `sync/host.js` 3,079 lines, `DocxExporter` 848, `DocxImporter` 780 (one test file covers DOCX), `Index` 556, `Editor` 482 |
| Q-04 | Low | PHPStan level 5, `app/` only, 77 baselined errors |
| Q-05 | Low | No `preventLazyLoading`/`shouldBeStrict`, no query budgets beyond one poll test |
| Q-06 | Low | No FormRequests; inline validation in 15 files |

### Operations and CI
| ID | Sev | Finding | Owner |
| --- | --- | --- | --- |
| O-01 | **High** | Backups stay on the production disk: no off-host copy, no encryption, uploads (`storage/app`) not backed up | **Owner**: off-host destination (cPanel backup to remote, or a bucket) and the encryption passphrase in a password manager |
| O-02 | **High** | No restore runbook (code comments cite one that does not exist); no restore ever drilled; no RTO | **Owner**: run the drill once the runbook exists |
| O-03 | **High** | Every merge deploys and runs `migrate --force` with no backup first | — |
| O-04 | Medium | A failed migration exits with the site still in maintenance mode; a failed smoke check does not roll back | — |
| O-05 | Medium | Backup failures alert nobody; Guardian does not check backup age | — |
| O-06 | Medium | No error tracker; the only alert path is GitHub's failed-run email from the watchdog, which the host firewall sometimes blocks | **Owner**: choose Sentry (or similar) and/or an independent uptime monitor; or allowlist GitHub runners |
| O-07 | Medium | Unexplained ~1.5 h outage on 2026-11-23 13:12–14:49 | **Owner**: host logs for that window |
| O-09 | Medium | CI runs PHP 8.5 only while notes say production runs 8.4 (`deploy/README.md` says 8.5); no file-backed SQLite/WAL test; no E2E, accessibility or coverage in CI | **Owner**: confirm the host PHP version |
| O-10 | Medium | Dependabot alerts off and no `dependabot.yml` on a public repo; audits never block | **Owner**: enable Dependabot alerts in repo settings |
| O-11 | Low | Host resets to `origin/main` at fetch time, not the tested SHA | — |
| O-12 | Low | Reverb and queue steps assume systemd that cannot exist; warning on every deploy | **Owner**: confirm Reverb is not wanted |
| O-13 | Low | `.env.example` drift (debug true, pgsql, single log at debug, mail log) | — |
| O-14 | Low | No `security.txt`, `SECURITY.md`, incident runbook or dependency-update process | — |
| O-15 | Low | App-folder probe still checks `/doc/...` paths that mean nothing since the docroot moved; watchdog prints `000000` | — |
| O-16 | Low | ~47 stale branches; PR #103 open four weeks | — |
| O-17 | Low | Old docroot still holds `Dot.docs.tar.gz`, the hand-written `index.php` and the old `build/` (no longer served) | **Owner**: delete in cPanel File Manager |

### Checked and sound (credited in the scores)
- **Identity:** OIDC with PKCE S256, single-use state, nonce `hash_equals`, RS256 only, iss/aud/azp/iat/exp checked, `email_verified` required;
  backchannel logout with jti replay cache; open-redirect guards (encoded and backslash forms); handoff token in POST body only, Origin
  pinned, session id rotated; unverified local accounts go through a hashed, attempt-limited claim code.
- **Tenancy:** Livewire model properties locked; every child-id action scoped by `document_id`; Files policy checks the node's own team and
  every write; moves pinned to the same team; templates scoped; header search team-scoped with LIKE escaping and a policy re-check;
  InfoDot search endpoint signed, membership-checked, titles and pointers only; access re-checked on every poll (no cached decisions).
- **Content safety:** HtmlRenderer escapes everything and allowlists URL schemes; TokenGuard on style tokens; comments purified; comment
  mail entity-encoded; file serving by signed URL with realpath containment, nosniff, attachment disposition; SVG refused; images re-encoded;
  DOCX import zip-bomb limits; outbound webhooks SSRF-guarded, no redirects, approval workflow.
- **Writes:** one write path (`DocumentStore`) with optimistic versioning, IMMEDIATE transactions, `busy_timeout`, replaced text kept as a
  named version; webhooks after commit; presence a single upsert on a unique key; sync metrics pruned.
- **Operations:** required quality gate (strict, admins included); deploy concurrency group; maintenance mode; forced `APP_DEBUG=false` plus a
  debug-page probe; asset smoke test against the rendered HTML; flock-protected crontab with verify-or-restore; LSCache purge; scheduler and
  queue heartbeats with `/up/scheduler` and a 15-minute watchdog; token-protected Guardian health; SQLite online-backup API, 14 copies; no
  tracked runtime files; TLS 1.3 only.
- **Interface:** every route, view and route name resolves; global focus ring, skip link, `lang`, one h1 per app page, labelled inputs, roving
  focus on the bubble toolbar, reduced motion; text tokens pass AA day and night, with a rendered-pair contrast script.

## 4. Remediation plan (one PR per phase, in the brief's priority order)

The programme number is the owner's to assign; phases are called EA-1..EA-10 here. **Merging to `main` deploys production
(`deploy.yml` runs on push).** Every phase PR therefore stays open until the owner says to merge, and EA-6 (backup before migrate)
is proposed to land before any phase that adds a migration.

| Phase | Scope | Findings |
| --- | --- | --- |
| EA-1 Security | Publishing access fixed and tested end to end (share gate for non-participants; `manage` + audit for the published state); invitation mail escaped and throttled; AI output sanitised; security headers (rebase PR #103's baseline: enforced nosniff/frame/referrer/permissions, HSTS env-gated, CSP report-only then enforced); local sign-in refused while SSO is on (fixed in the shared package and synced); API tokens: abilities + expiry, or `Features::api()` off; stronger passwords; DNS-pinned webhook fetch; private images behind auth; dompdf remote-off test; audits block on High | S-01, S-02, S-04..S-08, S-11, S-13..S-16, E-01 |
| EA-2 Account deletion (owner decision) | Deleting an account must not destroy company documents: transfer team documents to the team owner (or block deletion while the user owns team documents), soft-delete personal ones, keep versions with a "deleted user" author, audit it, mail the user | S-03, E-04 (account deleted) |
| EA-3 Email and notifications | Log and alert mail failures; `failed_jobs` in Guardian; queue invitation and claim mail with an SMTP timeout; email verification on; email-change notice to the old address; mails and bell rows for shared/role/removed/published/password changed/new sign-in/removed from team; preferences + `List-Unsubscribe` for optional mail; bell pruning and mark-read before navigation; link contrast; then a **live delivery test** to controlled inboxes with `Authentication-Results` read | E-02..E-11, F-11, S-09 |
| EA-4 Functional | Suggestion mode built properly (tracked suggestions) or the control removed until it is; AI accept honours append/replace and never accepts error or rate-limit text; mock provider labelled; stale AI suggestions not stored; flash messages shown in the editor; import errors shown; webhook secret shown once in full; webhook error listener; trash and restore screen (or wording made true); block-aware version compare; mentions; read the 955 test warnings and fix them | F-01..F-13, U-01, O-08 |
| EA-5 Performance | Foreign-key indexes; render/outline/text work moved out of the write lock; versions coalesced per window regardless of author, plus a retention policy (keep named, thin autos); `#[Renderless]`/no content round-trip on save; webhooks queued and debounced; narrow index query; editor bundle only on editor pages, drop Echo/lodash/unused deps; WAL set in config; pruning for notifications, audit views and presence | P-01..P-13, Q-05 |
| EA-6 Operations | Deploy takes a SQLite online backup before `migrate`, keeps the site up on migration failure (trap → `artisan up` + clear error), rolls back on failed smoke, pins the tested SHA; encrypted backups with an off-host copy and an uploads backup; backup-age check in Guardian and alerts; restore runbook; `security.txt` + `SECURITY.md`; Dependabot config; `.env.example`, README, wiki and `.ai/rules/deploy.md` corrected; watchdog fixes; prune stale branches | O-01, O-03..O-05, O-11..O-16, Q-01 |
| EA-7 Tests and CI | Browser suite (Playwright) for sign-in hand-over, create/edit/save, two-account co-editing, share/publish, export, import; axe on every screen; both in CI; PHP 8.4 job; file-backed SQLite WAL concurrency test; coverage measured and gated | O-09, A-01..A-08 (as tests) |
| EA-8 UX and accessibility pass (design skills: impeccable, emil-design-eng, within the Dot control-room direction) | Rendered pass of every screen at desktop, tablet and phone; focus traps and Escape on every sheet; 3:1 control borders; errors tied to fields; palette and slash-menu ARIA; confirmations for destructive actions; shared/published page structure; error pages on Fair Copy tokens; inline styles into classes; design docs corrected | U-02..U-07, A-01..A-08 |
| EA-9 Measurement | Seeded copy on the real host type (staging) and a load test: save p50/p95/p99 and lock waits with 1, 3, 10 typists; poll cost; index, history, search and export at 1k/10k documents; before and after EA-5. Gives the evidence the brief requires before any scalability claim | M-01, P-14 |
| EA-10 Verification | Independent re-audit and the final scorecard with the brief's evidence categories (verified and passed / fixed and retested / partially verified / blocked by external dependency / not tested) | Phases 12–13 |

### Needs the owner (cannot be done from the repository)
1. **Account deletion rule** (S-03, EA-2): what happens to company documents when a member deletes their account. Recommendation: team
   documents pass to the team owner; personal documents are soft-deleted for 30 days; deletion is audited.
2. **Suggestion mode** (F-01): build tracked suggestions properly (larger job) or hide the control until it is built. Recommendation: hide now, build later.
3. **API tokens** (S-07): keep Jetstream API tokens (then abilities and expiry) or switch the feature off. Recommendation: off; nothing in the ecosystem uses them.
4. **Off-host backups** (O-01): a destination outside the server (cPanel remote backup or a storage bucket) and the backup passphrase in a password manager.
5. **Restore drill** (O-02): run the runbook once on a scratch copy and record the time it took.
6. **Error tracking and uptime** (O-06): a Sentry project (DSN into the host `.env`) and/or an uptime monitor independent of GitHub; or allowlist GitHub's runner ranges in the host firewall.
7. **Host facts** (O-07, O-09, O-12, P-14): host logs for 2026-11-23 13:12–14:49; the host PHP version (`php -v` in the app folder); confirm Reverb is not wanted; whether the hosting plan's ~1 s first-byte time can improve.
8. **Mail DNS** (E-12): add a reporting address to DMARC (`v=DMARC1; p=none; rua=mailto:<a mailbox you read>`); after a few weeks of reports, move to `p=quarantine` and SPF to `-all`.
9. **Repository settings** (O-10): turn on Dependabot alerts.
10. **Old docroot leftovers** (O-17): delete `Dot.docs.tar.gz`, the hand-written `index.php` and the old `build/` from `/home/infodotc/doc.infodot.co.za/` (only after checking nothing serves them).
11. **Staging** (EA-9): a staging subdomain with its own app folder on the same hosting, for load tests.
12. **AI provider** (F-03): whether a real model key goes into production; until then the assistant says plainly that it is a demonstration.

### Decided by the owner, 2026-11-26
- **EA-1 security** merged (#193, `751802b9f`) and deployed the same day after a verified backup; live headers checked, HSTS included.
- **Account deletion (S-03, EA-2):**
  1. Documents the leaver owns in a company team **pass to the team's owner**, stay where they are, and the handover is audited.
  2. Personal documents (no team) are **soft-deleted with the account and purged after 30 days**.
  3. A person who **owns a company team cannot delete their account** until team ownership has moved to another member.
  4. Their comments and the versions they wrote on other people's documents are **kept, shown as "Former member"**.
