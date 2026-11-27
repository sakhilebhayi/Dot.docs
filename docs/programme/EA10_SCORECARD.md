# EA-10 verification and final scorecard

Enterprise audit of Dot.docs, phase EA-10 (brief phases 12 and 13). Re-audited on 2026-11-27 against `main` at
`d4b9069` (EA-8), with EA-9 (`2be5913`) merged and deployed during the re-audit and counted where it changes a score.
The baseline report and findings register are in the audit PR (#192, `docs/programme/ENTERPRISE_AUDIT.md`).

## 1. Executive summary

**Weighted score: 7.2 / 10, up from 5.9.** The target in the brief is 9.5. It is **not reached**, and under the brief
the result cannot be accepted as final while two High findings in operations are open:

- **O-01:** every backup sits on the production disk. No copy exists anywhere else.
- **O-02:** a restore runbook exists, but no restore has ever been drilled.

Both need the owner (section 9).

All three High security findings (S-01, S-02, S-03), the High email finding (E-01), the two High functional findings
(F-01, F-02) and four of the five High performance findings are fixed and pinned by tests. No Critical finding was
found at baseline or since.

**How it was verified.** Three reviewers who had not built the fixes each took a group of areas. They were given the
original findings and the code, and **not** the builder's view of what was fixed. They read the code paths, workflow
files and tests, queried GitHub, and made unauthenticated GET requests to production. They wrote nothing. Their
per-finding verdicts are summarised in section 3. Where the builder holds evidence a reviewer could not see, such as
the owner's delivery test, it is stated as such.

## 2. Phases delivered

| Phase | PR | Merged as | Deploy run | Result |
| --- | --- | --- | --- | --- |
| EA-1 Security | #193 | 751802b9f | 45879563099 | live, headers verified |
| EA-2 Account deletion | #207 | 7dedf91a8 | 45883346348 | live; owner backup and post-migration counts verified |
| EA-3 Email | #208 | a509b97c3 | 45886022539 | live; owner's delivery test arrived in the inbox, not spam |
| EA-4 Functional | #209 | 27c097c07 | 45889631036 | live |
| EA-5 Performance | #211 | 0c5c2ce78 | 45946730573 | live |
| EA-6 Operations | #212 + fix #213 | 750c21caf | 45948559213 | live (#212's workflow file was rejected by GitHub; #213 fixed it) |
| EA-7 Tests and CI | #214 + #215 | fc4c4ddf2 | 45952662569 | live (#214's smoke check met the host firewall; #215 retries) |
| EA-8 UX and accessibility | #216 | d4b906992 | 45955095155 | live |
| EA-9 Measurement | #217 | 2be5913f0 | 45959236218 | live; local numbers only |

Every merge had a green PR CI (the quality gate). Each was merged on the owner's explicit instruction, by lowering the
one-review rule to 0 and restoring it straight after (section 6).

## 3. Final weighted scorecard

| Area | Weight | Initial | Final | Weighted |
| --- | ---: | ---: | ---: | ---: |
| Security and data protection | 20% | 6.0 | **7.5** | 1.50 |
| Functional correctness and regression stability | 15% | 6.5 | **7.5** | 1.13 |
| UX/UI quality and usability | 15% | 6.5 | **7.2** | 1.08 |
| Accessibility | 10% | 6.0 | **7.4** | 0.74 |
| Email and notification reliability | 10% | 5.0 | **7.5** | 0.75 |
| Performance and scalability | 10% | 4.0 | **6.5** | 0.65 |
| Code quality and architecture | 10% | 6.5 | **7.0** | 0.70 |
| Infrastructure and operational reliability | 10% | 6.0 | **6.5** (capped) | 0.65 |
| **Total** | 100% | **5.9** | | **7.2** |

Evidence key used below: **VP** verified and passed · **FR** fixed and retested · **PV** partially verified · **EXT**
blocked by an external dependency or the owner · **NT** not tested.

### Security and data protection: 6.0 → 7.5

- **Resolved (FR):**
  - S-01, S-02: published-document access and publishing through settings (`PublishedDocumentAccessTest`).
  - S-03: account deletion keeps company documents (`AccountDeletionTest`, 14 cases).
  - S-04: AI output is sanitised.
  - S-08: password policy.
  - S-11: webhook DNS pinning (`SsrfGuardPinTest`).
  - S-17: search no longer surfaces public documents.
  - S-16: example environment.
- **Partly resolved (PV):**
  - S-06: security headers are live, HSTS included, but the CSP is report-only and permissive.
  - S-14: AI history capped and locked; the budget is checked only before a call.
  - S-15: CI blocks High advisories; Dependabot alerts are off (**EXT**, owner).
- **Open:**
  - S-05 (Medium): the local sign-in path can still be reached around central sign-in. The code is in the shared
    SSO package, so the fix is fleet-wide.
  - S-07 (Medium): API tokens have every ability and never expire. This waits on the owner's decision.
  - S-12 (Low/Medium): ownership transfer is not audited and is not limited to the team.
  - S-13 (Low): uploaded images are served without authentication.
  - S-09, S-10: Low.
  - Legacy stored HTML is printed raw on the shared and history pages, with no enforced CSP behind it.
- **Tests:** security feature tests in CI on PHP 8.4 and 8.5; live header checks with curl.
- **Why not higher:** two Medium authentication gaps are untouched and no CSP is enforced.

### Functional correctness: 6.5 → 7.5

- **Resolved (FR):**
  - F-01: suggestion mode hidden (owner decision).
  - F-02: AI replace or insert is decided on the server.
  - F-03, F-04, F-06, F-07, F-08, F-10, F-11.
  - O-08: CI reads warnings; 0 warnings.
- **Partly resolved:**
  - F-05: an empty document is left behind when an import from Files fails.
  - F-09: the copy is honest, but there is no way to restore deleted items.
  - F-12: only the apostrophe fix landed.
- **Open:**
  - F-13: the brand kit and print preview are dead features.
  - F-14: documented co-editing limits.
- **Tests:**
  - **VP** 1,125 PHPUnit tests, 0 warnings, line coverage 88.09% with an 87% floor.
  - **VP** 20 browser journeys in CI: sign-in hand-over, create/edit/save, two-account co-editing, share and
    publish, export, import, focus trap, palette ARIA, error page.
  - **VP** a file-backed SQLite concurrency test.

### UX/UI: 6.5 → 7.2

- **Resolved:**
  - U-01: AI progress is visible.
  - U-02: confirmations on destructive actions.
  - U-05: error pages use Fair Copy.
- **Partly resolved:** U-06: the shared page has its bar, h1 and Print; "Request access" still cannot send a request.
- **Open:**
  - U-03: share status and copy affordance.
  - U-04: the design rules file still names old fonts.
  - U-07: 55 inline styles.
- **Evidence:**
  - **PV**: rendered checks of the 404 page at desktop and phone, the share page and the palette.
  - **NT**: a recorded screenshot review at every viewport in both modes.

### Accessibility: 6.0 → 7.4

- **Resolved:**
  - A-01: focus trap and Escape on all 12 sheets.
  - A-04: palette combobox (browser-tested).
  - A-05, A-06, A-07.
- **Partly resolved:**
  - A-02: field borders reach 3.2–3.9:1, but button and tag borders are still 1.2–1.4:1.
  - A-03: Livewire forms are tied to their errors; 15 Jetstream fields and three editor errors are not.
  - A-08: targets meet 2.5.8, but the editor paper has no focus ring.
- **Gate:** axe serious and critical violations fail CI on every main screen. The gate does not cover open dialogs,
  night mode, the phone project or moderate violations. One shared dot-ui button is excluded (fleet fix).
- **NT:** no screen-reader (VoiceOver/NVDA) or recorded keyboard audit has ever been run.

### Email and notifications: 5.0 → 7.5

- **Resolved:**
  - E-01: the invitation link-injection High (`InvitationMailTest`).
  - E-03: email change is refused while central sign-in is on, and the old address is told.
  - E-06: comment-mail preference with one-click unsubscribe.
  - E-07, E-08, E-09, E-11.
  - E-04: mostly; access, sharing, publishing, password and deletion mail are all wired.
- **Partly resolved:**
  - E-02: failures are logged and surface through the job watchdog, but alerting is threshold-based and failed
    jobs are pruned after 7 days.
  - E-05: the claim-code mail is still sent synchronously.
- **Open:** E-10: the cap is spent before sending; there is no sent-mail record.
- **External (EXT):** E-12: DMARC `p=none` with no reporting address, SPF `~all`, no CAA.
- **Evidence:** **VP** the owner's live delivery test on 2026-11-26. The queued mail left the queue within a minute,
  with 0 failures, and arrived in a Gmail inbox, not spam. Its `Authentication-Results` headers were not recorded.

### Performance and scalability: 4.0 → 6.5

- **Resolved:**
  - P-01: derived work outside the write lock.
  - P-03: skip-render saves.
  - P-06: webhooks queued and coalesced.
  - P-13: WAL by config.
  - P-04: foreign-key indexes (mostly; `documents.owner_id` is still unindexed).
  - P-08: the list. EA-9 found it loaded every document in the folder to show subfolders; it was 2.4 s at 10,000
    documents and is now 87 ms.
- **Partly resolved:**
  - P-02: versions are coalesced, but there is no retention, so growth is unbounded.
  - P-05: polls still write the session.
  - P-09: guest pages still load the editor bundle.
  - P-12: view-audit rows grow unbounded.
- **Open or accepted:**
  - P-07: `LIKE` search, about 0.2 s at 10,000 documents.
  - P-10, P-11: latent.
- **Evidence:**
  - **PV** EA-9 local measurement (laptop, PHP built-in server): with 10 writers saving at the same instant there
    were 0 failures, save p99 230 ms (303 ms before EA-5), and history, editor and exports stayed under 60 ms at
    10,000 documents.
  - **EXT/NT**: no host measurement; same-document co-editing under load is not measured; host TTFB is about
    0.6–0.8 s (P-14).

### Code quality: 6.5 → 7.0

- **Resolved:**
  - Q-01: the README.
  - A coverage floor and stricter CI.
  - PHPStan baseline entries removed where the code was fixed.
- **Open:**
  - Q-01: rest of the docs drift (`.ai/rules/deploy.md` describes the old docroot; `.env.example` still says pgsql).
  - Q-02: two search implementations.
  - Q-03: very large files (`sync/host.js` 3,101 lines, the DOCX classes, `Index`).
  - Q-04: PHPStan level 5 with a 66-entry baseline.
  - Q-05: no lazy-loading guard.
  - Q-06: no form requests.

### Infrastructure and operations: 6.0 → 6.5 (capped)

- **Resolved:**
  - O-03: a checked backup before every deploy.
  - O-05: `/up/scheduler` answers 503 when the newest backup is more than 26 h old.
  - O-09: PHP 8.4 and 8.5, browser and axe in CI, coverage floor.
  - O-11: the deploy is pinned to the merged commit.
  - O-15: the watchdog output.
- **Partly resolved:**
  - O-02: the runbook exists; no drill.
  - O-04: a failed deploy puts the old code back and leaves maintenance mode, but a failed smoke check does not roll
    back and migrations are not reversed.
  - O-10: Dependabot PRs are on; alerts are off.
  - O-13, O-14: partly done.
- **Capped by:**
  - O-01: backups only on the production disk; encryption is unverified until the owner sets `BACKUP_PASSPHRASE`.
  - O-02: no restore drill.
  - O-06: no error tracker, and 12 of the last 40 watchdog runs were refused by the host's bot protection.
  - O-07: the 2026-11-23 outage is still unexplained.

## 4. Process findings from the re-audit

- **Deploys do not wait for CI on `main`.** `deploy.yml` runs on every push to `main`, independently of CI on that
  commit. For `d4b9069`, CI on main failed (a Composer download rate limit) while the deploy went ahead. Every PR's
  own CI was green before its merge, so the code was tested, but the gate is on the PR, not on `main`.
  - Fix: trigger the deploy from a successful CI run on `main` (`workflow_run`).
- **The one-review rule does not run in practice.** Every phase was merged with 0 reviews by lowering the rule and
  restoring it, each time on the owner's explicit word. That is a decision, not a bypass, but the recorded protection
  does not describe how the repository is actually governed.
  - Either keep it and add a second reviewer, or set it to 0 and record why.
- **CI does not lint workflow files.** #212 merged a `deploy.yml` GitHub would not parse. `WorkflowFilesAreValidTest`
  now catches duplicate keys only.
  - Fix: run actionlint in the gate.
- **Branch clutter:** 74 branches, and PR #103 is still open (superseded by EA-1).

## 5. Outstanding risks and external dependencies (owner)

| # | Item | Why it matters | Exact step |
| --- | --- | --- | --- |
| 1 | Off-host backups (O-01, **High**) | Losing the host's disk would lose every copy of the data | cPanel → Backup to a remote destination, or a bucket; copy only `doc-*.enc` and `doc-files-*.enc` |
| 2 | `BACKUP_PASSPHRASE` + restore drill (O-02, **High**) | Backups are unencrypted until it is set; a restore has never been proven | `docs/programme/RESTORE_RUNBOOK.md` section 1; record the time and counts |
| 3 | Host load test (M-01/P-14) | All performance numbers are laptop numbers | `docs/programme/EA9_MEASUREMENT.md` section 4 |
| 4 | Mail DNS (E-12) | Spoofing and deliverability | DMARC with a `rua` address, then `p=quarantine`; SPF `-all`; a CAA record |
| 5 | Dependabot alerts (S-15/O-10) | Vulnerable dependencies are not flagged | GitHub → Settings → Code security → Dependabot alerts: on |
| 6 | API tokens (S-07) | All-access, non-expiring tokens | Decide: switch the API off, or keep it with abilities and expiry |
| 7 | Error tracker and an independent uptime monitor (O-06) | The watchdog is blocked by the host's bot protection about 30% of the time | Sentry (or similar) DSN; allowlist the monitor at the host |
| 8 | The 2026-11-23 outage (O-07) | Unexplained | Ask the host for that day's logs |

## 6. Prioritised remaining improvements

| Priority | Improvement | Component |
| --- | --- | --- |
| 1 | Items 1–2 above (owner), then mark O-01/O-02 closed with the drill record | Hosting, `doc:backup-database` |
| 2 | Deploy only after green CI on `main`; actionlint in the gate; roll back on a failed smoke check | `.github/workflows/deploy.yml`, `ci.yml` |
| 3 | Refuse local credential POSTs whatever the request asks for (fleet-wide) | shared `dot-sso-client` package |
| 4 | Enforce a CSP; sanitise or migrate legacy stored HTML on the shared and history pages | `SecurityHeaders`, `shared.blade.php`, `version-history.blade.php` |
| 5 | Keep ownership transfer inside the team, with an audit row; serve uploaded images behind authentication | `DocumentSettings::transferOwnership`, `DocumentImageController` |
| 6 | Version retention (keep named versions, thin automatic ones); prune `document.viewed` audit rows | `DocumentStore`, scheduler |
| 7 | Accessibility: tie Jetstream and editor errors to their fields; 3:1 borders on buttons and tags; editor focus ring; axe with dialogs open, in night mode and on phone; a recorded VoiceOver pass | views, `shell.css`, `paper.css`, `tests/Browser` |
| 8 | Lazy-load the editor bundle on guest pages; remove unused `turbo`/`alpinejs`/`lodash` | `resources/js/app.js`, `package.json` |
| 9 | Restore from trash; finish or remove brand kit and print preview; import-from-Files cleanup | `Files/Navigator`, editor |
| 10 | Code health: one search policy, split `host.js` and the DOCX classes, PHPStan level up and baseline down, `Model::shouldBeStrict()` in tests, form requests; fix `.ai/rules` docs drift | `app/`, `resources/js/editor/sync`, `.ai/rules` |
| 11 | Shared dot-ui account button name (label-content-name-mismatch), fleet-wide | dot-ui kit |
| 12 | Files page paging for large folders (shared with Dot.Files) | `FilesService::children`, `Files/Navigator` |

## 7. Ongoing maintenance and monitoring

- **Daily (automatic):**
  - Nightly backup with an integrity check.
  - `/up/scheduler` answers 503 if the newest backup is more than 26 h old.
  - The GitHub watchdog emails on failure.
- **Every deploy (automatic):**
  - A checked pre-deploy backup.
  - The deploy is pinned to the merged commit.
  - A smoke check with retries.
- **Weekly:**
  - Read Dependabot PRs. Merge patch and minor updates on green CI; plan majors; never merge majors routinely.
  - Read `failed_jobs`.
- **Monthly:**
  - Check DMARC reports once a `rua` address exists.
  - Check the backup sizes are growing as expected.
- **Quarterly:**
  - Restore drill (runbook section 1).
  - Re-run `doc:bench` on staging and compare with `EA9_MEASUREMENT.md`.
  - Re-run this scorecard.
- **Rules that keep the improvements:**
  - The axe gate (serious and critical) and the coverage floor (87%) only ever go up.
  - Every new sheet gets `x-trap` and Escape.
  - Every new field error is tied to its field.
  - New list queries ask the database for what they show, never filter whole folders in PHP.
