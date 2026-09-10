<?php

namespace App\Documents;

use App\Documents\Import\HtmlToJson;
use App\Documents\Outline\Outline;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;
use App\Files\FilesService;
use App\Models\Document;
use App\Models\Files\Obj;
use App\Models\DocumentStyle;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\WebhookService;
use Illuminate\Support\Facades\DB;
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
        $parent ??= ($team = $owner->currentTeam ?? $owner->personalTeam()) ? $files->root($team) : null;

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

    /** @param array{version?:string,label?:string|null} $opts */
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

        return DB::transaction(function () use ($doc, $json, $actor, $opts) {
            $this->fill($doc, $json);
            $doc->version = $doc->version + 1;
            $doc->save();

            $kind = $opts['version'] ?? 'auto';
            if ($kind !== 'none' && $this->shouldCut($doc, $actor, $kind)) {
                $this->cutVersion($doc, $actor, $kind, $opts['label'] ?? null);
            }
            app(WebhookService::class)->fire($doc, 'on_save');

            return $doc;
        });
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

    public function restore(Document $doc, DocumentVersion $version, User $actor): Document
    {
        abort_unless($version->document_id === $doc->id, 404);
        $json = $version->content_json ?? $this->legacy->convert($version->content_snapshot);

        return $this->save($doc, $json, $actor, ['version' => 'restore', 'label' => 'Restored v'.$version->version_number]);
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
