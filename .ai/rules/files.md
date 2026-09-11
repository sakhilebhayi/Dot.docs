---
paths:
  - 'app/Files/**'
  - 'app/Models/Files/**'
  - 'app/Console/Commands/AdoptFilesTree.php'
  - 'app/Policies/ObjPolicy.php'
  - 'app/Livewire/Files/**'
  - 'app/Http/Controllers/FileUploadController.php'
  - 'app/Http/Controllers/FileViewController.php'
  - 'app/Actions/Jetstream/DeleteUser.php'
---

# Files

## The shared Dot.Files tree is ONE table set, and the migration order is what makes it land

`objects` is the tree (one row per node), `files` and `folders` are the two blob/label types Dot.Files owns, and Dot.Doc reads and writes the same three tables — there is no sync layer and no Dot.Doc folder table any more. `objects.objectable_type` holds a MORPH-MAP ALIAS (`file` / `folder` / `document`, registered in `AppServiceProvider::boot()`), never a class name, so neither product's namespaces leak into rows the other one reads. Every table is created behind `Schema::hasTable()` (the Dot.Brain ADR-0013 convention this repo already uses for `users`/`teams`): whichever app migrates first against the shared database creates them, the rest skip.

`folders` is the collision point, and the ONLY ordering that survives `php artisan migrate` from an empty database AND from one already carrying Dot.Doc's legacy folder system is:

1. `2026_09_08_000001` creates `objects`, `files` and the folder table under the NON-COLLIDING name `tree_folders`; Dot.Doc's legacy `folders` (owner_id/team_id/parent_id/name, no uuid) is left completely alone.
2. `2026_09_08_000002` renames the legacy table aside to `document_folders_legacy`, then renames `tree_folders` → `folders`.
3. `2026_09_08_000003` runs `dot:files:adopt-tree`, then drops `documents.folder_id` and `document_folders_legacy` — the column before the table, because the foreign key followed the rename.
4. `2026_09_08_000004` adds the two uniqueness constraints the tree needs from the database (see below).

Dot.Doc's OWN pre-2026_09_08 folder migrations are part of that sequence too, because they run chronologically FIRST: `2026_08_10_161301_create_folders_table` skips creating anything when a `folders` already exists (a re-run, or the shared table a Dot.Files instance made), and `2026_08_10_161302` skips adding `documents.folder_id` when `folders` is the shared shape. Unguarded, 161301 crashed `php artisan migrate` mid-batch with "table folders already exists" against exactly the shared-database scenario the rest of this set exists to support — `tests/Feature/Files/SharedTreeMigrateTest` runs the real command against a throwaway database to keep that honest.

The rename comes BEFORE adoption, not after. `App\Models\Files\Folder` names exactly one table (`folders`, pinned with `protected $table`), and adoption writes through that model and through `FilesService`, so there has to be a `folders` for it to reach by the time it runs. The first WIP created the shared table straight over the name in 000001 and shipped nine `QueryException`s — "table folders has no column named owner_id" — because the legacy `Folder` model was still inserting into it. Tell the two apart by SHAPE, never by name: a `folders` WITH a `uuid` column is the shared one, WITHOUT is Dot.Doc's legacy table.

`dot:files:adopt-tree` owns NO schema. It refuses to run unless `objects` exists and `folders` has a `uuid` column, precisely so a run before 000002 cannot write shared rows into the legacy table. `--dry-run` therefore writes nothing at all — not a row, not a table.

## FilesService never trusts `currentTeam`

Every method takes its team from the object it was handed — `$parent->team_id`, or the `Team` argument on `root()` — and never from `Auth::user()->currentTeam`. Dot.Files' own `HasTeamScope` reads `currentTeam` and falls OPEN when a user has none, which is how a team-less session could see another team's rows; nothing in `App\Files\FilesService` consults the session at all, so that failure mode is unreachable from this side. There is deliberately NO global team scope on `App\Models\Files\Obj` either — adding one would reintroduce exactly the bug the service closes.

The ACTOR is a separate argument from the team, and every mutating method authorises through `ObjPolicy` (`Gate::forUser($actor)`), so a caller that forgets to authorise cannot open a hole. `ObjPolicy` answers off the node's OWN `team_id`, so switching current team never widens or narrows what a person can reach. `create` is authorised against the PARENT (`Gate::authorize('create', [Obj::class, $parent])`).

The one place `currentTeam` is read is picking WHICH workspace to open when nothing was named — `BrowsesTheTree::workspaceTeam()` (shared by `Documents\Index` and `Files\Navigator`), `DocumentStore::create()`'s fallback. Everything after that derives from a node, which is what keeps an id or uuid in a query string from reaching another team's rows.

## `deleteObject` means three different things, and that is the point

- a **document** node is soft-deleted (the document keeps its own trash/restore lifecycle) and its `objects` row goes, so it leaves the browser at once. `DocumentObserver::restored()` files it again at the team root, because a restore would otherwise bring back a document nothing lists.
- a **folder** with anything in it is REFUSED with a `ValidationException` — cascading would take real documents and files down with a piece of filing. Dot.Doc's old folder table nulled `documents.folder_id` on delete, which silently rearranged someone's filing; the shared tree does not get to do that.
- a **file** takes its blob off the `files` disk with it, since nothing else references it.

The team root (`parent_id === null`) can be neither moved nor deleted.

## Blobs live on a private disk reached only through a signed route

The `files` disk is `local`, rooted at `FILES_ROOT` (default `storage/app/files`), `visibility => private`, and is NOT exposed under `public/storage`. Both products point it at ONE directory so a `files.path` row written by either resolves to the same bytes. `FilesService::createFile()` composes the stored path itself — `documents/{team_id}/{40 random chars}.{sanitised extension}` — so nothing a person can type decides where bytes land; only the extension survives.

`FileViewController` is the only way back out, and it needs BOTH gates: the `signed` middleware (10-minute expiry) proves the link was minted here, and the controller then checks the viewer belongs to the FILE's team — a signed link is not a bearer token for anyone who happens to be logged in. The path is resolved with the same `realpath()`-containment discipline `DocxExporter::publicDiskPath()` uses (`.ai/rules/documents-io.md`): a row on a SHARED database was not necessarily written by this app, so what it resolves to is proved to be under the disk root before anything is read.

## Move is a sheet, not a drag

There is no drag-and-drop anywhere in the tree UI and no `resources/js/files/tree.js`. Opening a folder is navigation (a button that changes which folder is listed, with breadcrumbs back up), and Move is a `.sheet` folder picker built from `FilesService::folderChoices($teamId)` — the same APG pattern `documents/index.blade.php` established for rename, Escape closing it and returning focus to the control that opened it. That is keyboard-operable from the first commit rather than as a fallback bolted onto a pointer gesture, and it is why there is no custom JS file to keep in step with the Livewire state.

## Nothing uploaded is ever rendered in this origin unless it is on the inline allow-list
FilesService::createFile() stores bytes VERBATIM (no re-encoding, unlike DocumentImageController's raster WebP pass) and FileViewController serves them back, so the type decides whether a file can run as a document with the viewer's session. Two halves, both required: FileUploadController's mimetypes: list excludes image/svg+xml entirely (an SVG is a script that renders as a picture; it is not a documented Files use case), and FileViewController sends Content-Disposition: inline ONLY for its INLINE_TYPES allow-list (png/jpeg/gif/webp, pdf, text/plain|markdown|csv) and `attachment` for everything else. The second half is not redundant: `files` is a SHARED table, so a row written by a Dot.Files instance can name any type at all. Do not widen either list to text/html or image/svg+xml. Covered by NavigatorTest::test_an_svg_upload_is_refused and FileViewTest's two disposition cases.

## Deleting a document ALWAYS goes through FilesService::deleteObject
Never call $document->delete() from a UI path. The service soft-deletes the document AND drops its `objects` row in one transaction; a raw delete leaves the node behind, where it is invisible in every listing (children() rejects a node whose objectable is gone) yet still counts in deleteObject()'s "is anything filed in here" check - so the folder that held it can never be deleted again, with no user-facing recovery. DocumentSettings::delete() shipped with exactly that bug in Task 14 and now routes through the service. The same reasoning covers the one delete that CANNOT route through it: DeleteUser::forgetTreeNodes() removes the nodes by hand before documents.owner_id's FK cascade hard-deletes the documents themselves.

## root() and registerDocument() are races the DATABASE decides, not PHP
Both resolve a node with a query and create one if it was missing, which two concurrent first-time callers can both pass - two roots for one team, or two live nodes for one document, and neither is recoverable afterwards. Migration 2026_09_08_000004 adds the two constraints that settle it: objects(objectable_type, objectable_id) unique, and a PARTIAL unique index on objects(team_id) WHERE parent_id IS NULL AND objectable_type = 'folder' (pgsql and sqlite - identical syntax, and sqlite is what the suite runs on; mysql has no partial indexes and keeps the PHP check alone). Both call sites catch UniqueConstraintViolationException and re-fetch the winner's row instead of raising, and both create inside DB::transaction so a nested call rolls back to a SAVEPOINT rather than poisoning a caller's transaction on postgres. Any new writer of `objects` follows the same shape. If the migration ever fails with "could not create unique index", the data already carries duplicates - merge them, do not drop the constraint.

## TRACKED LIMITATION: deleting an account still takes its documents with it
documents.owner_id is a NOT NULL FK with cascadeOnDelete, so Jetstream's Delete Account hard-deletes every document the person owned - including documents filed in OTHER teams they merely belonged to - without passing through DocumentObserver or FilesService. Task 14 fix round 1 closed the half that corrupts the shared tree (DeleteUser::forgetTreeNodes() removes those `objects` rows first, so no folder is left permanently undeletable by an invisible orphan) and deliberately did NOT change the ownership semantics: reassigning a leaver's documents to a team admin means making owner_id nullable or reassignable, and owner_id is the primary predicate in DocumentPolicy and DocumentSearch's SQL. That is a product decision, still open. Do not describe account deletion as safe for other teams' documents until it is made.

## Both tree pages share BrowsesTheTree
App\Livewire\Documents\Index and App\Livewire\Files\Navigator both stand in the tree and had their own copies of three small pieces: the currentTeam-then-personalTeam workspace lookup, the "resolve this folder id, fall back to my own root when it is missing, foreign or not a folder" rule, and guarded(), which turns a FilesService ValidationException into an error on the field that caused it. They live in App\Livewire\Files\BrowsesTheTree now. Put a new tree page on the trait rather than copying them again, and change the fallback rule in one place - it is a security-shaped rule (a foreign id must show YOUR root, never theirs, and never a 403 that confirms the folder exists).
