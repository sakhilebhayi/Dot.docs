<?php

namespace Tests\Feature\Documents;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec §4: the permanent bench is retired.
 *
 * The twelve formatting buttons that used to wrap into three ragged rows at a
 * realistic width now come to the SELECTION (resources/js/editor/ui/bubble.js,
 * measured by tests/js/toolbar.test.js) or live in the two menus that already
 * listed them, `/` and ⌘K. What is left on the page is one slim row of the
 * things that have to be true all the time.
 *
 * These assertions are about the markup the SERVER renders, which is the half
 * a browser check cannot regress-protect: a button put back into the Blade
 * toolbar in six months would pass every JS test in the project.
 */
class ContextualToolbarTest extends TestCase
{
    use RefreshDatabase;

    private function editorHtml(?User $user = null): string
    {
        $this->seed(DocumentStyleSeeder::class);
        $user ??= User::factory()->withPersonalTeam()->create();
        $document = app(DocumentStore::class)->create($user, 'Contextual toolbar');

        return $this->actingAs($user)
            ->get(route('documents.edit', $document->uuid))
            ->assertOk()
            ->getContent();
    }

    /**
     * The retired buttons, by the label or aria-label they carried. Each one
     * is still reachable — through the floating toolbar, the `/` menu or ⌘K —
     * but none of them is on the persistent row any more.
     */
    public function test_the_persistent_bar_carries_no_block_insert_button(): void
    {
        $html = $this->editorHtml();

        foreach ([
            'aria-label="Heading 1"',
            'aria-label="Heading 2"',
            'aria-label="Heading 3"',
            'aria-label="Bulleted list"',
            'aria-label="Numbered list"',
            'aria-label="Blockquote"',
            'aria-label="Insert a table"',
            'title="Insert an image"',
            'aria-label="Bold"',
            'aria-label="Italic"',
        ] as $retired) {
            $this->assertStringNotContainsString(
                $retired,
                $html,
                "the persistent bar still carries the retired control {$retired}",
            );
        }

        // And the row that held them is gone, not merely emptied.
        $this->assertStringNotContainsString('class="doc-tools"', $html);
        $this->assertStringNotContainsString('class="doc-cluster"', $html);
    }

    /**
     * What the row DOES carry (spec §4 plus the task brief): the style picker,
     * the ⌘K door, the save word, the version and — when anyone is here — the
     * presence faces. Asserting the positive half matters as much as the
     * negative one: "no buttons" is also satisfied by deleting the row.
     */
    public function test_the_persistent_bar_carries_the_five_things_that_stay(): void
    {
        $html = $this->editorHtml();

        $this->assertStringContainsString('class="doc-bar"', $html);
        $this->assertStringContainsString('id="doc-style-picker"', $html);
        $this->assertStringContainsString('&#8984;K', $html);
        // The save word is the shared component, not a hand-rolled span.
        $this->assertMatchesRegularExpression('/class="status-word status-word-good"/', $html);
        $this->assertMatchesRegularExpression('/class="numeral"[^>]*>v\d/', $html);
    }

    /**
     * The title lives ONCE.
     *
     * Task 1 shipped it twice: the top bar resolved it through ShellContext
     * and the editor rendered its own inline field under that. The editor's
     * field is the canonical one — it is the only one you can type in — so the
     * bar names the section instead on this route, and only on this route.
     */
    public function test_the_editor_names_the_document_in_exactly_one_place(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $document = app(DocumentStore::class)->create($user, 'Singular Title');

        // ShellContext memoises the route's document for one request, and one
        // test method issues several against one container. Forgetting the
        // scoped bindings before each is what makes the loop below measure
        // each page rather than the first page's memo.
        $this->app->forgetScopedInstances();

        $html = $this->actingAs($user)
            ->get(route('documents.edit', $document->uuid))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/<div class="topbar-title">(.*?)<\/div>/s', $html, $found));
        $this->assertSame(
            'Documents',
            trim(html_entity_decode(strip_tags($found[1]))),
            'the top bar repeated the document title the editor already edits inline',
        );

        // Exactly one editable copy, and it is the editor's own.
        $this->assertSame(1, substr_count($html, 'id="doc-title"'));
        $this->assertStringContainsString('wire:model.blur="title"', $html);

        // Every OTHER document route still names the document up there, because
        // on those pages nothing else does.
        foreach ([
            route('documents.history', $document->uuid),
            route('documents.share', $document->uuid),
            route('documents.settings', $document->uuid),
        ] as $url) {
            $this->app->forgetScopedInstances();

            $other = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertSame(1, preg_match('/<div class="topbar-title">(.*?)<\/div>/s', $other, $named));
            $this->assertSame(
                'Singular Title',
                trim(html_entity_decode(strip_tags($named[1]))),
                "{$url} stopped naming the document it is about",
            );
        }
    }

    /**
     * The palette's `search` entry carries the writer's selection to the
     * ledger as `?q=`. That query string is the backing the entry claims, so
     * it is measured here rather than assumed.
     */
    public function test_the_ledger_reads_a_search_off_the_query_string(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $store->create($user, 'Quarterly forecast');
        $store->create($user, 'Holiday rota');

        $this->actingAs($user)
            ->get(route('documents.index', ['q' => 'Quarterly']))
            ->assertOk()
            ->assertSee('Quarterly forecast')
            ->assertDontSee('Holiday rota');
    }

    /**
     * Everything the retired buttons did is still reachable, and the bundle is
     * the one place that decides where from. A palette entry with nothing
     * behind it is worse than an absent one (spec §4), so the two the owner
     * brief named but the application cannot do yet — find/replace and insert
     * chart — must NOT be in the registry.
     */
    public function test_the_registry_adds_only_commands_with_something_behind_them(): void
    {
        $registry = file_get_contents(resource_path('js/editor/commands/registry.js'));

        foreach ([
            "name: 'share'",
            "name: 'search'",
            "name: 'recent.open'",
            'export.${format}',
            'ai.${name}',
            "tableOp('addRow'",
            "name: 'image.alt'",
        ] as $present) {
            $this->assertStringContainsString($present, $registry, "the registry is missing {$present}");
        }

        foreach (['find.replace', 'insert.chart', 'ai.analyze'] as $absent) {
            $this->assertStringNotContainsString(
                $absent,
                $registry,
                "{$absent} was added to the registry with no implementation behind it",
            );
        }
    }

    /**
     * Every `system` command the registry can emit has a branch in the page's
     * `hostCommand()`. A command that falls off the end of that function is a
     * palette row that silently does nothing — the exact failure the spec
     * forbids, and one no JS test can see because the handler is in Blade.
     */
    public function test_every_host_command_the_registry_names_has_a_branch_in_the_page(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/documents/editor.blade.php'));

        foreach ([
            'export.pdf',
            'export.word',
            'export.html',
            'export.markdown',
            'share',
            'recent.open',
            'search',
            'style.switch',
            'comment',
        ] as $name) {
            $this->assertStringContainsString(
                "'{$name}'",
                $blade,
                "hostCommand() has no branch for the registry command {$name}",
            );
        }

        // The AI passes arrive under one name with an action parameter.
        $this->assertStringContainsString("name === 'ai'", $blade);
        $this->assertStringContainsString("'shell:reveal-dock'", $blade);
    }
}
