<div align="center">

<img src="public/dot_doc.png" alt="Dot.Doc" width="220" />

<br /><br />

**Create, style, and share structured documents across your team in real time.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![TipTap](https://img.shields.io/badge/TipTap-3-2563EB?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `docs.infodot.app`

</div>

---

## What is Dot.Doc?

Dot.Doc is the team document platform in the InfoDot ecosystem. Documents are stored as structured ProseMirror JSON, not raw HTML — which is what makes numbered outlines, cross-references, a table of contents, fourteen switchable document styles, and a real print/PDF surface possible, alongside real-time collaboration, full version history, and granular sharing controls.

## Core Features

- **Structured editor** — TipTap 3 on a custom ProseMirror JSON schema (headings, callouts, columns, figures, page/section breaks, cross-references, variables), with outline numbering and an auto-generated table of contents
- **Document Styles engine** — fourteen system styles (Corporate, Executive, Academic, Legal, Financial, Technical, Government, Mining, Engineering, Marketing, Proposal, Report, Minimal, Creative), each a token set driving fonts, sizes, numbering, and page layout across the editor, print, and DOCX export
- **Server-side print** — a page-accurate PDF surface (`PrintRenderer` + dompdf) with configurable size/orientation/margins and templated headers/footers (`{{ title }}`, `{{ date }}`, `{{ page }}`, ...)
- **Structured import/export** — round-trips through DOCX, Markdown, and plain text, preserving headings, lists, tables, and marks
- **Template gallery** — global, team, and personal templates, including a set of South African business starters (monthly production report, safety incident report, board memorandum)
- **Sharing** — slug-based published pages with optional password and expiry, plus view counters, independent of named per-document collaborator roles
- **Full-text search** — PostgreSQL `tsvector`/`ts_rank` over title and body (falls back to `LIKE` on SQLite), scoped to what the signed-in user is allowed to see
- **AI assistant** — grammar check, summarise, continue writing, tone rewrite, translate, outline — via `prism-php/prism`, safe-by-default (`AI_PROVIDER=mock`, no network call, no API key needed) with Anthropic as the live primary provider and an OpenAI failover leg
- **Append-only audit log** — every save, export, share, and AI call recorded as an immutable `AuditLog` row; updates/deletes throw rather than fail silently
- **Real-time collaboration** — presence, live cursors/avatars, and broadcast content updates via Laravel Reverb
- **Comments & suggestion mode** — threaded, resolvable comments with @mentions, plus a track-changes-style suggestion pipeline
- **"Two Inks on a Desk"** — the app's own night/day design system: a mono status line, ledger-style panels, and desk-seam rules instead of cards

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
| Language | PHP 8.3+ |
| Editor | TipTap 3 on a custom ProseMirror schema — structured JSON (`content_json`) is the canonical document format; HTML is a rendered/legacy view, not the source of truth |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS — "Two Inks on a Desk" design system (night/day themes) |
| Database | PostgreSQL, with a generated `tsvector` column powering full-text search (SQLite in the test suite) |
| Realtime | Laravel Reverb (presence channels, broadcast events) |
| Auth | Laravel Sanctum (InfoDot SSO) + Jetstream Teams |
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

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and `APP_URL=https://docs.infodot.app`. Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens.

> **AI:** Ships with `AI_PROVIDER=mock` — the assistant works out of the box with no API key and no network call. Set `ANTHROPIC_API_KEY` and `AI_PROVIDER=live` to use the real Anthropic models configured in `config/ai.php`.

## Ecosystem

**Dot.Doc** is one of **21 platforms** in the InfoDot ecosystem, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
