<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The real fixture pair of the paragraph-level merge (Phase 2, section 12
 * of the spec). tests/js/sync.merge.editor.test.js captures
 * editor-form.json, one of everything as TipTap's editor serialises it;
 * this test stores that document through DocumentStore::save() and writes
 * what the store hands back to the editor (stamped contents list and
 * cross-reference labels, `[]` for the empties, no `align: null` on a
 * paragraph or heading) as stored-form.json. The node suite then merges the
 * two over the editor's own schema: a canonicalisation difference that the
 * merge's levelling does not absorb shows up there as a false change or a
 * false loss.
 *
 * The stored form is written when the file is missing or when
 * MERGE_FIXTURE_WRITE=1 is set, and asserted against on every other run,
 * so a change in what the store does to a document is seen here first.
 */
class MergeFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const EDITOR_FORM = 'tests/fixtures/merge/editor-form.json';

    private const STORED_FORM = 'tests/fixtures/merge/stored-form.json';

    public function test_the_stored_form_is_what_the_store_makes_of_the_editor_form(): void
    {
        $editorForm = base_path(self::EDITOR_FORM);
        $this->assertFileExists($editorForm, 'tests/js/sync.merge.editor.test.js captures editor-form.json: run npm test first');
        $json = json_decode(file_get_contents($editorForm), true, 512, JSON_THROW_ON_ERROR);

        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Fixture');
        $store->save($doc, $json, $user);

        $stored = $store->json(Document::findOrFail($doc->id));

        $this->assertSame('doc', $stored['type']);
        $this->assertSame(
            array_map(fn (array $block) => $block['attrs']['id'], $json['content']),
            array_map(fn (array $block) => $block['attrs']['id'], $stored['content']),
            'every top-level block keeps its id on the way through the store',
        );

        $encoded = json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
        $path = base_path(self::STORED_FORM);
        if (! file_exists($path) || getenv('MERGE_FIXTURE_WRITE')) {
            file_put_contents($path, $encoded);
            fwrite(STDERR, "[fixture] wrote {$path}\n");
        }

        $this->assertJsonStringEqualsJsonFile($path, $encoded);
    }
}
