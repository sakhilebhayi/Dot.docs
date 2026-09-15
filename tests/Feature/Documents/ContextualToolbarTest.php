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
     * A reveal trigger names the panel the control actually acts INTO.
     *
     * `data-shell-expand` opens a panel and never closes one — revealing is
     * one-way by design (.ai/rules/views.md), so a trigger pointing at the
     * wrong panel has no way back. The comments toggle carried
     * `data-shell-expand="dock"` and comments do not render in the dock: they
     * render in `.editor-side`, beside the paper. Pressing it in EITHER
     * direction took ~340px of canvas width for a panel the writer had not
     * asked for and could only close by hand.
     *
     * The assistant is the genuine case and stays: it does live in the dock.
     */
    public function test_only_the_controls_that_act_into_the_dock_reveal_it(): void
    {
        $html = $this->editorHtml();

        $this->assertSame(
            1,
            substr_count($html, 'data-shell-expand='),
            'a second control claims to act into a panel — check that it really does',
        );

        $this->assertSame(1, preg_match(
            '/<button[^>]*data-shell-expand="dock"[^>]*>(.*?)<\/button>/s',
            $html,
            $reveals,
        ));
        $this->assertStringContainsString('Ask the assistant', $reveals[1]);

        // The comments toggle is still there, and still only toggles comments.
        $this->assertSame(1, preg_match(
            '/<button[^>]*wire:click="toggleCommentSidebar"[^>]*>(.*?)<\/button>/s',
            $html,
            $comments,
        ));
        $this->assertStringNotContainsString('data-shell-expand', $comments[0]);
        $this->assertStringContainsString('comments', $comments[1]);
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

        // And the rail does not state it a second time. The rail's contents
        // are rendered by the server whether or not it is open — `⌘\` only
        // changes `data-panel-state` — so what is measured here is exactly
        // what the writer sees the moment they open it for the outline.
        $this->assertSame(1, preg_match('/<aside id="shell-rail".*?<\/aside>/s', $html, $rail));
        $this->assertStringContainsString(
            'This document',
            $rail[0],
            'the rail lost the label that does the section-naming job',
        );
        $this->assertStringContainsString('data-shell-outline', $rail[0]);
        $this->assertStringNotContainsString(
            'Singular Title',
            $rail[0],
            'the rail repeats the document title the persistent bar already carries',
        );
        $this->assertStringNotContainsString('rail-title', $html);

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
     * ledger as `?q=` and asks for the box itself with `?focus=search`. Both
     * are the backing the entry claims, so both are measured here rather than
     * assumed — without the second one, "Search documents" chosen with nothing
     * selected lands on precisely the page "Open another document" lands on,
     * with the cursor nowhere.
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

    public function test_the_ledger_puts_the_cursor_in_the_search_box_when_asked_to(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $focused = $this->actingAs($user)
            ->get(route('documents.index', ['focus' => 'search']))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, preg_match('/<input id="doc-search"[^>]*>/', $focused, $field));
        $this->assertStringContainsString('autofocus', $field[0]);

        // ...and not otherwise: a page anyone opens from the rail must not
        // steal the caret from whatever they came to read.
        $this->app->forgetScopedInstances();

        $plain = $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input id="doc-search"[^>]*>/', $plain, $unfocused));
        $this->assertStringNotContainsString('autofocus', $unfocused[0]);
    }

    /**
     * The editor's palette is what sends that query string, so the page has to
     * be the one asking for it — an entry that navigates somewhere the page
     * does not read is the dead row in a different costume.
     */
    public function test_the_palette_search_entry_asks_the_ledger_for_its_search_box(): void
    {
        $blade = file_get_contents(resource_path('views/livewire/documents/editor.blade.php'));

        $this->assertStringContainsString('?focus=search', $blade);
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

        // The NEGATIVE half — that find/replace, insert-chart and an `analyze`
        // AI pass are absent — used to live here as three
        // assertStringNotContainsString calls against this same source text.
        // They could not fail on any tree: the AI entries are generated as
        // `ai.${name}` from a tuple list, so the literal 'ai.analyze' would
        // never appear however the feature was added. That half now measures
        // the list the module actually BUILDS, in
        // tests/js/registry.test.js ("the registry names nothing the
        // application cannot actually do"), where the generated names exist.
    }

    /**
     * The editor's whole Alpine component is the value of ONE html attribute,
     * `x-data="..."`. A double quote anywhere inside it — in a string, or in a
     * comment naming a menu row — closes the attribute early, and everything
     * after it becomes stray markup: Alpine then reports `SyntaxError:
     * Unexpected token` and the page loses the editor, the save word and every
     * toolbar binding at once. Nothing else in the suite can see that, because
     * the server renders it perfectly happily; this round shipped it for
     * exactly as long as it took to open a browser.
     */
    public function test_the_editors_alpine_component_survives_being_an_html_attribute(): void
    {
        $html = $this->editorHtml();

        // `[^"]*` is not a shortcut here — it is precisely how a browser reads
        // the attribute, so a quote inside the component makes this match stop
        // short of the component's end.
        $this->assertSame(1, preg_match('/\sx-data="([^"]*docUuid[^"]*)"/s', $html, $alpine));
        $this->assertStringContainsString('hostCommand(name, params)', $alpine[1]);
        $this->assertStringEndsWith('}', trim($alpine[1]));
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

        /*
         * A branch is not enough on its own: `style.switch` reaches the page
         * from the palette with NO params (palette.js calls `run(editor, key)`),
         * and the branch was guarded on `params.key`, so choosing "Switch
         * document style" from ⌘K fell straight through and did nothing at
         * all. The handler has to answer the no-key case too, and its
         * destination is the picker in the persistent bar.
         */
        $this->assertStringNotContainsString(
            "name === 'style.switch' && params && params.key",
            $blade,
            'style.switch is guarded on a parameter the palette never sends',
        );
        $this->assertStringContainsString("getElementById('doc-style-picker')", $blade);
        $this->assertStringContainsString('id="doc-style-picker"', $blade);
    }
}
