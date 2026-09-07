<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
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
}
