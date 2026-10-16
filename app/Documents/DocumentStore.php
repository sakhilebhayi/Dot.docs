<?php

namespace App\Documents;

use App\Documents\Import\HtmlToJson;
use App\Documents\Outline\Outline;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;
use App\Files\FilesService;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Models\DocumentVersion;
use App\Models\Files\Obj;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class DocumentStore
{
    public const AUTO_VERSION_WINDOW_SECONDS = 120;

    public function __construct(
        private DocumentSchema $schema,
        private HtmlRenderer $renderer,
        private Outline $outline,
        private HtmlToJson $legacy,
    ) {}

    /**
     * Create a document and file it in the shared Dot.Files tree.
     *
     * `$parent` is optional so every existing call site keeps working, but
     * omitting it does NOT mean "unfiled": the document is registered under
     * the owner's current-team (or personal-team) root, which is where the
     * navigator shows a document nobody chose a folder for. The only case
     * that skips registration is a user with no team at all - impossible
     * through Jetstream's CreateNewUser, reachable only from a factory.
     */
    public function create(User $owner, string $title, ?array $json = null, array $attrs = [], ?Obj $parent = null): Document
    {
        $json = $this->schema->normalise($this->schema->ensureIds($json ?? DocumentSchema::empty()));
        $doc = new Document(array_merge(['title' => $title, 'owner_id' => $owner->id, 'team_id' => $owner->currentTeam?->id, 'version' => 1, 'style_key' => 'report'], $attrs));
        $this->fill($doc, $json);
        $doc->save();

        $files = app(FilesService::class);

        if ($parent === null) {
            $team = $owner->currentTeam ?? $owner->personalTeam();
            $parent = $team === null ? null : $files->root($team);
        }

        if ($parent !== null) {
            $files->registerDocument($doc, $parent);
        }

        return $doc;
    }

    /**
     * The document as Dot.Doc JSON, ready for the editor.
     *
     * See App\Documents\Import\HtmlToJson::fromStored() for the JSON-or-
     * legacy-HTML fallback and why normalise() runs on both branches -
     * DocumentTemplate::contentJson() shares the same helper.
     */
    public function json(Document $doc): array
    {
        return $this->legacy->fromStored($doc->content_json, $doc->content, $this->schema);
    }

    /**
     * `expectedVersion` is how a writer says "this is the version my copy
     * was based on". When it is given and the stored document has moved on,
     * the save is refused with StaleDocumentException and nothing is
     * written - two people with the same document open would otherwise each
     * replace the other's work with a stale whole-document copy. Writers
     * that replace the document on purpose (restore, import, an accepted
     * suggestion) state no base and always go through. A style change is
     * not one of them: it replaces nothing, and goes through restyle().
     *
     * `$doc` may have been loaded long before this runs (Livewire loads it
     * when the request begins). Nothing is taken from that copy: the row is
     * read again under the write lock, `$doc` is brought up to it, and only
     * then filled from `$json`. So every content column is written from
     * `$json`, compared against what is stored NOW, and the same `$doc` is
     * returned. Anything else the caller changed on `$doc` before calling
     * (a title, say) is written with it, as Eloquent's save() would.
     *
     * `keepReplacedAs` is for a writer that replaces the stored document on
     * purpose: the editor's "Keep mine" and "Put it back" (which replace a
     * newer version their base is on), a restore, an import and an accepted
     * suggestion. Whoever wrote the stored document is not asked, their
     * open tab follows the replacement, and their save may have cut no
     * version of its own (see shouldCut()). So the stored document is first
     * kept as a `named` version with this label, inside the same
     * transaction as the write that replaces it - unless a version row for
     * the stored version number already exists, in which case the text is
     * in the history already and nothing more is cut. A save that is
     * refused, or whose content the schema rejects, keeps nothing.
     *
     * @param  array{version?:string,label?:string|null,expectedVersion?:int,keepReplacedAs?:string}  $opts
     *
     * @throws StaleDocumentException
     */
    public function save(Document $doc, array $json, User $actor, array $opts = []): Document
    {
        // normalise() before validate(): style-bearing attrs (align, column
        // count) are clamped on the way in so a bad value never reaches the
        // renderers or the next editor to open the document.
        $json = $this->schema->normalise($this->schema->ensureIds($json));
        $errors = $this->schema->validate($json);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', $errors));
        }

        $doc = DB::transaction(function () use ($doc, $json, $actor, $opts) {
            $stored = $this->lockStored($doc);
            $current = (int) $stored->version;

            $expected = $opts['expectedVersion'] ?? null;
            if ($expected !== null && $expected !== $current) {
                throw new StaleDocumentException($current);
            }

            // The caller is replacing the stored document on purpose. Keep
            // what is stored, as a named version, inside the same
            // transaction as the write that replaces it - so it exists only
            // if this save is stored, and holds exactly what it replaced.
            // Not when the stored version already has a row of its own: the
            // text is in the history then. That is asked HERE, about the
            // version read under the lock; asked by the caller beforehand,
            // an autosave landing in between would leave a newer head that
            // is in no row and is then replaced unkept.
            if (isset($opts['keepReplacedAs']) && ! $this->hasVersion($stored)) {
                $this->cutVersion($stored, $actor, 'named', $opts['keepReplacedAs']);
            }

            $this->bringUpTo($doc, $stored);
            $this->fill($doc, $json);
            $doc->version = $current + 1;
            $doc->save();

            $kind = $opts['version'] ?? 'auto';
            if ($kind !== 'none' && $this->shouldCut($doc, $actor, $kind)) {
                $this->cutVersion($doc, $actor, $kind, $opts['label'] ?? null);
            }

            return $doc;
        });

        // After the commit, not inside it: a webhook is an HTTP call to
        // somebody else's server, and it must neither hold the database's
        // write lock while it waits nor announce a save that then rolls back.
        app(WebhookService::class)->fire($doc, 'on_save');

        return $doc;
    }

    /**
     * The `keepReplacedAs` label for a writer who saves over a newer
     * version on purpose (the editor's "Keep mine" and "Put it back").
     *
     * Two writers send that save - the Livewire action and the unload
     * beacon - and both take the label from here, so the history reads the
     * same whichever of them carried it. The name is cut so the whole
     * label fits the 120-character column.
     */
    public static function overwriteLabel(User $writer): string
    {
        return 'Before '.Str::limit($writer->name, 80, '').' kept their version';
    }

    /**
     * Change the document's style.
     *
     * A style decides how headings and figures are numbered, and those
     * numbers are stamped into the stored JSON and HTML, so a style change
     * has to renumber and re-render the document. It does that to the
     * document AS IT IS STORED when the change is written: the row is read
     * again under the write lock and its own content is what gets refilled.
     * `$doc`'s copy of the content is never used. It was loaded when the
     * request began, and passing it to save() wrote it back over a save
     * that landed in between - the other person's text was replaced, or
     * the row was left with one person's JSON and the other's search text.
     *
     * The content itself does not change, so no version is cut and nobody's
     * base is asked for; the version number still goes up, because the
     * stored JSON and HTML did change and an open editor has to fetch them.
     * The stored content is normalised but not validated: it was validated
     * when it was stored, and a style is no reason to refuse it now.
     */
    public function restyle(Document $doc, string $styleKey): Document
    {
        $doc = DB::transaction(function () use ($doc, $styleKey) {
            $stored = $this->lockStored($doc);
            $this->bringUpTo($doc, $stored);

            $doc->style_key = $styleKey;
            $this->fill($doc, $this->schema->normalise($this->schema->ensureIds($this->json($doc))));
            $doc->version = (int) $stored->version + 1;
            $doc->save();

            return $doc;
        });

        // After the commit, for the reasons given in save().
        app(WebhookService::class)->fire($doc, 'on_save');

        return $doc;
    }

    /**
     * The document's row as the DATABASE has it now, read inside the
     * caller's transaction and under the write lock. SQLite takes the write
     * lock when an IMMEDIATE transaction opens (config/database.php), so
     * this read and the write that follows cannot interleave with another
     * save; lockForUpdate() gives the same guarantee on PostgreSQL/MySQL.
     *
     * withTrashed(): a document moved to the trash while its editor is open
     * is still saved into, as it was when only the version was read here.
     */
    private function lockStored(Document $doc): Document
    {
        return Document::withTrashed()->lockForUpdate()->findOrFail($doc->getKey());
    }

    /**
     * Bring the caller's model up to the row just read under the lock.
     *
     * Eloquent writes only the columns that differ from what the model was
     * LOADED with. Left on a copy loaded when the request began, that
     * comparison is made against a document somebody else may have replaced
     * since: a column whose new value happens to equal the old copy's is
     * skipped and keeps the other person's value, and the row ends up mixed
     * (or the write is skipped altogether while the version still goes up).
     * After this, "loaded with" is the stored row, so the comparison is
     * exact. What the caller itself changed on the model beforehand is put
     * back on top and stays unsaved, to be written with the content.
     */
    private function bringUpTo(Document $doc, Document $stored): void
    {
        $own = $doc->getDirty();

        $doc->setRawAttributes(array_merge($doc->getAttributes(), $stored->getAttributes()), true);
        $doc->setRawAttributes(array_merge($doc->getAttributes(), $own));
    }

    public function cutVersion(Document $doc, User $actor, string $kind = 'auto', ?string $label = null): DocumentVersion
    {
        return DocumentVersion::create([
            'document_id' => $doc->id,
            'content_snapshot' => $doc->content ?? '',
            'content_json' => $doc->content_json,
            'version_number' => $doc->version,
            'created_by' => $actor->id,
            'created_at' => now(),
            'label' => $label,
            'kind' => $kind,
            'word_count' => $doc->word_count,
        ]);
    }

    /**
     * Replace the document with one of its earlier versions. The document
     * being replaced is kept first (`keepReplacedAs`, see save()): a restore
     * states no base, so it goes through over whatever is stored.
     */
    public function restore(Document $doc, DocumentVersion $version, User $actor): Document
    {
        abort_unless($version->document_id === $doc->id, 404);
        $json = $version->content_json ?? $this->legacy->convert($version->content_snapshot);

        return $this->save($doc, $json, $actor, [
            'version' => 'restore',
            'label' => 'Restored v'.$version->version_number,
            'keepReplacedAs' => 'Before restoring v'.$version->version_number,
        ]);
    }

    /**
     * Fill and persist a document's rendered/derived fields from JSON
     * without cutting a version or firing events. Used by the backfill
     * command to migrate legacy HTML documents onto the JSON pipeline.
     */
    public function refill(Document $doc, array $json): void
    {
        $this->fill($doc, $json);
        $doc->saveQuietly();
    }

    /**
     * Whether the document, at the version it is at, is already in its
     * history. A version row carries the version number of the document it
     * was cut from, so a row with this number holds this content.
     */
    private function hasVersion(Document $doc): bool
    {
        return DocumentVersion::where('document_id', $doc->getKey())
            ->where('version_number', $doc->version)
            ->exists();
    }

    private function shouldCut(Document $doc, User $actor, string $kind): bool
    {
        if ($kind !== 'auto') {
            return true;
        }
        $latest = $doc->versions()->latest('id')->first();
        if ($latest === null) {
            return true;
        }

        return $latest->created_by !== $actor->id
            || $latest->created_at->lt(now()->subSeconds(self::AUTO_VERSION_WINDOW_SECONDS));
    }

    private function fill(Document $doc, array $json): void
    {
        $style = $doc->resolvedStyle() ?? DocumentStyle::resolve('report');
        $rules = $style?->tokens['numbering'] ?? [];
        $result = $this->outline->build($json, $rules);
        $json = $this->outline->apply($json, $result);
        $ctx = RenderContext::editor();
        $ctx->numbers = $result->numbers;
        $ctx->kinds = $result->kinds;
        $ctx->toc = $result->toc;
        $ctx->vars = $doc->variables ?? [];

        $doc->content_json = $json;
        $doc->schema_version = DocumentSchema::VERSION;
        $doc->content = $this->renderer->render($json, $ctx);
        $doc->search_text = $this->schema->plainText($json);
        $doc->word_count = $this->schema->wordCount($json);
    }
}
