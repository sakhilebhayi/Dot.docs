# Dot.docs — round 2 independent re-audit (R2-8)

Date: 2026-11-27. Audited commit: `9c75111` (R2-7 deployed). Brief: `~/Dot/briefs/ENTERPRISE_READINESS_AUDIT.md`,
Phase 12 weights. Baseline: `docs/programme/ENTERPRISE_AUDIT.md` (5.9). Previous scorecard: `EA10_SCORECARD.md` (7.2).

## 1. How this was scored

Four auditors, each given the brief, the 2026-11-26 baseline findings and a clean checkout of the deployed commit,
scored two areas each **without** the round-2 PR descriptions or the EA-10 scorecard: they established every
finding's current state from the code, the test suite (which they ran) and anonymous requests to the live site. Their
reports were checked against the live site the same afternoon (headers, private paths, TLS, timing). The brief's rules
applied: no credit for completed tasks without evidence, an open High caps its area, and owner-side items that cannot
be verified from here count as not done.

Evidence key: **VP** verified and passed · **FR** fixed and retested · **PV** partially verified · **EXT** blocked by an
external dependency or the owner · **NT** not tested.

## 2. Weighted scorecard

| Area | Weight | Baseline | EA-10 (self-scored) | **R2-8 (independent)** | Weighted |
| --- | ---: | ---: | ---: | ---: | ---: |
| Security and data protection | 20% | 6.0 | 7.5 | **7.0** (capped) | 1.40 |
| Functional correctness and regression stability | 15% | 6.5 | 7.5 | **8.0** | 1.20 |
| UX/UI quality and usability | 15% | 6.5 | 7.2 | **7.5** | 1.13 |
| Accessibility | 10% | 6.0 | 7.4 | **7.5** | 0.75 |
| Email and notification reliability | 10% | 5.0 | 7.5 | **6.5** (capped) | 0.65 |
| Performance and scalability | 10% | 4.0 | 6.5 | **6.0** | 0.60 |
| Code quality and architecture | 10% | 6.5 | 7.0 | **8.0** | 0.80 |
| Infrastructure and operational reliability | 10% | 6.0 | 6.5 | **6.0** | 0.60 |
| **Total** | 100% | **5.9** | **7.2** | | **7.1** |

**Read this table honestly.** The code moved a long way since the baseline (every baseline High is fixed and tested;
PHPStan level 6 with no baseline; 1,229 PHP + 1,100 JS + 33 browser tests; enforced CSP; send-once mail; full-text
search; retention code). The independent total is still 7.1, not above 9, for three reasons: the independent auditors
were stricter than the EA-10 self-assessment; they found one new High (security); and three areas are scored on what
is **live and verified in production**, where owner-side work is still outstanding (section 4). No area reaches 9 today.

## 3. Area findings

### Security and data protection — 7.0 (capped by one open High)

- **FR:** S-01 (published access), S-02 (publish needs `manage`, audited), S-03 (account deletion keeps company
  documents), S-04 (AI output sanitised), S-07 (API token abilities, expiry, token-only API), S-11 (webhook DNS pin),
  S-12 (ownership transfer within the team), S-14 (AI input caps), S-17 (search scoped to the company).
- **VP live:** enforced CSP with nonce, HSTS, nosniff, frame, referrer and permissions headers; `/.env`, `.git`,
  the database file and logs answer 403; `/.well-known/security.txt` served; TLS valid to 2027-01-14.
- **PV / OPEN:** S-05 (one High, below); S-08 (breached-password check is tied to the production environment setting,
  see the hotfix); S-09 (2FA optional); S-10 (InfoDot signature has no nonce); S-13 (older `/storage` images still
  public); S-15 (Dependabot alerts off); CSP still allows `'unsafe-eval'` and any `https:` for `connect-src`.
- **New High (N-1):** an authentication issue. Details are in the private audit notes, not in this public
  repository. **Mitigated in Dot.docs by PR #234** (`RefuseLocalSignInWhileSso`); the root fix
  belongs in the shared package and is with the InfoDot programme. The cap lifts when #234 is live and re-verified.
- **New Medium (N-2):** a production configuration setting (details in the private notes). It made R2-7's lazy-load
  guard able to show an error page instead of logging; **PR #234 removes that dependency.** The setting itself is an
  owner change (section 4).

### Functional correctness — 8.0

- **FR:** F-01 to F-13 except F-06 (PV) and F-14 (open, documented co-editing limits). Bin and restore, Files import,
  access requests, brand kit, mentions, version history, exports — all with feature tests; 0 test warnings (O-08).
- **New Medium:** comments containing `&`, `<` or `>` display escaped twice (`CommentThread.php:328`) — reproduced.
- **New Medium:** a document editor can change or remove a collaborator who was given the admin role, and collaborator
  changes are not audited (`ShareManager.php:220,257`) — reproduced.
- **Low:** two Livewire-only confirmations never show ("Template saved", "Filed in…"); no browser test yet for a
  two-browser conflict, DOCX import, PDF/DOCX export, version restore, Bin restore or comments.

### UX/UI — 7.5

- **FR/PV:** U-01, U-02, U-06, U-07 fixed; U-03 (status line and Copy link; a slug saved on a private document still
  shows no confirmation), U-04 (one "Two inks" phrase left in the dock), U-05 (error pages on Fair Copy; guest/auth
  pages still on the old palette).
- **Medium:** the dock chat shows raw `[mock:draft]` text when no AI model is connected; the invite field returns
  Laravel's default "invalid" message for someone without an account.
- **Low:** shortcut glyphs differ by screen, two features are both called "command palette", row actions lack the
  item's name, no recorded screenshot review at three widths in both modes (NT).

### Accessibility — 7.5

- **FR (tested):** A-01 to A-08; axe fails CI on serious and critical issues at rest, with dialogs open, at night and at
  phone width; control edges measured at 3:1 in day and night.
- **Medium:** the ⌘K palette has no focus trap and drops focus on close; save status announced by two live regions;
  chat answers not announced; profile "Saved." has no `role=status`; the empty-document hint is 2.30:1.
- **NT:** no VoiceOver or NVDA pass has ever been run; axe does not yet reach the published, shared, password-gate,
  request-access, brand, webhooks and teams pages, the template gallery or the open comments sidebar.

### Email and notifications — 6.5 (capped)

- **FR in code:** E-01, E-03 to E-11. All mail queued with retries, a send-once ledger (`sent_mails`), a give-up alert
  (`MailNotDelivered` → `ErrorAlert`, addresses masked), the claim code encrypted on the queue, `List-Unsubscribe`.
- **Capped because, in production today, a failed email still reaches nobody:** `OPS_ALERT_EMAIL` is not set (E-02),
  and the alert would travel through the same mail server that failed.
- **EXT:** DMARC `p=none` with no `rua`, SPF `~all` (E-12); no recorded `Authentication-Results` from a live delivery
  test; bell, `sent_mails` and `failed_jobs` pruning paused by the scheduler mode (section 4).

### Performance and scalability — 6.0

- **VP/FR:** P-04, P-06, P-07 (FTS5 search, bm25), P-08, P-09 (editor code only on the editor page; `/` loads 21 KB JS),
  P-13; version and view-record retention written and tested.
- **Open:** no load test on the host (M-01, EXT — every measurement is from a laptop and predates the search index);
  the host answered `/` in 0.7–2.1 s and returned three 503s in a row during the audit (P-14, EXT); every sync poll
  writes a session row inside SQLite's write lock; the search-index trigger re-indexes on every typing save
  (unmeasured); polls rebuild the outline and CSS per co-editor save; no `auto_vacuum`.
- **Accepted risk (owner):** retention jobs are paused (section 4), so versions grow by about 1.5 MB an hour per
  active typist until they run again.

### Code quality and architecture — 8.0

- **VP:** Q-02 (one company-scoped search), Q-04 (PHPStan level 6, no baseline, two justified inline ignores),
  Q-05 (lazy-loading guard). CI: PHP 8.4 and 8.5, 87% coverage floor, browser and axe suite, Pint, PHPStan, dependency
  audits, workflow lint.
- **PV:** Q-01 (some docs still describe the old search rule and Postgres — being corrected), Q-03 (`host.js` and the
  DOCX classes split; `pagination/decorations.js`, `editor/index.js`, `Index.php`, `Editor.php` and the 83 KB editor
  view remain large), Q-06 (six form requests; the remaining inline validation is either deliberate or framework code).
- **Low:** the test configuration turns the production scheduler mode off, so its effect is never exercised in CI.

### Infrastructure and operations — 6.0

- **FR:** deploys run only after CI passes on `main`, pin the tested commit, take an integrity-checked backup before
  migrating, check the site from the host (including `GET /up`) and roll back when that check fails; the app folder
  probe; security.txt, SECURITY.md, restore runbook and `doc:restore-backup`.
- **EXT / NT:** no off-host backup copy, `BACKUP_PASSPHRASE` not confirmed, restore drill never run (O-01, O-02);
  `OPS_ALERT_EMAIL` unset and no independent uptime monitor (O-06); Dependabot alerts off; 87 stale branches.

## 4. Owner-side items (cannot be done from code) and accepted risks

| Item | Effect on score | What to do |
| --- | --- | --- |
| **Accepted risk:** essential-only scheduler (owner decision 2026-11-27, "pause backups too") | Nightly backup, uploads archive and all pruning (versions, view records, bell, `sent_mails`, `failed_jobs`, metrics, tokens) are paused. Deploys still take their own pre-deploy backup. `/up/scheduler` will report the backup as stale from about 04:35 UTC on 2026-11-28 and the watchdog will fail until it runs again. | To restart: set `DOT_CRON_ESSENTIAL_ONLY=false` in the production `.env`, then `php artisan config:cache`. |
| Production configuration setting (N-2) | Breached-password check off | The exact change is in the private audit notes; apply it after #234 is live, then `php artisan config:cache`. |
| `OPS_ALERT_EMAIL` | Caps email; infrastructure alerting | Set it in the production `.env`, then `php artisan config:cache`. |
| Off-host backup, `BACKUP_PASSPHRASE`, restore drill | Infrastructure | As in `RESTORE_RUNBOOK.md`; record the drill's time. |
| DMARC `rua` then `p=quarantine`, SPF `-all` | Email | DNS for infodot.co.za. |
| Live delivery test with `Authentication-Results` recorded | Email | Send to two controlled inboxes; keep the headers. |
| Host load test (`EA9_MEASUREMENT.md` §4) | Performance | Run `doc:bench` on the host with 1, 3 and 10 writers. |
| Host time to first byte and the 503s | Performance | Hosting plan / LiteSpeed cache. |
| Dependabot alerts on; stale branches pruned | Security, infrastructure | GitHub settings. |
| VoiceOver/NVDA pass of the core flows | Accessibility | A recorded session. |

## 5. What would take each area to 9 or more

- **Security:** #234 live and re-verified; the shared-package fix synced; the N-2 setting corrected; nonce in the InfoDot
  signature; remaining `/storage` images private; Dependabot on; 2FA required for company owners and admins; CSP
  without `unsafe-eval` and with a narrow `connect-src`.
- **Functional:** fix the double-escaped comments and the collaborator-role gap (with an audit row for collaborator
  changes); show the two missing confirmations; browser tests for conflict, import/export, restore, Bin and comments.
- **UX:** label or hide the mock chat; plain-English validation messages; one name per feature and per-platform
  shortcut glyphs; guest/auth pages on Fair Copy; recorded screenshot review.
- **Accessibility:** focus trap and return for the ⌘K palette; one save-status live region; announce chat answers and
  "Saved."; placeholder to 4.5:1; named row actions; axe on the unscanned screens; a screen-reader pass.
- **Email:** `OPS_ALERT_EMAIL` and an alert path that does not depend on the failing mail server; DMARC/SPF; recorded
  authenticated delivery; pruning running.
- **Performance:** host load test with p95/p99 and lock waits; no session write on `/sync`; outline/CSS cached per
  version; retention running; host latency fixed.
- **Code quality:** docs drift closed; the remaining large files split; PHPStan level 8; coverage floor 90%; a CI run
  with the production scheduler setting.
- **Infrastructure:** nightly backups running, encrypted, copied off the host and restored once in a drill; alert email
  and an independent uptime monitor; the N-2 setting corrected.

## 6. Round-2 work behind these scores

R2-1 #220/#221, R2-2 #222/#223, R2-3 #226/#227/#229, R2-4 #230, R2-5 #231, R2-6 #232, R2-7 #233, all merged on the
owner's instruction after a green CI and deployed with a checked pre-deploy backup; R2-8 hotfix #234 open.
