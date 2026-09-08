<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Documents\Import\HtmlToJson;
use App\Documents\Schema\DocumentSchema;
use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class DocumentStoreTest extends TestCase
{
    use RefreshDatabase;

    private function para(string $text, string $id = 'p0p0p0p0'): array
    {
        return ['type' => 'doc', 'attrs' => ['schema' => 1], 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => $id], 'content' => [['type' => 'text', 'text' => $text]]],
        ]];
    }

    public function test_save_renders_html_cache_text_and_word_count(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Report');
        $doc = app(DocumentStore::class)->save($doc, $this->para('Three word sentence'), $user);

        $this->assertSame('<p data-id="p0p0p0p0">Three word sentence</p>', trim($doc->content));
        $this->assertSame('Three word sentence', $doc->search_text);
        $this->assertSame(3, $doc->word_count);
        $this->assertSame(2, $doc->version);
    }

    public function test_auto_versions_coalesce_within_two_minutes_for_same_author(): void
    {
        $user = User::factory()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'R');
        $store->save($doc, $this->para('a'), $user);
        $store->save($doc, $this->para('ab'), $user);
        $this->assertSame(1, $doc->versions()->count());

        Carbon::setTestNow(now()->addMinutes(3));
        $store->save($doc, $this->para('abc'), $user);
        $this->assertSame(2, $doc->versions()->count());

        $other = User::factory()->create();
        $store->save($doc, $this->para('abcd'), $other);
        $this->assertSame(3, $doc->versions()->count());
        Carbon::setTestNow();
    }

    public function test_named_version_and_restore(): void
    {
        $user = User::factory()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'R');
        $store->save($doc, $this->para('first'), $user, ['version' => 'named', 'label' => 'v1']);
        $store->save($doc, $this->para('second'), $user, ['version' => 'named', 'label' => 'v2']);
        $v1 = $doc->versions()->where('label', 'v1')->first();
        $doc = $store->restore($doc, $v1, $user);
        $this->assertSame('first', $doc->content_json['content'][0]['content'][0]['text']);
        $this->assertSame('restore', $doc->versions()->latest('id')->first()->kind);
    }

    public function test_json_falls_back_to_legacy_html(): void
    {
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create(['content' => '<h1>Old</h1><p>doc</p>', 'content_json' => null]);
        $json = app(DocumentStore::class)->json($doc);
        $this->assertSame('heading', $json['content'][0]['type']);
    }

    public function test_editor_accepts_json_and_rejects_invalid(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', $this->para('typed'))
            ->assertSet('saved', true);
        $this->assertSame('typed', $doc->fresh()->search_text);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])
            ->call('saveContent', ['type' => 'doc', 'content' => [['type' => 'marquee']]])
            ->assertHasErrors('content');
    }

    public function test_save_normalises_style_bearing_attrs_so_bad_values_never_persist(): void
    {
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Normalised');

        $doc = app(DocumentStore::class)->save($doc, ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'attrs' => ['id' => 'p0p0p0p0', 'align' => 'left; background: url(https://evil.example/x)'], 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['type' => 'paragraph', 'attrs' => ['id' => 'p1p1p1p1', 'align' => 'CENTER'], 'content' => []],
            ['type' => 'columns', 'attrs' => ['id' => 'c0c0c0c0', 'count' => 99], 'content' => [
                ['type' => 'column', 'attrs' => ['id' => 'c1c1c1c1'], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => 'c2c2c2c2'], 'content' => []]]],
                ['type' => 'column', 'attrs' => ['id' => 'c3c3c3c3'], 'content' => [['type' => 'paragraph', 'attrs' => ['id' => 'c4c4c4c4'], 'content' => []]]],
            ]],
        ]], $user);

        // Both attrs land inside a `style` attribute in HtmlRenderer and in
        // the editor's own renderHTML, and a document travels between the two
        // as JSON over Echo without ever passing through parseHTML.
        $this->assertArrayNotHasKey('align', $doc->content_json['content'][0]['attrs']);
        $this->assertSame('center', $doc->content_json['content'][1]['attrs']['align']);
        $this->assertSame(4, $doc->content_json['content'][2]['attrs']['count']);
        $this->assertStringNotContainsString('evil.example', $doc->content);
        $this->assertStringContainsString('style="--cols:4"', $doc->content);
    }

    public function test_a_legacy_html_document_converts_to_json_the_editors_content_check_accepts(): void
    {
        // The shape a legacy blob actually has: an empty <table> and a <ul>
        // with no <li>. DocumentSchema::validate() is happy with both, but
        // ProseMirror's content expressions (`table` is `tableRow+`,
        // `bulletList` is `listItem+`) are not — and the editor is built with
        // `enableContentCheck: true`, so before the repairs such a document
        // opened READ-ONLY with autosave off.
        $json = app(HtmlToJson::class)->convert('<p>a</p><table></table><ul></ul>');

        $types = [];
        (new DocumentSchema)->walk($json, function (array $node) use (&$types): void {
            $types[] = $node['type'] ?? '';
        });

        $this->assertSame(['doc', 'paragraph', 'text'], $types);
        $this->assertSame([], (new DocumentSchema)->validate($json));

        // And through the store, which is how the editor page reads it.
        $user = User::factory()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'content' => '<p>a</p><table></table><ul></ul>',
            'content_json' => null,
        ]);

        $opened = app(DocumentStore::class)->json($doc);

        $this->assertSame(['paragraph'], array_column($opened['content'], 'type'));
    }

    public function test_a_stored_document_with_thin_legacy_nodes_is_repaired_on_the_way_to_the_editor(): void
    {
        $user = User::factory()->create();
        // Written before the repairs existed: an empty list, and a list item
        // whose first child is not a paragraph.
        $doc = Document::factory()->for($user, 'owner')->create(['content_json' => [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'attrs' => ['id' => 'p0p0p0p0'], 'content' => []],
                ['type' => 'orderedList', 'attrs' => ['id' => 'o0o0o0o0'], 'content' => []],
                ['type' => 'bulletList', 'attrs' => ['id' => 'b0b0b0b0'], 'content' => [
                    ['type' => 'listItem', 'attrs' => ['id' => 'l0l0l0l0'], 'content' => [
                        ['type' => 'codeBlock', 'attrs' => ['id' => 'k0k0k0k0'], 'content' => []],
                    ]],
                ]],
            ],
        ]]);

        $opened = app(DocumentStore::class)->json($doc);

        $this->assertSame(['paragraph', 'bulletList'], array_column($opened['content'], 'type'));
        $this->assertSame(
            ['paragraph', 'codeBlock'],
            array_column($opened['content'][1]['content'][0]['content'], 'type')
        );
    }
}
