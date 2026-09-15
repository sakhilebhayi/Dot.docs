# Dot.Doc — Platform Design, Architecture and Roadmap

**Status:** approved direction for Phase 1 implementation (owner brief 2026-09-07)
**Date:** 2026-09-07
**Repo:** `Dot.docs` (this repository) becomes **Dot.Doc**. See §1.

Dot.Doc is the document operating system of the Dot.Ecosystem: it creates, understands,
designs, collaborates on, automates, publishes and manages any document. The owner's brief
(46 sections) is the requirements source; this document turns it into an architecture and a
build order. It is grounded in a full audit of the 35 repositories under `/Users/sakhilebhayi/Dot`
and in current (2025–2026) competitive research. Audit and research summaries are in §2–§3;
everything after that is decision.

---

## 1. Decisions taken up front

The owner was not available while this was written, so the following calls were made and are
recorded here so they can be reversed cheaply if wrong.

| # | Decision | Why |
|---|---|---|
| D1 | **Dot.Doc is built in this repository (`Dot.docs`), not greenfield.** The product is rebranded Dot.Doc; the GitHub repo rename is left to the owner. | The audit found `Dot.docs` already ships a TipTap 3 editor, versions, comments, presence, templates, slash commands, webhooks, SSO, export and 69 passing tests, and its own login page already calls itself "dot.doc". Nothing in it justifies a rebuild; everything in it accelerates Phase 1. |
| D2 | **The canonical document format changes from an HTML blob to structured ProseMirror JSON with stable block IDs.** HTML becomes a derived render cache. | Cross-references, TOC, figure numbering, block-anchored comments, range-level suggestions, data blocks and semantic diff are impossible on an HTML string and trivial on a typed tree with IDs. This is the single most important architectural change and it happens first. |
| D3 | **Editor stays TipTap 3 / ProseMirror**, extended with Dot.Doc node types. Chrome stays Livewire 3 + Alpine, but the editor becomes a real Vite bundle; the Tailwind and Alpine CDN scripts in the app shell are removed. | Same engine the ecosystem already uses (docs, Press). The CDN shell is a performance and design liability. |
| D4 | **Real-time collaboration moves to Yjs + Hocuspocus (Node sidecar) in Phase 3.** Phase 1 keeps Reverb presence and adds the `ydoc_state` column now so the migration is additive. | No CRDT exists anywhere in the ecosystem; Reverb cannot do it. Hocuspocus 4 is stable and MIT. |
| D5 | **AI transport is rebuilt on `prism-php/prism`** (the Dot.Agents pattern) with `AI_PROVIDER=mock` as the default, Anthropic as the primary provider, and the Dot.Analytics `ai_model_usage` schema for token/cost accounting. | Dot.Doc would otherwise be the 13th independent LLM client. Prism gives Anthropic/OpenAI/Gemini/Ollama, streaming and structured output behind one interface. |
| D6 | **Print fidelity is server-side.** Phase 1 renders print CSS through dompdf (already installed); Phase 2 adds Gotenberg (Chromium) as the fidelity engine and keeps dompdf as fallback. Editor pagination is a visual preview, never the source of truth. | Every competitor that paginates in the browser (Docs, Notion) has pagination bugs users have complained about for a decade. |
| D7 | **Search:** Postgres full-text in Phase 1; pgvector + self-hosted embeddings (Ollama, per Dot.Brain ADR-0011) in Phase 2 with a hosted-embedding fallback flag. | ADR-0011 forbids sending classified summaries to hosted embedding APIs; the only existing implementation (Dot.Agents) violates it. Dot.Doc indexes user documents, the strictest case. |
| D8 | **Tenancy:** new tables use `HasTeamScope`; documents keep the existing owner/team/collaborator ACL. **Audit logging is added** (`audit_logs`), the first in the ecosystem. | The audit found zero audit logging ecosystem-wide, and the brief's §26 requires it. |
| D9 | **Design direction: "Two Inks on a Desk"** (§7). Atkinson Hyperlegible for chrome, real paper for the canvas, graphite for human ink and a distinct marker colour for AI ink until accepted. | The current shell (Inter, near-black, one purple accent, radial bloom behind the logo) hits four items on the owner's reject list. |
| D10 | **Shared-package extraction is deferred.** Dot.Doc copies `EcosystemAuthController`, `config/ecosystem.php`, `HasTeamScope` and the error layout like every other repo does, and records the extraction as ecosystem debt. | Extracting `dot/ecosystem` is the right thing but is an ecosystem-wide change; doing it inside a product build would block the product. |

Three items need the owner's answer; work proceeds under the stated assumption:

1. **Shared database.** SSO only works if every product shares one Postgres (`infodot`), yet every repo ships its own `users`/`teams` migrations. Assumption: Dot.Doc runs its own database locally (`dot_docs_verify`) and the production database question is settled at deploy time.
2. **Repository rename** `Dot.docs` → `Dot.Doc` and the ecosystem registry key (`docs` → `doc`) across 26 repos. Assumption: brand changes now, key stays `docs` until the owner renames.
3. **E-signature licence.** DocuSeal is AGPL. Assumption: Phase 5 either runs it as an isolated HTTP service or reuses Dot.Engage's `signature_pad` flow, which is already in-house.

---

## 2. What exists (audit summary)

Full agent reports are summarised here; file paths are exact.

**Foundation.** 26 Laravel 13 / Livewire 3 / Jetstream Teams / Sanctum / Reverb repos with an
identical skeleton. There is **no shared composer or npm package**; "reuse" is copy-paste. SSO is
a single-use Sanctum handoff token (`EcosystemAuthController`, present in 26 repos) that only
works because all apps read one `personal_access_tokens` table. Tenancy is Jetstream Teams +
hand-written `HasTeamScope` global scopes (`Dot.Central/app/Models/Concerns/HasTeamScope.php` has
the best docblocks). Permissions are Jetstream's two roles. Billing has a `billing_usage_records`
table keyed by `platform`/`metric` but no registration API. Dot.Files has no API and hardcodes
the local disk. Dot.Design catalogues tokens but distributes nothing. No audit logging exists.
Domains are `<platform>.infodot.app`.

**AI.** No shared client. Four incompatible patterns across 12 repos. Dot.Agents is the only
real runtime (Prism, failover chain, per-tenant model config, deterministic PHP skills, no
tool-calling loop, no streaming). Dot.Central has the only SSE streaming code. Dot.Analytics has
the best usage schema (`ai_model_usage`). No vector store, no OCR package, no transcription.
Dot.Brain is a specification (DKP knowledge packs, autonomy ladder L1–L3, governance tiers
T1–T4, "Why" blocks, calm-by-default design). Dot.Memory is a loop/telemetry store that is
schema-banned from holding content.

**Adjacent products.** Dot.Sheet: hand-rolled grid, server-side PHP formula evaluator (12
functions, no cross-tab refs), Chart.js, no API. Dot.Forms: nine field types, a reusable
conditional-visibility evaluator, an SSRF guard, no signatures. Dot.Press is a slide editor, not a
CMS. Dot.Engage has the ecosystem's only e-signature flow. Dot.Notify accepts HMAC webhooks and
delivers email/in-app/webhook/Slack (SMS, push are stubs). Dot.Tasks/Projects have no API.

**Data sources.** Dot.Mines is the only production-shaped source: versioned Sanctum API,
`GET /api/openapi.json`, and `ReportDataService::build()` returning `{headers, rows, summary}` for
seven report types. Dot.Analytics has a connector framework (`app/Services/Connectors/`) and a
KPI API nothing feeds. Finance, HR, Farms, Pulse expose no API. No product renders charts
server-side. Dot.HR's rule: individual employment data never leaves the platform.

**This repo.** Document = HTML blob; versions are full snapshots; collaboration is whole-HTML
last-write-wins broadcast; suggestions store a whole-document copy; DOCX export strips
formatting; the shell loads Tailwind and Alpine from CDNs; AI is hardwired to OpenAI `gpt-4o`.

---

## 3. Market position

### 3.1 Where the category is (2026)

Word Agent Mode is default; Gemini drafts from Drive with citations; Notion sells custom agents on
credits; Coda became Superhuman Docs (the only editor with real formulas, and it cannot print);
Canva Docs has no pages; Acrobat owns PDF at a 50–100% premium; Dropbox Paper is sunset; Tome
died pivoting; Gamma is worth $2.1B on prompt-to-deck. Harvey and Spellbook prove vertical,
playbook-driven AI review commands 5–50× SaaS pricing. LibreOffice 26.8 shipped with zero AI.

### 3.2 What stays broken everywhere

Formatting fights; TOC/cross-references/numbering (Word needs training, Docs needs an add-on,
Notion/Canva cannot); tables that are not spreadsheets; data → report outside the vendor's own
cloud; PDF round-trip; version comparison (Word Compare is 2003-era); approvals and signatures
always a second product; brand consistency by fragile templates; nothing offline; nothing in
South African English, ZAR or with POPIA residency by default.

### 3.3 Dot.Doc's twelve signature capabilities (ranked)

| # | Capability | Why Word / Docs / Pages cannot easily match it |
|---|---|---|
| 1 | **Live data blocks** — a table or chart bound to Dot.Mines, Dot.Sheet, CSV, SQL or an API that refreshes and prints correctly | They ground only in their own clouds; they will never connect to a mine's fleet data. |
| 2 | **Recurring report runs** — "generate the October production pack from last month's template and new data, diff against September" | Requires owning the data layer, the template and the diff engine. |
| 3 | **Approval and sign-off trail inside the document model** — checklist-gated versions, signatures, exportable audit bundle | Pushed to SharePoint/DocuSign; cannot be merged into DOCX without breaking it. |
| 4 | **Statutory South African template library maintained as code** (MHSA, DMRE, EE, B-BBEE, POPIA, IFRS notes) with AI fill and regulatory diffs | Too small a market for Microsoft, too vertical for Notion. |
| 5 | **Playbook-driven AI reviewers** for operational documents (safety, tender, board pack, legal, financial) | Needs vertical rule libraries, not a general assistant. |
| 6 | **Guaranteed paginated output** from a block editor (A4, headers, footers, TOC, cross-refs, landscape tables) | Notion/Canva/Craft structurally cannot; Docs has not fixed pagination in ten years. |
| 7 | **Semantic compare with narrative** — "the five most important changes between v3 and v4" | Word Compare is mechanical; Docs has none. |
| 8 | **Document → data** — ingest 100 contracts, ask "which expire in 90 days" | Acrobat charges extra and does not write into an editable report. |
| 9 | **Document Health score** with explanations, including data consistency (summary says 14%, table says 11%) | No competitor checks a document against its own numbers. |
| 10 | **Voice → structured report** for field staff (shift boss dictates, incident template fills, routes for approval) | Competitors' audio is output-only. |
| 11 | **Spreadsheet-grade tables in the page** that still paginate and print | Only Superhuman Docs has formulas and it cannot paginate. |
| 12 | **Per-organisation ZAR pricing, unlimited viewers, POPIA residency** | Per-seat plus credits is their revenue engine. |

The first three are the wedge. They make Dot.Doc a reporting engine that happens to be a
beautiful editor, which is the position no incumbent occupies.

---

## 4. Product architecture

### 4.1 The three areas

The workspace is one screen with three areas that share one document model:

- **Canvas** — the page. Print layout by default, web layout and focus mode as modes.
- **Intelligence** — the right dock. Ask, review, transform, research, health. Every result is a
  proposed change on the canvas in AI ink, never a chat transcript the user must copy from.
- **Data** — the same dock, second tab. Sources, live blocks, tables, charts, variables.

A **navigator** rail on the left carries outline, pages, comments, versions and search. A
one-line **document status line** sits above the canvas and answers "what state is this
document in" in words: saved · v14 · health 91 · 2 reviewers · approval pending.

### 4.2 Services (Laravel, `app/`)

| Service | Responsibility | Depends on |
|---|---|---|
| `Documents\DocumentStore` | Load/save the canonical JSON, derive HTML, bump versions, emit events | `DocumentSchema`, `HtmlRenderer` |
| `Documents\DocumentSchema` | The typed node set (PHP mirror of the TipTap schema), validation, block-ID guarantees | — |
| `Documents\Outline` | Headings, numbering, TOC, figure/table numbering, cross-reference resolution | `DocumentSchema` |
| `Documents\HtmlRenderer` | JSON → HTML for editor hydration, sharing, print | `Styles\StyleEngine` |
| `Styles\StyleEngine` | Document Style (tokens) → CSS for canvas and print; brand kit application | `BrandKit` |
| `Print\PrintRenderer` | HTML + print CSS → PDF (dompdf now, Gotenberg driver later) | `HtmlRenderer` |
| `Import\Importer` | DOCX/MD/HTML/PDF/CSV → JSON | phpword, commonmark, pdfparser |
| `Export\Exporter` | JSON → DOCX/MD/HTML/PDF/TXT | phpword, html-to-markdown, `PrintRenderer` |
| `Ai\Operations` | Named operations (`rewrite`, `summarise`, `generate`, `review`, `transform`…) that take document context and return **JSON patches** | `Ai\Client` |
| `Ai\Client` | Prism wrapper: provider routing, failover, mock, streaming, structured output, usage accounting, injection screen | prism-php |
| `Ai\Reviewers` | Reviewer roles (grammar, legal, financial, technical, executive, compliance, custom) as operation bundles with playbooks | `Ai\Operations` |
| `Health\HealthAnalyzer` | Deterministic checks (structure, references, numbering, consistency, accessibility) plus AI checks; produces a score and findings | `Outline`, `Ai\Operations` |
| `Data\Connectors` | Source registry and connectors (CSV, Sheet, Mines, Analytics, SQL, HTTP); returns the `{headers, rows, summary}` shape | HTTP, Sanctum tokens |
| `Data\LiveBlocks` | Bind a table/chart node to a connector query; refresh, cache, snapshot into versions | `Data\Connectors` |
| `Collab\Presence` | Existing Reverb presence (Phase 1); Hocuspocus bridge (Phase 3) | Reverb |
| `Versions\Snapshots` | Named versions, restore, structural diff, AI change narrative | `DocumentStore` |
| `Workflows\Review` | Review states, assignments, approvals, sign-off, audit bundle | `Audit` |
| `Audit\AuditLog` | Append-only actor/action/subject log for every access and mutation | — |
| `Search\Indexer` | Postgres FTS now, pgvector later; block-level chunks | — |
| `Publish\Publisher` | Public link, slug, web page, PDF, email, knowledge-base article | `Exporter` |
| `Automation\Runs` | Scheduled report generation pipelines (Phase 6) | `Data`, `Ai`, `Workflows`, Dot.Notify |

Each service is a small class with one public verb set and a feature test. Livewire components
call services; they do not contain business logic.

### 4.3 Editor (Vite bundle, `resources/js/editor/`)

TipTap 3 with StarterKit plus Dot.Doc extensions:

- `UniqueId` on every block (stable `data-id`; the JSON carries `attrs.id`).
- `Heading` with `numbered` attribute and auto-numbering decorations from `Outline`.
- `TableOfContents` node (rendered from the outline, never hand-edited).
- `Figure` (image + caption + number), `TableFigure` (table + caption + number).
- `CrossRef` inline node (`targetId`, `kind`: heading/figure/table/page) that renders the
  current number and stays correct when things move.
- `PageBreak`, `Section` (page size, orientation, margins, header/footer override, columns).
- `Footnote` and `Citation` inline nodes with a `References` block (Phase 2 for CSL styles).
- `Variable` inline node (`{{ report.period }}`) resolved from document variables and data.
- `DataTable` and `Chart` nodes (Phase 4), `FormField` and `SignatureBlock` (Phase 5).
- `Callout`, `Columns`, `Divider`, `Math` (KaTeX) as standard blocks.
- Command palette (`⌘K`) and slash menu (`/`) driven by one command registry.
- Suggestion marks (`insertion`, `deletion`, `author`, `ink`) for track changes and AI ink.

The bundle exposes `window.DotDoc.mount(el, options)` and speaks to Livewire through a small
event bridge (`content:changed`, `selection:changed`, `command:run`). Autosave sends JSON, not
HTML. The editor never sends HTML to the server again; `HtmlRenderer` derives it.

---

## 5. Data and document model

### 5.1 Document JSON

ProseMirror JSON, schema version stamped on the root:

```json
{ "type": "doc", "attrs": { "schema": 1, "style": "corporate", "vars": {} },
  "content": [ { "type": "heading", "attrs": { "id": "h_8f2c", "level": 1, "numbered": true },
                 "content": [ { "type": "text", "text": "Executive summary" } ] } ] }
```

Every block has `attrs.id` (8-char base62, generated client-side, validated server-side).
Marks carry `author` (user ID or `ai`) and `ink` (`human` | `ai`) when suggestion mode is on.

### 5.2 Tables (additive migrations on the existing schema)

| Table | Change |
|---|---|
| `documents` | add `content_json jsonb`, `ydoc_state bytea nullable`, `schema_version smallint`, `style_key string`, `brand_kit_id nullable`, `page_setup jsonb` (size, orientation, margins), `variables jsonb`, `health_score smallint nullable`, `health_checked_at`, `review_state string default 'draft'`, `slug nullable unique`, `word_count int`, `search_vector tsvector` (generated). Keep `content` as the HTML render cache. |
| `document_versions` | add `content_json jsonb`, `label nullable`, `kind` (`auto`/`named`/`restore`/`approved`), `summary text nullable` (AI narrative), `word_count`. |
| `document_styles` | new: `key`, `name`, `category` (corporate/executive/academic/legal/financial/technical/government/mining/engineering/marketing/proposal/report/minimal/creative), `tokens jsonb` (type scale, fonts, spacing, colours, numbering rules, caption rules, header/footer templates), `is_system`, `team_id nullable`. |
| `brand_kits` | new (Phase 1 schema, Phase 2 UI): `team_id`, `name`, `logo_path`, `fonts jsonb`, `colours jsonb`, `letterhead jsonb`, `footer jsonb`, `disclaimer text`, `contact jsonb`, `default_style_key`. |
| `document_comments` | existing `comments` gains `block_id nullable` (anchor by ID, falls back to selection text). |
| `document_suggestions` | replaces the whole-document `ai_suggestions` model: `document_id`, `block_id`, `author_type` (`user`/`ai`), `author_id`, `operation`, `patch jsonb` (ProseMirror steps or replacement node), `rationale text`, `status` (`pending`/`accepted`/`rejected`), `resolved_by`, `resolved_at`. The old table is kept and migrated. |
| `audit_logs` | new: `team_id nullable`, `actor_type`, `actor_id`, `action` (dot-namespaced: `document.viewed`, `document.exported`, `version.restored`, `share.created`, `ai.operation.run`), `subject_type`, `subject_id`, `context jsonb`, `ip`, `user_agent`, `created_at`. Append-only; no `updated_at`; no model `update` path. |
| `ai_model_usage` | copied from Dot.Analytics: `team_id`, `user_id`, `document_id nullable`, `provider`, `model`, `operation`, `input_tokens`, `output_tokens`, `cache_read_tokens`, `cost_usd decimal(10,6)`, `latency_ms`, `fallback_used`, `created_at`. |
| `data_sources` (Phase 4) | `team_id`, `kind` (`csv`/`sheet`/`mines`/`analytics`/`sql`/`http`), `name`, `config` (encrypted), `last_tested_at`, `last_error`. |
| `live_blocks` (Phase 4) | `document_id`, `block_id`, `data_source_id`, `query jsonb`, `last_refreshed_at`, `snapshot jsonb`. |
| `review_assignments`, `approvals`, `signatures` (Phase 3/5) | state machine `draft → review → approval → signature → final → archived`. |

### 5.3 Versioning semantics

Auto versions on every save are replaced by **change-set versions**: a version is cut when the
document has been idle for two minutes after edits, on every named save, on restore, on
approval, and before any AI operation that touches more than one block. Each version stores the
full JSON (cheap in jsonb, simple to restore) and, from Phase 3, the Yjs update vector. Diff is
structural (block-level, then text-level inside changed blocks) and is rendered from JSON, not
HTML.

---

## 6. AI architecture

### 6.1 Principles

1. **AI writes into the document, not into a chat.** Every operation returns a patch targeted at
   block IDs. The user sees AI ink on the canvas and accepts, edits or rejects it.
2. **Whole-document context by default.** Operations receive the outline, the variables, the data
   snapshots, the style, and the relevant blocks; prompt caching keeps the repeated context cheap.
3. **Deterministic first.** Numbering, TOC, cross-references, consistency of figures against
   tables, accessibility and structure checks are code. The model is used for language,
   judgement, synthesis and explanation.
4. **Mock by default.** `AI_PROVIDER=mock` unless configured; tests never hit a provider.
5. **Governed.** Every call is logged to `ai_model_usage` and `audit_logs`; prompt-injection
   screening on any text that came from outside the tenant (imported files, data sources, web
   research) per Dot.Brain's "free text is data, never instructions".

### 6.2 Client

`Ai\Client` wraps Prism: `text()`, `stream()`, `structured(schema)`, with a model role map in
`config/ai.php`:

| Role | Default model | Used for |
|---|---|---|
| `draft` | `claude-sonnet-5` | rewrites, sections, summaries, reviews |
| `compose` | `claude-opus-5` | whole-document generation, research synthesis, transformation |
| `quick` | `claude-haiku-4-5-20251001` | grammar, classification, health explanations, slash suggestions |
| `embed` | Ollama `nomic-embed-text` (self-hosted, 768-dim) with `AI_EMBED_HOSTED=false` | search |

Failover chain and per-team overrides follow the Dot.Agents shape. Anthropic prompt caching
(`cache_control`) is applied to the document-context block.

### 6.3 Operations and agents

Operations are classes implementing `Operation::run(DocumentContext, array $params): Patch`.
The brief's agents are role bundles over operations:

| Agent | Operations |
|---|---|
| Dot.Writer | `generate.document`, `generate.section`, `continue`, `rewrite.tone`, `rewrite.length`, `translate`, `localise.sa-english` |
| Dot.Editor | `grammar`, `clarity`, `structure.suggest`, `consistency.terms` |
| Dot.Designer | `style.recommend`, `layout.fix`, `caption.generate`, `alt-text.generate` |
| Dot.Analyst | `data.describe`, `chart.recommend`, `table.from-text`, `insight.narrate` |
| Dot.Researcher | `research.plan`, `research.gather`, `cite`, `fact-vs-opinion.tag` |
| Dot.Reviewer | `review.<role>` with a playbook (grammar/legal/financial/technical/executive/compliance/custom) |
| Dot.Compliance | `policy.check` against team policies stored as documents |
| Dot.Publisher | `transform.<target>` (presentation, summary, article, minutes, plain-english, checklist, sop, course, web) |
| Dot.Archive | `classify`, `extract.fields`, `relate` |

Reviewers return findings, each anchored to a block ID with severity, rationale and an optional
patch. Findings feed the Document Health score and the comments rail.

### 6.4 Document Health

`HealthAnalyzer` produces `{score, findings[]}` with categories readability, grammar, structure,
consistency, formatting, data, citations, missing sections, broken references, duplicates,
unsupported claims, accessibility. Deterministic checks run on every save (cheap); model-backed
checks run on demand or when the document has been idle. The score is explained, never just shown.

---

## 7. UX/UI system — "Two Inks on a Desk"

The owner's bar: a visual identity that could not be mistaken for anyone else's, and "looks
generated" is a defect. The current shell fails that bar (Inter, near-black ground, one violet
accent, radial bloom behind the logo, floating cards). This replaces it.

**The idea.** A document is paper on a desk. The paper is real: white, A4-proportioned, with a
true edge and a shadow that says "this will print exactly like this". The desk is quiet and
matte. Two inks are written on the paper: **graphite** for people and **marker** for the machine.
AI never writes invisibly; its ink is visibly different until a person accepts it, at which point
it becomes graphite. This one rule makes the AI-native promise legible on every page.

**Palette.**

| Role | Night (default) | Day |
|---|---|---|
| Desk | `#14161a` | `#e9e6df` |
| Desk, raised (rails, dock) | `#1b1e24` | `#f2f0ea` |
| Rule | `#2a2f38` | `#d5d1c7` |
| Paper | `#fbfaf7` | `#ffffff` |
| Paper shadow | `rgba(0,0,0,.55)` | `rgba(30,25,15,.18)` |
| Chrome text | `#e6e4de` | `#1e1f22` |
| Chrome text, secondary | `#9a9a94` | `#5d5e5a` |
| Graphite ink (on paper) | `#1f2023` | `#1f2023` |
| Marker ink (AI, pending) | `#0f766e` teal, underlaid `#0f766e14` | same |
| Signal (needs a person) | `#f1c62e` (ecosystem gold) | `#8a6d05` |
| Good | `#3f8f5a` | `#2f7043` |
| Danger | `#c9463d` | `#a9352d` |

Status is never colour alone; every lamp has a word.

**Type.** Chrome is set in **Atkinson Hyperlegible Next** (Braille Institute; drawn for
legibility, not fashion), figures and state words in **Atkinson Hyperlegible Mono**. The default
document style pairs **Source Serif 4** for body with **Source Sans 3** for headings; every
Document Style sets its own pair. No Inter, Geist, Space Grotesk or Plus Jakarta anywhere.

**Structure.** Rails and dock **share edges** with hairline rules; nothing floats. The paper is
the only element with a shadow. The status line is a single row of mono readouts. Panels in the
dock are ledgers, not cards. Empty states are a sentence and one action, never an illustration.

**Motion.** Paper does not animate. AI ink fades in over 180 ms; accepted ink turns graphite
over 240 ms. Reduced-motion disables both.

**Verification.** Every page is run through the Impeccable detector
(`node /Users/sakhilebhayi/Dot/impeccable/cli/bin/cli.js detect <rendered.html>`) and must
report zero anti-patterns; contrast is checked against desk and desk-raised in both modes.

---

## 8. Ecosystem integration

| Concern | Dot.Doc uses | Notes |
|---|---|---|
| SSO | `EcosystemAuthController` at `/auth/ecosystem` (unchanged) | Route name normalised to `auth.ecosystem`. |
| Registry | `config/ecosystem.php` entry `docs` (brand "Dot.Doc") | Key rename is the owner's call. |
| Tenancy | Jetstream Teams + `HasTeamScope` on new tables | Documents keep owner/team/collaborator ACL. |
| Notifications | Local database channel + `NotificationBell`; Dot.Notify via `POST /webhooks/{token}` with `X-Dot-Signature` for review/approval/automation events | Dot.Notify has no send API; the inbound webhook is the contract. |
| Dot.Mines | Connector to `GET /api/v1/reports` + `ReportDataService` shape; `GET /api/openapi.json` for discovery | Sanctum PAT per team, `read` ability. |
| Dot.Analytics | Connector to `GET /api/v1/metrics`; reuse of `Connectors/` interfaces | KPIs empty until something ingests. |
| Dot.Sheet | Needs a new `GET /api/spreadsheets/{id}/ranges/{a1}` endpoint in Dot.Sheet (Phase 4 work item in that repo) | Only an HTML share page exists today. |
| Dot.Charts | `GET /api/backtests/{id}`; the `disclosure` object travels with any embedded figure | Hard constraint from that product. |
| Dot.Finance / HR / Farms | CSV/Excel connector first; APIs do not exist. HR is aggregate-only by that product's rule | Recorded as gaps. |
| Dot.Memory | Loop records (`/api/intelligence/*`) for automation outcomes; never document content | Schema ban on content. |
| Dot.Brain | Publish `platforms/dot-doc.md`, DKP `insight`/`metric` packs from Health and usage; obey autonomy L1–L3 and governance T1–T4 | Automations that send documents externally are L2 (approval) by default. |
| Dot.Engage | Signature flow reference for Phase 5 | `signature_pad`, sequential signers. |
| Dot.Forms | `FormFieldVisibilityEvaluator` pattern for conditional sections | Copy, not import. |
| Dot.Billing | Write `billing_usage_records` rows (`platform=dot.doc`, metrics `ai_tokens`, `documents`, `storage_gb`) | Contract defined here, since none exists. |

---

## 9. Security and enterprise

Team scoping on every new table; document ACL through `DocumentPolicy` only; block-level lookups
always scoped by `document_id` (the two IDOR classes found in the audit are the pattern to
prevent). Audit log on view, export, share, restore, AI run, approval, signature. Share links:
UUID or slug, optional password, expiry, view/download counters, revocation. Encryption at rest
for `data_sources.config` and signature artefacts. Retention policy per team (Phase 5). Rate
limits on AI, export, import, public views. SSRF guard (from Dot.Forms) on every outbound URL.
POPIA: processing region flag per team, redaction operation, data export and erasure actions
(the Dot.Agents `ExportUserDataAction`/`EraseUserDataAction` shape).

---

## 10. Roadmap

Classification: **M** Must have · **S** Should have · **D** Differentiator · **F** Future.

### Phase 1 — Foundation (this build)

| Item | Class |
|---|---|
| Structured JSON document model with block IDs; HTML derived; migration of existing documents | M |
| Editor bundle (Vite), Tailwind/Alpine CDN removed, keyboard-first, command palette + slash menu on one registry | M |
| Outline engine: numbered headings, live TOC block, figure/table numbering, cross-references | D |
| Page model: sections, page size/orientation/margins, page breaks, headers/footers, print preview | M |
| Document Styles engine with the fourteen system styles; instant restyle | D |
| Brand kit schema (UI in Phase 2) | S |
| Shell redesign to the Two Inks system; night/day; accessibility baseline | M |
| Templates gallery on the new model; South African statutory starter set (3 templates) | S |
| Sharing: slug links, password, expiry, view counters; publish as web page | M |
| Import DOCX/MD/HTML/TXT; export PDF (print CSS)/DOCX (structured, not stripped)/MD/HTML | M |
| Audit log; rate limits; SSRF guard on webhooks | M |
| Search: Postgres FTS over title and blocks | M |
| AI transport on Prism with mock default and usage accounting (plumbing only; features are Phase 2) | M |

### Phase 2 — Intelligence

Operations engine and AI ink; Dot.Writer/Editor/Designer; whole-document generation from a
prompt, notes, data or an existing document (D); conversational refinement (M); transformations
(D); Document Health with deterministic + model checks (D); intelligent recommendations (D);
citations with CSL (S); research mode (S); voice dictation via Whisper-class transcription (S);
semantic search with pgvector (S); Gotenberg print engine (M); brand kit UI and auto-branding (S).

### Phase 3 — Collaboration

Yjs + Hocuspocus sidecar; live cursors and per-user ink colours (M); comments anchored to blocks,
mentions, tasks (M); suggestions as patches with accept/reject (M); review states and
assignments (M); section ownership and locking (S); named versions, structural diff, AI change
narrative, "what changed this month" (D); offline drafts merged through Yjs (S).

### Phase 4 — Data

Data sources and connectors (CSV/XLSX, Dot.Mines, Dot.Analytics, Dot.Sheet, SQL, HTTP) (M);
live tables and charts bound to sources, refresh, snapshot per version (D); spreadsheet-grade
tables (formulas, sort, filter, conditional format, totals) via Univer (D); chart engine with
server-side rendering for print (M); Dot.Analyst narratives (D); document → data extraction and
the document database query surface (D).

### Phase 5 — Enterprise

Fine-grained permissions and expiring links (M); e-signatures (Dot.Engage flow or DocuSeal
service) (M); approvals with checklist gates and audit bundle export (D); smart forms with
conditional sections and generated finals (S); document retention and POPIA controls (M);
document analytics (S); universal inbox with classification (S); PDF toolkit (merge, split,
compress, annotate, OCR via Docling, redact, compare) (S); billing usage records (M).

### Phase 6 — Autonomous documents

Automation runs (schedule → gather → generate → review → notify → approve → export → send) with
Dot.Notify and Dot.Memory loop records (D); reviewer playbooks as team assets (D); document
memory (preferred structure, KPIs, terminology) via Dot.Memory-safe summaries (D); proactive
recommendations engine (D); template marketplace (F); mobile and desktop shells (F).

---

## 11. Phase 1 in detail

Deliverables, in build order. Each is one plan task group with tests.

1. **Schema + migration.** New columns (§5.2), `DocumentSchema` in PHP, `HtmlRenderer`,
   backfill command `dot:documents:migrate-json` that parses existing HTML through the TipTap
   schema (server-side via a tiny Node script called once) and assigns block IDs. `DocumentStore`
   becomes the only write path; `Editor::saveContent` accepts JSON.
2. **Editor bundle.** `resources/js/editor/` with the extension set in §4.3 (Phase 1 subset:
   UniqueId, Heading numbering, TOC, Figure, CrossRef, PageBreak, Section, Callout, Columns,
   Variable, suggestion marks), command registry, palette, slash menu, autosave of JSON,
   selection bridge to Livewire.
3. **Outline engine** (`Documents\Outline`) with numbering rules from the style; TOC block
   regeneration on save; cross-reference resolution; tests for renumbering when blocks move.
4. **Styles engine** (`Styles\StyleEngine`) with fourteen seeded `document_styles`; token → CSS
   for canvas and print; style switcher in the dock; tests that every style renders every node.
5. **Page model + print.** Page setup on the document and per section; header/footer templates
   with variables (`{{ page }}`, `{{ pages }}`, `{{ title }}`); print CSS; PDF export through
   `PrintRenderer`; print preview mode in the canvas.
6. **Shell redesign.** New `layouts/app.blade.php` on Vite, Two Inks tokens, navigator rail,
   status line, dock, night/day, keyboard navigation, skip link, focus rings; Impeccable check
   with zero findings; dashboard and documents index restyled onto the same system.
7. **Import/export.** Structured DOCX import (paragraph styles → headings, lists, tables,
   images) and export (styles, numbering, tables, images, headers/footers); MD/HTML/TXT both
   ways; tests with fixture files.
8. **Templates and sharing.** Templates carry JSON + style; three South African starters
   (monthly production report, safety incident report, board memorandum); slug links; view
   counters; publish as web page.
9. **Audit log, search, AI plumbing.** `audit_logs` + `AuditLog` service on view/export/share/
   restore; FTS with `search_vector`; Prism client with mock, usage table, `config/ai.php`,
   the existing five AI features re-pointed through it so nothing regresses.

Out of scope for Phase 1: real-time CRDT, AI ink and operations beyond the existing five,
data connectors, e-signature, Gotenberg.

---

## 12. Testing

Feature tests for every service verb and every Livewire action; schema tests that every node
type round-trips JSON → HTML → JSON; renumbering and cross-reference tests; style rendering
matrix (14 styles × node set); import/export fixture tests; policy tests for every by-ID lookup;
audit tests that every logged action writes a row; AI tests run against the mock provider only.
The suite must stay green at every task; the current 69 tests are the floor.

---

## 13. Ecosystem debt this build records (not fixes)

- No shared `dot/ecosystem` or `dot/ai` package; five files are copy-pasted into 26 repos.
- Shared-database vs per-repo migrations contradiction.
- Dot.Sheet, Dot.Tasks, Dot.Notify, Dot.Finance, Dot.HR, Dot.Farms lack outbound APIs.
- Dot.Agents embeddings violate ADR-0011; no pgvector anywhere.
- Every model ID in the ecosystem's price tables is a generation old.
