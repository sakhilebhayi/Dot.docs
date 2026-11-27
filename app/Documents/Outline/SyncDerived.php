<?php

namespace App\Documents\Outline;

use App\Brand\Brand;
use App\Models\Document;
use App\Models\DocumentStyle;
use App\Styles\StyleEngine;
use Illuminate\Support\Facades\Cache;

/**
 * The outline and canvas stylesheet that the sync poll hands a tab whose copy is behind, computed once per state of
 * the document and shared by every co-editor's poll for it (EA-9 / R2-9).
 *
 * With N people in a document each save made the other N-1 polls rebuild the same outline from the JSON and the same
 * CSS from the style. Both are a pure function of what key() folds in - the document's id, version, content, title,
 * variables and page override, its style key and team, the resolved style row and when it was last changed, the
 * brand kit's own settings, the team's name and today's date (the header and footer bands' {{date}}) - so a result
 * is only reused for exactly the same inputs, and a restyle, a brand-kit edit or a style edit always misses. The style
 * row and the kit are compared by their CONTENTS, not their updated_at: SQLite keeps whole seconds, and two edits
 * inside one second must not leave a co-editor on the first one's CSS (R2-9 review).
 *
 * ONE cache row per document, holding the fingerprint it was computed for and the result, overwritten when the
 * fingerprint changes: a row per version was never read again once the document moved on, and the database cache
 * store only deletes an expired row when that same key is read, so they piled up (R2-9 review). Access is not part of it:
 * the controller authorises before it asks, and the result holds nothing the caller is not already being sent.
 */
class SyncDerived
{
    /** Seconds an entry lives: long enough for every open tab's next poll, short enough not to pile up. */
    public const TTL = 60;

    public function __construct(
        private EditorOutline $outline,
        private StyleEngine $styles,
    ) {}

    /**
     * @return array{outline: array<string, mixed>, css: string}
     */
    public function of(Document $document): array
    {
        $fingerprint = $this->key($document);
        $slot = 'sync-derived:'.$document->getKey();
        $entry = Cache::get($slot);

        if (is_array($entry) && ($entry['for'] ?? null) === $fingerprint && is_array($entry['result'] ?? null)) {
            /** @var array{outline: array<string, mixed>, css: string} */
            return $entry['result'];
        }

        $result = [
            'outline' => $this->outline->of($document),
            // The style may be what changed. The outline carries its numbering and page setup; this carries its
            // fonts and colours.
            'css' => $this->styles->css($this->styles->resolve($document), 'canvas'),
        ];
        Cache::put($slot, ['for' => $fingerprint, 'result' => $result], self::TTL);

        return $result;
    }

    /** Every input the result depends on. Public for the tests. */
    public function key(Document $document): string
    {
        $style = $document->resolvedStyle() ?? DocumentStyle::resolve('report');
        $kit = Brand::forDocument($document);

        $inputs = json_encode([
            'content' => $document->content_json,
            'title' => $document->title,
            'variables' => $document->variables,
            'page' => $document->page_setup,
            'style_key' => $document->style_key,
            'team_id' => $document->team_id,
            'team' => $document->team?->name,
            'style' => [$style?->getKey(), $style?->tokens],
            'kit' => $kit === null ? null : array_diff_key($kit->attributesToArray(), array_flip(['created_at', 'updated_at'])),
            'date' => now()->format('Y-m-d'),
        ], JSON_THROW_ON_ERROR);

        // The id and version in clear, then a hash of everything else: two documents, or two versions of one, can
        // never share a key whatever the hash does.
        return 'sync-derived:'.$document->getKey().':'.$document->version.':'.hash('sha256', $inputs);
    }
}
