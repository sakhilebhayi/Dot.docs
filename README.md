<div align="center">

<img src="public/dot_doc.png" alt="Dot.docs" width="220" />

<br /><br />

**Create, style, and share structured documents across your team in real time.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![TipTap](https://img.shields.io/badge/TipTap-3-2563EB?style=flat-square) ![SQLite](https://img.shields.io/badge/SQLite-WAL-003B57?style=flat-square&logo=sqlite&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `doc.infodot.co.za`

</div>

---

## What is Dot.docs?

Dot.docs is the team document platform in the InfoDot ecosystem. Documents are stored as structured ProseMirror JSON, not raw HTML — which is what makes numbered outlines, cross-references, a table of contents, fourteen switchable document styles, and a real print/PDF surface possible, alongside real-time collaboration, full version history, and granular sharing controls.

## Core Features

- **Structured editor** — TipTap 3 on a custom ProseMirror JSON schema (headings, callouts, columns, figures, page/section breaks, cross-references, variables), with outline numbering and an auto-generated table of contents
- **Document Styles engine** — fourteen system styles (Corporate, Executive, Academic, Legal, Financial, Technical, Government, Mining, Engineering, Marketing, Proposal, Report, Minimal, Creative), each a token set driving fonts, sizes, numbering, and page layout across the editor, print, and DOCX export
- **Server-side print** — a page-accurate PDF surface (`PrintRenderer` + dompdf) with configurable size/orientation/margins and templated headers/footers (`{{ title }}`, `{{ date }}`, `{{ page }}`, ...)
- **Structured import/export** — round-trips through DOCX, Markdown, and plain text, preserving headings, lists, tables, and marks
- **Template gallery** — global, team, and personal templates, including a set of South African business starters (monthly production report, safety incident report, board memorandum)
- **Sharing** — slug-based published pages with optional password and expiry, plus view counters, independent of named per-document collaborator roles
- **Full-text search** — over title and body, within the company the person is standing in, then checked against what they may read; SQLite FTS5 ranked by bm25 in production (`LIKE` only as a fallback), `tsvector`/`ts_rank` when run on PostgreSQL
- **AI assistant** — grammar check, summarise, continue writing, tone rewrite, translate, outline — via `prism-php/prism`, safe-by-default (`AI_PROVIDER=mock`, no network call, no API key needed) with Anthropic as the live primary provider and an OpenAI failover leg
- **Append-only audit log** — every save, export, share, and AI call recorded as an immutable `AuditLog` row; updates/deletes throw rather than fail silently
- **Real-time collaboration** — co-editing over plain HTTP polling: presence, paragraph-level merge of simultaneous saves, and conflict choices (production has no websocket server; Reverb code paths stay off unless a build is given a key)
- **Comments & suggestion mode** — threaded, resolvable comments with @mentions, plus a track-changes-style suggestion pipeline
- **Fair Copy** — the app's own day/night design system (`resources/css/shell.css`, `paper.css`): Roboto throughout (the Dot ecosystem face), quiet hairline panels, status said in words, and one shadow in the product — the document paper

## Domain Models

- **Document** — structured content (`content_json`, canonical) plus a legacy `content` HTML mirror, owner, optional team, style, page setup, brand kit, slug/publish state, and generated search vector
- **DocumentVersion** — snapshot history (including `content_json`), auto-created by `DocumentObserver` on every save
- **DocumentStyle** — a named token set (fonts, sizes, numbering, page layout) — system-wide or team-overridden — resolved per document by `StyleEngine`
- **BrandKit** — a team's logo, fonts, colours, letterhead/footer templates, and default style
- **DocumentTemplate** — reusable starting content (`content_json` + style + page setup) — global, team-owned, or personal
- **DocumentSuggestion** — a pending AI- or human-proposed patch to a block, awaiting accept/reject
- **DocumentCollaborator** — per-user role on a shared document (viewer/editor/admin)
- **Comment** — threaded, resolvable inline annotation (with `parent_id` for replies)
- **AuditLog** — append-only record of who did what to what, when (actor/action/subject/context) — updates and deletes are refused at the model level
- **AiModelUsage** — per-call token/cost/latency accounting for every AI request, mock calls included
- **DocumentSlashCommand** — user- or team-defined `/command` prompt shortcut for the AI assistant
- **DocumentWebhook** — outbound webhook fired on document save/export events, guarded by an SSRF check on the target URL
- **Folder** / **Tag** — organisational structure for the document index

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13 |
| Language | PHP 8.4+ (CI runs 8.5) |
| Editor | TipTap 3 on a custom ProseMirror schema — structured JSON (`content_json`) is the canonical document format; HTML is a rendered/legacy view, not the source of truth |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS 4 — "Two Inks on a Desk" design system (night/day themes) |
| Database | SQLite in WAL mode in production (one file, one writer); PostgreSQL supported, with a generated `tsvector` column for search |
| Realtime | HTTP polling (`/documents/{uuid}/sync`); Laravel Reverb optional, off in production |
| Auth | Central sign-in through InfoDot (OIDC, `packages/dot-sso-client`) + Jetstream Teams |
| AI | `prism-php/prism` — mock provider by default (no network call, forced in tests), Anthropic as the live primary, OpenAI as the failover leg (`config/ai.php`) |
| Styling engine | `App\Styles\StyleEngine` — fourteen document styles, each a token set compiled to CSS (editor/screen) and to DOCX styles (`TokenGuard` sanitises tokens before they reach either surface) |
| Print / PDF | Server-side via `App\Print\PrintRenderer` + `barryvdh/laravel-dompdf`, page setup resolved from the document's style + per-document overrides |
| Import / Export | Structured DOCX (`phpoffice/phpword`) and Markdown round-trip, plain text export |
| Audit | Append-only `AuditLog` model — every save/export/share/AI call, actor/action/subject/context, immutable at the model level |
| Storage | Local disk (Flysystem); no S3 config wired in yet |
| Cache / Session / Queue | Database driver (no Redis, Horizon, Scout, or Meilisearch dependency in `composer.json`) |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.docs.git
cd Dot.docs
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DOTSSO_ENABLED=true` and the `DOTSSO_*` values from InfoDot; people then sign in at InfoDot (OIDC) and come back signed in. Each platform keeps its own database.

> **AI:** Ships with `AI_PROVIDER=mock` — the assistant works out of the box with no API key and no network call. Set `ANTHROPIC_API_KEY` and `AI_PROVIDER=live` to use the real Anthropic models configured in `config/ai.php`.

## Ecosystem

**Dot.docs** is one of the platforms in the InfoDot ecosystem, connected through InfoDot's central sign-in. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)

## Operations

- Deploys run on every merge to `main` (`.github/workflows/deploy.yml`): a verified database backup first, then the tested commit (never an older one) and its migrations; if that step fails the previous release is put back, and if a later step fails the site is at least taken out of maintenance mode.
- Backups: nightly at 02:35 (`doc:backup-database`, database + uploads, encrypted when `BACKUP_PASSPHRASE` is set) and before every deploy; restore with `doc:restore-backup` - see `docs/programme/RESTORE_RUNBOOK.md`. While `DOT_CRON_ESSENTIAL_ONLY` is on (owner decision, 2026-11-27) only mail and notification jobs run, so the nightly backup and every pruning job are paused; `php artisan cron:essential-status` says which mode is on.
- Health: `/up` (also checks that this PHP can read the search index), `/up/scheduler` (scheduler, queue and backup age, and emails given up on in the last hour; watched from GitHub every 15 minutes, which emails the owner when it fails), `/guardian/health` (token).
- Security reports: see `SECURITY.md`.

