<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\User;
use App\Services\WebhookService;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A style change renumbers and re-renders the document AS IT IS STORED when
 * the change is written - never the copy the request loaded when it began,
 * which another person's save may have replaced in the meantime.
 */
class DocumentRestyleTest extends TestCase
{
    use RefreshDatabase;

    private const THEIRS = 'Original plus what B typed';

    /**
     * A heading (its number is what a style changes) over one paragraph,
     * with or without a contents list in front.
     */
    private function body(string $text, bool $withContentsList): array
    {
        $content = [];
        if ($withContentsList) {
            $content[] = ['type' => 'toc', 'attrs' => ['id' => 't1t1t1t1', 'depth' => 3]];
        }
        $content[] = ['type' => 'heading', 'attrs' => ['id' => 'h1h1h1h1', 'level' => 1], 'content' => [['type' => 'text', 'text' => 'Intro']]];
        $content[] = ['type' => 'paragraph', 'attrs' => ['id' => 'p1p1p1p1'], 'content' => [['type' => 'text', 'text' => $text]]];

        return ['type' => 'doc', 'content' => $content];
    }

    private function lastParagraphText(Document $doc): string
    {
        $blocks = $doc->content_json['content'];

        return $blocks[count($blocks) - 1]['content'][0]['text'];
    }

    /**
     * The four system styles whose heading numbering differs from the
     * default `report` style, so the stored JSON and HTML really change.
     *
     * @return array<string, array{string, bool}>
     */
    public static function stylesThatRenumber(): array
    {
        $cases = [];
        foreach (['executive', 'marketing', 'minimal', 'creative'] as $style) {
            $cases[$style.', with a contents list'] = [$style, true];
            $cases[$style.', without one'] = [$style, false];
        }

        return $cases;
    }

    /**
     * Through the real component. A's request loads the document at version
     * 1; B's autosave then commits version 2 (injected at A's
     * authorize('update'): after Livewire has loaded A's copy, before A's
     * write opens its transaction); then A's style change is written.
     *
     * Before DocumentStore::restyle() the style change re-saved A's copy:
     * with a contents list B's text was replaced in `content_json` and
     * `content` while `search_text` kept it, and without one the rendered
     * `content` alone went back to A's.
     */
    #[DataProvider('stylesThatRenumber')]
    public function test_a_style_change_keeps_a_save_that_landed_after_its_request_loaded_the_document(string $style, bool $withContentsList): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $a = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($a, 'Race', $this->body('Original', $withContentsList));

        $editor = Livewire::actingAs($a)->test(Editor::class, ['uuid' => $doc->uuid]);

        $landed = false;
        Gate::before(function (User $user, string $ability) use (&$landed, $doc, $store, $withContentsList) {
            if (! $landed && $ability === 'update') {
                $landed = true;
                $store->save(Document::findOrFail($doc->id), $this->body(self::THEIRS, $withContentsList), $user, ['expectedVersion' => 1]);
            }

            return null;
        });

        $editor->call('setStyle', $style)
            ->assertDispatched('style-changed', fn (string $event, array $params) => ($params['version'] ?? null) === 3);

        $this->assertTrue($landed, 'The other save must have landed inside the style change request.');

        $stored = Document::findOrFail($doc->id);

        $this->assertSame(3, $stored->version);
        $this->assertSame($style, $stored->style_key);
        $this->assertSame(self::THEIRS, $this->lastParagraphText($stored), 'content_json must hold the later save.');
        $this->assertStringContainsString(self::THEIRS, $stored->content, 'The rendered content must hold the later save.');
        $this->assertStringContainsString(self::THEIRS, $stored->search_text, 'The search text must hold the later save.');

        // What the page is handed back is the stored document too.
        $blocks = $editor->get('contentJson')['content'];
        $this->assertSame(self::THEIRS, $blocks[count($blocks) - 1]['content'][0]['text']);
    }

    public function test_restyle_renumbers_the_stored_document_and_bumps_the_version_without_cutting_one(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Numbered', $this->body('Body', false));

        $this->assertStringContainsString('<span class="num">1</span>', $doc->content);

        // A copy loaded before somebody else's save.
        $loadedEarlier = Document::findOrFail($doc->id);
        $store->save(Document::findOrFail($doc->id), $this->body(self::THEIRS, false), $user, ['version' => 'none']);

        $restyled = $store->restyle($loadedEarlier, 'executive');

        $this->assertSame(3, $restyled->version);
        $this->assertSame('executive', $restyled->style_key);
        $this->assertSame(self::THEIRS, $this->lastParagraphText($restyled));

        $stored = Document::findOrFail($doc->id);
        $this->assertSame(3, $stored->version);
        $this->assertSame('executive', $stored->style_key);
        $this->assertStringNotContainsString('<span class="num">', $stored->content);
        $this->assertStringContainsString(self::THEIRS, $stored->content);
        $this->assertSame(0, DocumentVersion::where('document_id', $doc->id)->count());
    }

    /** A document still stored as legacy HTML has no JSON to pass along; restyle reads it the way the editor does. */
    public function test_a_document_still_stored_as_legacy_html_can_be_restyled(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = Document::factory()->for($user, 'owner')->create([
            'team_id' => $user->currentTeam->id,
            'content' => '<p>Written before the JSON pipeline</p>',
            'content_json' => null,
            'version' => 4,
        ]);

        $restyled = app(DocumentStore::class)->restyle($doc, 'legal');

        $this->assertSame(5, $restyled->version);

        $stored = Document::findOrFail($doc->id);
        $this->assertSame('legal', $stored->style_key);
        $this->assertSame('Written before the JSON pipeline', $stored->search_text);
    }

    public function test_restyle_fires_the_webhook_once_after_the_change_has_committed(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Announced');
        $outerLevel = DB::transactionLevel();

        $this->mock(WebhookService::class, function (MockInterface $mock) use ($outerLevel) {
            $mock->shouldReceive('fire')->once()->andReturnUsing(function () use ($outerLevel) {
                $this->assertSame($outerLevel, DB::transactionLevel(), 'The webhook must fire after the transaction has closed.');
            });
        });

        app(DocumentStore::class)->restyle($doc, 'legal');
    }
}
