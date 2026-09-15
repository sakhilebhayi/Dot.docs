# Dot.Doc ↔ Dot.Files — One Tree Integration

**Status:** approved direction (owner request 2026-09-08), implemented as Phase 1 Tasks 14–15 after Task 13
**Parent spec:** `2026-09-07-dot-doc-platform-design.md`

## The owner's request, sharpened

> "Dot.Doc should link and work well with Dot.Files. Users should be able to create files and folders from Dot.Doc which should then also appear in Dot.Files. Seamless and user-friendly. Use Impeccable and the design skills to build a beautiful UI/UX of the inner pages."

Turned into requirements:

1. **One tree, two doors.** A folder made in Dot.Doc is a Dot.Files folder. A document made in Dot.Doc is a row in the Dot.Files tree. A file uploaded in Dot.Files can be opened, previewed, or turned into a document from Dot.Doc. There is no "sync"; there is one table set both products read and write.
2. **Seamless means no re-login and no dead ends.** "Open in Dot.Files" from Dot.Doc and "Open in Dot.Doc" / "New document here" from Dot.Files land the user on the exact item, signed in, in one click.
3. **Nothing Dot.Doc already gives a document is lost by filing it.** Versions, sharing, comments and soft delete stay on the document; the tree row is a pointer, never a copy.
4. **The inner pages (documents index with the tree, editor, versions, share, settings, templates) get the full design pass**: the design skills (`frontend-design`, `ui-ux-pro-max`) direct the work; the Impeccable detector must report zero anti-patterns on every rendered page in both night and day; contrast is measured, not assumed.

## What the survey found (facts the design must respect)

- Dot.Files' whole domain is three tables: `objects` (polymorphic tree node: `uuid`, `objectable_type/id`, `parent_id`, `team_id`), `files` (`uuid,name,size,team_id,path`) and `folders` (`uuid,name,team_id`). `objectable_type` is aliased to the literal strings `file` / `folder` via `Relation::morphMap`. Every team gets one root object on creation. No API, no service layer (creation lives inline in the `FileBrowser` Livewire component), no soft deletes, no name uniqueness, no versions, no signed URLs, no inline viewer, `HasTeamScope` fails open without a current team.
- The **`folders` table collides three ways** in the shared `infodot` database: Dot.Files, InfoDot (same migrations, with a typo that drops the FK) and Dot.docs (a different shape: `owner_id, team_id nullable, parent_id, name`). All three `Schema::create` unguarded. Whichever migrates second breaks.
- Storage is `Storage::disk('local')` in Dot.Files with root `storage/app`, while Dot.Doc's `local` root is `storage/app/private`. A shared `path` column would resolve to different directories.
- The only cross-platform primitive that works is the single-use five-minute `ecosystem:read` handoff token, redeemed at `/auth/ecosystem`, which always lands on the dashboard.

## Decisions

| # | Decision | Why |
|---|---|---|
| F1 | **Dot.Doc adopts the Dot.Files tree as its folder system.** Dot.Doc's own `folders` table is migrated into `objects`/`folders` and then dropped. Documents become `objects` rows with `objectable_type = 'document'`. | One tree is the only thing that is seamless; two trees plus sync is where every integration like this dies. |
| F2 | **Tree tables are created guarded (`Schema::hasTable`) by whichever app migrates first**, the same way `users`/`teams` already are (Dot.Brain ADR-0013). Dot.Doc ships guarded copies of `objects`/`files`/`folders` so it runs standalone in dev and shares in production. | Resolves the three-way collision without a package. |
| F3 | **A `FilesService` with one verb set lives in Dot.Doc and is copied into Dot.Files** (copy-paste convention, recorded as ecosystem debt for the future `dot/files` package): `root(team)`, `children(obj)`, `createFolder(parent, name, actor)`, `registerDocument(document, parent)`, `moveObject(obj, parent)`, `renameObject(obj, name)`, `deleteObject(obj)` (soft in Dot.Doc's document case; Dot.Files file rows keep hard delete but only through the service), `uniqueName(parent, name)` (`Report`, `Report (2)`). | Dot.Files has no seam today; this creates the seam both sides use. |
| F4 | **Shared blob disk named `files`** in both apps: `FILES_DISK` env (`local` in dev with `FILES_ROOT` pointing at one absolute directory; `s3` in production). Dot.Doc writes exports, imported originals and image uploads it wants filed through this disk. | Removes the root mismatch and prepares S3. |
| F5 | **Deep-link handoff.** `EcosystemAuthController` (both apps) accepts an optional `redirect` query parameter restricted to a relative path on the same app; both apps can mint the handoff token themselves (they share `personal_access_tokens`). Links: Dot.Doc → `DOT_FILES_URL/auth/ecosystem?token=…&redirect=/files?uuid=…`; Dot.Files → `DOT_DOCS_URL/auth/ecosystem?token=…&redirect=/documents/{uuid}/edit`. | One click, signed in, on the item. |
| F6 | **Dot.Files gains the minimum to be a good neighbour**: `'document'` morph alias rendered as a row with a document icon and "Open in Dot.Doc"; a "New document" action in the folder toolbar; `objects.uuid` unique; `files.mime_type` and `files.owner_id`; guarded domain migrations; the copied `FilesService` used by `createFolder`/`updatedUpload`; a signed inline view route `files.view` (`temporarySignedRoute`, 10 min) for previews. | Without these the reverse direction is a dead end. |
| F7 | **Dot.Doc gains a Files navigator on the documents index**: the left rail shows the team tree (folders, documents, files); breadcrumbs; New folder / New document / Upload here; drag-to-move; per-row "Open in Dot.Files"; files show a preview (PDF via the signed route, images inline) and "Open as document" (runs the importer). Personal (team-less) documents live under the user's personal team root, which Jetstream guarantees. | This is the user-facing half of "seamless". |
| F8 | **Search stays per product for now** (Dot.Doc FTS over documents; Dot.Files TNTSearch over names); the tree navigator filters client-side. Unified search is Phase 4 work. | Avoids a third search stack mid-phase. |

## Data model

```
objects (shared)            files (shared)         folders (shared)        documents (Dot.Doc)
id, uuid UNIQUE,            id, uuid, name,        id, uuid, name,         id, uuid, title, …
objectable_type ∈           size, path,            team_id, timestamps     (folder_id dropped;
 {file,folder,document},    mime_type*, owner_id*,                         location = objects row)
objectable_id, parent_id,   team_id, timestamps
team_id, timestamps
* added by Task 15
```

A document's location is the `objects` row whose `objectable_type='document'` and `objectable_id=documents.id`. `Document::node()` returns it; `Document::folder()` becomes `node->parent`. Migration `dot:files:adopt-tree` walks every Dot.Doc folder and document, creates `folders`/`objects` rows under the team root (personal documents under the personal team root), preserving nesting and names (deduplicated with `uniqueName`), then drops `documents.folder_id` and Dot.Doc's `folders` table. Dry-run prints the plan.

## UX (Dot.Doc inner pages)

- **Documents index** becomes a two-pane workspace: the tree in the rail (folders expand in place, counts as mono readouts), the current folder's ledger on the desk (name, kind lamp + word, owner, updated, version, health), a toolbar with New folder · New document · Upload · Import, breadcrumbs above the ledger, and a right dock showing the selected item (preview for files, summary for documents, "Open in Dot.Files", move, rename, share).
- **Empty folder** is one sentence and two actions ("This folder is empty. New document · Upload").
- **Editor** status line shows the document's location as a breadcrumb chip; "Move" opens a folder picker in the dock.
- All chrome follows "Two Inks on a Desk" (parent spec §7). Design skills are invoked before layout work; the Impeccable detector runs on every rendered page (dashboard, index with tree, editor, history, share, settings, templates, published page) in night and day and must print nothing; every text token is measured ≥ 4.5:1 against desk and desk-raised.

## Security

- Every `FilesService` call takes an explicit team and actor; it never relies on `currentTeam` (closes the fail-open scope). Policies: `ObjPolicy` (view/create/move/rename/delete by team membership + Jetstream role), documents still gated by `DocumentPolicy`.
- `redirect` on the handoff is validated: must start with `/`, no `//`, no scheme, no `\`.
- Signed view URLs expire in 10 minutes and are only minted for team members.
- Names are validated (`max:255`, no `/`, `\`, control chars) and deduplicated.

## Tasks (appended to the Phase 1 plan)

- **Task 14 — Dot.Doc side:** guarded tree migrations; `Files\FilesService`; read models `Files\{Obj,File,Folder}`; `Document::node()`; adopt-tree command; Files navigator + index redesign; handoff `redirect`; `files` disk; export "Save to Dot.Files"; tests for the service, policies, migration command and Livewire flows; Impeccable on the index.
- **Task 15 — Dot.Files side (repo `/Users/sakhilebhayi/Dot/Dot.Files`, branch `feature/dot-doc-integration`):** guarded migrations + `uuid` unique + `mime_type`/`owner_id`; morph alias `document`; copied `FilesService` used by `FileBrowser`; "Open in Dot.Doc" / "New document" actions with handoff; `files.view` signed route; handoff `redirect`; tests.
- Both tasks are reviewed with the same SDD loop; the final whole-branch review covers both repos.
