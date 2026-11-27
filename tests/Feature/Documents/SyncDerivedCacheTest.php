<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Outline\EditorOutline;
use App\Documents\Outline\Outline;
use App\Documents\Outline\SyncDerived;
use App\Models\Document;
use App\Models\DocumentCollaborator;
use App\Models\DocumentStyle;
use App\Models\User;
use App\Print\HeaderFooterBands;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EA-9 / R2-9: when a document changes, the outline and canvas CSS the sync poll hands back are built once per state
 * of the document and shared by every co-editor's poll, and a new version, another document or a changed style never
 * reuses an old result.
 */
class SyncDerivedCacheTest extends TestCase
{
    use RefreshDatabase;

    private int $builds = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $test = $this;
        $this->app->instance(EditorOutline::class, new class(app(Outline::class), app(StyleEngine::class), app(HeaderFooterBands::class), $test) extends EditorOutline
        {
            public function __construct(Outline $outline, StyleEngine $styles, HeaderFooterBands $bands, private SyncDerivedCacheTest $test)
            {
                parent::__construct($outline, $styles, $bands);
            }

            public function of(Document $document): array
            {
                $this->test->built();

                return parent::of($document);
            }
        });
    }

    public function built(): void
    {
        $this->builds++;
    }

    private function para(string $text): array
    {
        return ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]]]];
    }

    private function poll(User $user, Document $doc, int $version, string $tab): array
    {
        return $this->actingAs($user)
            ->postJson(route('documents.sync', $doc->uuid), ['version' => $version, 'tab' => $tab])
            ->assertOk()
            ->json();
    }

    public function test_co_editors_polling_for_the_same_version_share_one_build(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Shared');
        $others = User::factory()->count(3)->withPersonalTeam()->create();
        foreach ($others as $other) {
            DocumentCollaborator::create(['document_id' => $doc->id, 'user_id' => $other->id, 'role' => 'editor']);
        }

        app(DocumentStore::class)->save($doc->fresh(), $this->para('First change'), $owner);
        $answers = [];
        foreach ($others as $i => $other) {
            $answers[] = $this->poll($other, $doc, 1, 'tab-'.$i);
        }

        $this->assertSame(1, $this->builds, 'Three polls for version 2 built the outline more than once.');
        foreach ($answers as $answer) {
            $this->assertTrue($answer['changed']);
            $this->assertSame(2, $answer['version']);
            $this->assertSame($answers[0]['outline'], $answer['outline']);
            $this->assertSame($answers[0]['css'], $answer['css']);
            $this->assertSame('First change', $answer['json']['content'][0]['content'][0]['text']);
        }

        // What the cache served is exactly what a fresh build gives.
        $styles = app(StyleEngine::class);
        $this->assertSame($styles->css($styles->resolve($doc->fresh()), 'canvas'), $answers[2]['css']);

        // A newer version misses and is built once more.
        app(DocumentStore::class)->save($doc->fresh(), $this->para('Second change'), $owner);
        $this->poll($others[0], $doc, 2, 'tab-0');
        $this->poll($others[1], $doc, 2, 'tab-1');
        $this->assertSame(2, $this->builds);
    }

    public function test_a_restyle_or_an_edited_style_row_misses(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($owner, 'Styled');
        $derived = app(SyncDerived::class);

        $before = $derived->key($doc->fresh());
        $this->assertSame($before, $derived->key($doc->fresh()), 'The key is not stable for an unchanged document.');

        // The style row's tokens changed underneath (a team style edited) with no new document version - in the same
        // second, which SQLite's whole-second updated_at could not tell apart: a different key (R2-9 review).
        $row = DocumentStyle::where('key', $doc->style_key)->firstOrFail();
        $tokens = $row->tokens;
        $tokens['colours']['heading'] = '#112233';
        $row->forceFill(['tokens' => $tokens])->saveQuietly();
        $edited = $derived->key($doc->fresh());
        $this->assertNotSame($before, $edited);

        // A touch alone (nothing the CSS reads) does not throw the cached result away.
        $row->touch();
        $this->assertSame($edited, $derived->key($doc->fresh()));

        // A restyle: a different key, and the poll's CSS is the new style's.
        $restyled = app(DocumentStore::class)->restyle($doc->fresh(), $this->otherStyle($doc->style_key));
        $this->assertNotSame($edited, $derived->key($restyled->fresh()));
        $answer = $this->poll($owner, $doc, 1, 'tab-a');
        $styles = app(StyleEngine::class);
        $this->assertSame($styles->css($styles->resolve($restyled->fresh()), 'canvas'), $answer['css']);

        // A title change (the header band's {{title}}) also misses.
        $renamed = $restyled->fresh();
        $renamed->title = 'Renamed';
        $this->assertNotSame($derived->key($restyled->fresh()), $derived->key($renamed));
    }

    /** R2-9 review: one cache row per document, overwritten - a row per version was never read again and piled up. */
    public function test_new_versions_reuse_the_documents_one_cache_row(): void
    {
        config(['cache.default' => 'database']); // production's store, where rows can pile up
        $owner = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($owner, 'Busy');
        $derived = app(SyncDerived::class);

        foreach (range(1, 5) as $n) {
            $doc = $store->save($doc->fresh(), $this->para('Line '.$n), $owner);
            $derived->of($doc->fresh());
        }

        $rows = DB::table('cache')->where('key', 'like', '%sync-derived:%')->count();
        $this->assertSame(1, $rows);
    }

    public function test_two_documents_never_share_a_key_even_with_identical_content(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $owner = User::factory()->withPersonalTeam()->create();
        $a = app(DocumentStore::class)->create($owner, 'Same');
        $b = app(DocumentStore::class)->create($owner, 'Same');
        $derived = app(SyncDerived::class);

        $this->assertNotSame($derived->key($a->fresh()), $derived->key($b->fresh()));
        $this->assertStringStartsWith('sync-derived:'.$a->id.':'.$a->fresh()->version.':', $derived->key($a->fresh()));
    }

    private function otherStyle(string $current): string
    {
        foreach (StyleEngine::systemKeys() as $key) {
            if ($key !== $current) {
                return $key;
            }
        }

        $this->fail('Only one system style.');
    }
}
