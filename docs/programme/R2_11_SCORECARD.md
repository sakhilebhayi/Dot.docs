# Dot.docs — independent re-audit after R2-9 and R2-10 (R2-11)

Date: 2026-11-27. Audited commit: `43b73d0` (R2-10 deployed). Same method as `R2_8_SCORECARD.md`: four auditors,
each scoring two areas from a clean checkout of the deployed commit, with the brief and the 2026-11-26 baseline
findings but without the round-2 PR texts; every finding's state established from the code, the test suite (run in
full: 1,270 PHP tests, 0 warnings; 1,110 JS tests) and a few spaced anonymous requests to the live site. Security
details are kept in the private audit notes, not in this public repository.

## 1. Weighted scorecard

| Area | Weight | Baseline | R2-8 | **R2-11** | Weighted |
| --- | ---: | ---: | ---: | ---: | ---: |
| Security and data protection | 20% | 6.0 | 7.0 | **8.0** | 1.60 |
| Functional correctness and regression stability | 15% | 6.5 | 8.0 | **7.5** | 1.13 |
| UX/UI quality and usability | 15% | 6.5 | 7.5 | **7.5** | 1.13 |
| Accessibility | 10% | 6.0 | 7.5 | **7.0** | 0.70 |
| Email and notification reliability | 10% | 5.0 | 6.5 | **6.0** | 0.60 |
| Performance and scalability | 10% | 4.0 | 6.0 | **6.0** | 0.60 |
| Code quality and architecture | 10% | 6.5 | 8.0 | **8.0** | 0.80 |
| Infrastructure and operational reliability | 10% | 6.0 | 6.0 | **6.5** | 0.65 |
| **Total** | 100% | **5.9** | **7.1** | | **7.2** |

**How to read it.** Security rose a full point: every in-app High from the baseline is fixed with tests, and the
app-level sign-in guard is live. Three areas dropped half a point because these auditors looked deeper and found
things R2-8 did not (below) - not because anything regressed. No area reaches 9: what separates them from it is now
mostly evidence only the owner can produce (section 4) plus the specific code items in section 3.

## 2. What the auditors verified as done

- **Security:** S-01, S-02, S-03, S-04, S-07, S-11 fixed and retested; enforced nonce CSP and HSTS verified live;
  private files refused; images private through `/media` (S-13, older pictures need the owner's one-off command).
- **Functional:** F-01 to F-13 fixed or hidden, each with tests; suite clean with 0 warnings (O-08).
- **UX and accessibility:** every U-* finding fixed in code; focus traps and Escape on every sheet; errors tied to
  fields in the product; palette and slash-menu ARIA; control edges measured at 3:1 in both themes in CI.
- **Email:** queued mail with retries, send-once ledger, give-up alert path through `/up/scheduler`, one-click
  unsubscribe, escaped user text (E-01, E-04 to E-11).
- **Performance:** hot-path work, FTS5 search, quiet sync session, per-version outline cache, editor bundle only on
  the editor page (P-01, P-03, P-04, P-06, P-07, P-09, P-13).
- **Code quality:** PHPStan level 8 with no baseline, coverage floor 90% (90.64%), large files split, lazy-loading
  guard, one company-scoped search.
- **Infrastructure:** CI-gated deploy with a checked pre-deploy backup, pinned SHA, rollback on a failed check.

## 3. New findings (code)

| Sev | Area | Finding |
| --- | --- | --- |
| **High** | Performance | The documents list's visibility filter (owner OR collaborator OR team) makes SQLite scan every document in the database, all companies: 51 ms at 60k documents, 151 ms at 180k on a laptop, twice per page view (`Index.php` documents query). |
| **Medium** | Infra / Email | `/up/scheduler` treats an overdue nightly backup as unhealthy even while essential-only mode has paused backups: from about 04:35 UTC on 2026-11-28 it answers 503 for good, the GitHub watchdog fails every 15 minutes and a real mail or scheduler failure is lost in that noise. |
| Medium | Performance | Search scores full-text matches across every company before scoping to one (grows with the platform, not the tenant). |
| Medium | Code / UX | Two visibility rules for the same documents: the list (owner/collaborator/team) and search (one company); the tag filter follows the list. |
| Medium | Accessibility | Sign-in pages' errors not tied to their fields; validation summary not announced. |
| Medium | Accessibility | Some live regions exist only while visible (assistant "Working", `@error` alerts); axe gate passes moderate issues and does not reach the shared, published, password, request-access, claim pages or the open menus. |
| Medium | UX | The welcome page hides its content until a script runs; a blocked script leaves it blank. |
| Low | Email | A comment email can still go for a document moved to the Bin during the two-minute delay; "shared with you" can follow a removal; ledger write failures are silent; bare domains in names and titles become links in mail clients. |
| Low | Security | One older hand-over path, sign-in enumeration through invites, a client-settable view property, production-only checks keyed on the environment name (details private). |
| Low | Various | Docs drift (PHPStan level in `.ai/rules/app.md`, a report-only comment), panel inputs below 3:1, a few focus and reduced-motion details, autosave not quiet, unpruned database cache rows, null broadcast on every save. |

## 4. Owner-side items (evidence only the owner can produce)

| Item | Effect | What to do |
| --- | --- | --- |
| Production environment setting (`APP_ENV`) | Security, infrastructure | Exact steps in the private notes; a deploy check should then enforce it. |
| `OPS_ALERT_EMAIL` | Email, infrastructure | Set in the production `.env`, then `php artisan config:cache`. |
| Off-host encrypted backups and one restore drill (O-01, O-02 - the two open Highs in infrastructure) | Infrastructure | `RESTORE_RUNBOOK.md`; record the time the restore took. |
| Accepted risk: essential-only scheduler (backups and all pruning paused) | Performance, infrastructure, retention promises | Restart: `DOT_CRON_ESSENTIAL_ONLY=false`, then `php artisan config:cache`. |
| Shared sign-in package 0.1.1 (#236) | Security | Merge and re-sync the fleet. |
| `documents:privatise-images` on the host | Security | `--dry-run`, then the real run, after a backup. |
| DMARC `rua` then quarantine, SPF `-all`; a recorded delivery test with `Authentication-Results` | Email | DNS and two test inboxes. |
| Host load test and time to first byte (0.6-3.2 s measured today) | Performance | `doc:bench` on the host; the hosting plan. |
| Dependabot alerts; stale branches | Security, infrastructure | GitHub settings. |
| A VoiceOver and NVDA pass of the core flows | Accessibility | A recorded session. |

## 5. What would take each area to 9 or more

- **Security:** the package fix synced; `APP_ENV=production` with a deploy check; the older hand-over path refused
  while central sign-in is on; uniform invite answers with a throttle; the client-settable property locked; the
  InfoDot signature with a nonce, method and path; Dependabot on.
- **Functional:** browser journeys for co-editing conflict, version restore with an open editor, comments and
  mentions, DOCX/PDF round trip and Bin restore; suggesting mode built or removed; the Bin and removal cases in mail.
- **UX:** a recorded rendered pass of every screen at three widths in both themes; error and welcome pages fully on
  Fair Copy; one name for signing in; the welcome page readable without scripts.
- **Accessibility:** a screen-reader pass; sign-in errors tied to fields; persistent live regions; axe failing on
  moderate issues and reaching every listed screen; 3:1 edges on the remaining inputs.
- **Email:** the probe fix (section 3); `OPS_ALERT_EMAIL`; DMARC/SPF and recorded authenticated delivery; links
  defanged in user text; ledger failures logged.
- **Performance:** the list and search scoped by company before anything else (with a query-plan test); retention
  running; a host load test; host latency.
- **Code quality:** one visibility rule shared by list, search and tags; docs drift closed; remaining inline
  validation in form requests; a CI run with the production scheduler setting.
- **Infrastructure:** off-host encrypted backups with a drill; the probe fix; an alert recipient and an independent
  uptime monitor; an incident runbook.
