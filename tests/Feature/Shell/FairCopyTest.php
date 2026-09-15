<?php

namespace Tests\Feature\Shell;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

class FairCopyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fixture carries TWO documents on purpose.
     *
     * With one, `index.blade.php` never renders its "load more" sentinel, and
     * the banned-font check below used to be a bare `assertDontSee('Inter')`
     * that passed only because the page happened not to contain the substring
     * inside `new IntersectionObserver`. A second document makes the list long
     * enough to reach every branch that the first one skipped.
     */
    public function test_no_retired_chrome_survives_on_any_touched_page(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $store = app(DocumentStore::class);
        $doc = $store->create($user, 'Fair Copy check');
        $store->create($user, 'Fair Copy second');

        foreach ([
            route('dashboard'),
            route('documents.index'),
            route('documents.edit', $doc->uuid),
            route('documents.history', $doc->uuid),
            route('documents.share', $doc->uuid),
            route('documents.settings', $doc->uuid),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertDontSee('<x-shell.lamp', false)
                ->assertDontSee('class="status-line"', false)
                ->assertDontSee('class="lamp"', false)
                ->getContent();

            $this->assertBannedFontsAreAbsent($html, $url);
        }
    }

    /**
     * DOM ORDER IS TAB ORDER, and the top bar leads it.
     *
     * The bar was last inside `.shell` (the grid put it back on top whatever
     * the order), on the reasoning that Tab should reach the document before
     * the chrome. That stopped being right when the panels started defaulting
     * to COLLAPSED: a collapsed panel is `display: none` and out of the tab
     * order entirely, so the toggles up here are the only way to open either
     * one — and they were a keyboard reader's first visible control and their
     * LAST tab stop, after the whole document.
     *
     * It is also what makes the editor's floating toolbar reachable by Tab at
     * all. That toolbar is appended to `<body>` (resources/js/editor/ui/bubble
     * .js explains why it cannot live inside the canvas — `.canvas-region`'s
     * `container-type` makes it the containing block for anything positioned
     * inside it), so with the bar last, tabbing out of the paper hit the bar's
     * rail toggle, the editor blurred, and the toolbar took itself out of the
     * tab order before focus could ever come round to it. Alt+F10 is the
     * belt-and-braces route, measured in tests/js/toolbar.test.js.
     */
    public function test_the_top_bar_leads_the_shell_in_dom_order(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Tab order');

        foreach ([route('dashboard'), route('documents.index'), route('documents.edit', $doc->uuid)] as $url) {
            $this->app->forgetScopedInstances();

            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $at = [];

            foreach ([
                'the top bar' => 'class="topbar"',
                'the rail' => 'id="shell-rail"',
                'the canvas' => 'id="canvas"',
                'the dock' => 'id="shell-dock"',
            ] as $what => $needle) {
                $found = strpos($html, $needle);
                $this->assertNotFalse($found, "{$url} renders no {$what}");
                $at[$what] = $found;
            }

            $ordered = $at;
            asort($ordered);

            $this->assertSame(
                ['the top bar', 'the rail', 'the canvas', 'the dock'],
                array_keys($ordered),
                "{$url}: the shell's DOM order no longer matches what is on screen, so the panel toggles are not the first tab stops",
            );
        }
    }

    /**
     * The banned faces are banned as TYPE, not as a substring: "Inter" lives
     * inside IntersectionObserver, "Roboto" inside a filename, and a bare
     * `assertDontSee` on either is a trap that passes for the wrong reason.
     * What must never appear is the face in a font stack or in a webfont
     * request, so that is what is measured.
     */
    private function assertBannedFontsAreAbsent(string $html, string $where): void
    {
        foreach (['Inter', 'Geist', 'Space Grotesk', 'Plus Jakarta Sans', 'Atkinson Hyperlegible'] as $face) {
            // A Google Fonts request spells a two-word face "Plus+Jakarta+Sans",
            // a CSS stack spells it "Plus Jakarta Sans"; one pattern covers both.
            $spaced = str_replace(' ', '[\s+]', preg_quote($face, '/'));

            $this->assertDoesNotMatchRegularExpression(
                "/font(?:-family)?\s*[:=]\s*[^;}>]*{$spaced}/i",
                $html,
                "{$where} declares the banned face {$face} in a font stack",
            );

            $this->assertDoesNotMatchRegularExpression(
                "/(?:family=|@font-face[^}]*){$spaced}/i",
                $html,
                "{$where} requests the banned face {$face} as a webfont",
            );
        }
    }

    /**
     * Proof the check above is not vacuous: the same assertion, run over markup
     * that really does declare a banned face, has to fail.
     */
    public function test_the_banned_font_check_catches_a_banned_face(): void
    {
        foreach ([
            '<style>body { font-family: Inter, sans-serif; }</style>',
            '<div style="font-family:\'Plus Jakarta Sans\',sans-serif"></div>',
            '<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400" rel="stylesheet">',
            '<link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible" rel="stylesheet">',
        ] as $markup) {
            try {
                $this->assertBannedFontsAreAbsent($markup, 'canary');
            } catch (AssertionFailedError) {
                continue;
            }

            $this->fail("the banned-font check let this through: {$markup}");
        }

        // And the false-positive it replaced must now pass.
        $this->assertBannedFontsAreAbsent('<div x-init="new IntersectionObserver(() => {})"></div>', 'canary');
    }

    public function test_the_editor_ships_a_topbar_and_starts_with_panels_collapsed(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Panels');

        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertSee('class="topbar"', false)
            ->assertSee('data-panel-state="collapsed"', false)
            ->assertSee('Fraunces', false)
            ->assertSee('Work+Sans', false);
    }

    /**
     * An UNCOMPILED component tag renders as literal text and takes the rest of
     * the tag's siblings with it. Blade's component compiler does not parse
     * directives inside an attribute list, so
     * `<x-shell.status-word ... @if ($x) flag @endif />` left the whole topbar
     * status word in the output as "<x-shell.status-word :tone=..." — and every
     * string assertion in this file still passed, because the raw text contains
     * the same words the rendered markup would have. Only the browser saw it.
     */
    public function test_no_component_tag_survives_uncompiled_on_any_page(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Compiled');

        foreach ([
            route('dashboard'),
            route('documents.index'),
            route('documents.index', ['gallery' => 1]),
            route('documents.edit', $doc->uuid),
            route('documents.history', $doc->uuid),
            route('documents.share', $doc->uuid),
            route('documents.settings', $doc->uuid),
            route('files.index'),
            route('slash-commands.index'),
            route('profile.show'),
        ] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringNotContainsString('<x-', $html, "{$url} left a component tag uncompiled");
        }
    }

    /**
     * Spec §3: the top bar carries no platform-name readout. A page with no
     * document names ITSELF there — "Dashboard", "Documents", "Files" — and the
     * brand stays where a brand belongs, in the tab title and the rail's
     * colophon. Falling back to config('app.name') put "Dot.Doc" in Fraunces
     * across the dashboard: the exact row this redesign retired.
     */
    public function test_the_topbar_names_the_page_and_never_the_platform(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Fair Copy check');

        $expected = [
            route('dashboard') => 'Dashboard',
            route('documents.index') => 'Documents',
            route('files.index') => 'Files',
            route('slash-commands.index') => 'Slash commands',
            route('profile.show') => 'Profile',
            route('api-tokens.index') => 'API Tokens',
            // The editor is the exception, and deliberately so: it owns the
            // one INLINE, EDITABLE copy of the document's title (.doc-bar's
            // title field), and Task 1 shipped that title twice — readable in
            // the bar, editable under it. Spec §4's rebuild of the persistent
            // row resolved it in favour of the field you can actually type in,
            // so up here the editor names its section. Every other document
            // route still names the document, because on those pages nothing
            // else does. Covered end to end in ContextualToolbarTest.
            route('documents.edit', $doc->uuid) => 'Documents',
            route('documents.history', $doc->uuid) => 'Fair Copy check',
        ];

        foreach ($expected as $url => $name) {
            // ShellContext is a SCOPED binding: it memoises the route's
            // document for exactly one request. A test method issues several
            // requests against ONE container, so without this the first URL's
            // answer (null, for a page with no uuid) is still cached when the
            // document routes are reached, and every later assertion measures
            // the memo rather than the page. Forgetting them is what a new
            // request does.
            $this->app->forgetScopedInstances();

            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();

            $this->assertSame(
                1,
                preg_match('/<div class="topbar-title">(.*?)<\/div>/s', $html, $found),
                "{$url} renders no topbar title",
            );

            $title = trim(html_entity_decode(strip_tags($found[1])));

            $this->assertSame($name, $title, "{$url} must name itself in the top bar");
            $this->assertStringNotContainsString(
                config('app.name'),
                $title,
                "{$url} put the platform name in the top bar's title slot",
            );
        }
    }

    /**
     * The two night blocks in shell.css are not redundant (one is the explicit
     * `theme` cookie, one is the reader's system preference) but they MUST
     * declare the same thing — a token added to one and not the other ships a
     * shell that is half night. Kept honest here rather than by eye.
     */
    public function test_both_night_guards_declare_the_same_tokens(): void
    {
        $css = file_get_contents(resource_path('css/shell.css'));

        $this->assertSame(1, preg_match('/\nhtml\.dark \{(.*?)\n\}/s', $css, $explicit));
        $this->assertSame(1, preg_match('/@media \(prefers-color-scheme: dark\) \{\s*html:not\(\.light\) \{(.*?)\n    \}/s', $css, $system));

        $declarations = static function (string $block): array {
            preg_match_all('/(--[\w-]+)\s*:\s*([^;]+);/', $block, $pairs, PREG_SET_ORDER);

            return collect($pairs)->mapWithKeys(fn ($pair) => [$pair[1] => trim($pair[2])])->all();
        };

        $a = $declarations($explicit[1]);

        $this->assertNotEmpty($a, 'the html.dark block declared nothing — the pattern has drifted');
        $this->assertSame($a, $declarations($system[1]), 'the two night guards in shell.css have drifted apart');
    }

    public function test_theme_defaults_to_day(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $res = $this->actingAs($user)->get(route('dashboard'));
        $res->assertOk()->assertDontSee('class="dark"', false);
    }

    /**
     * Spec §6: below 900px neither panel is a column any more. Both become
     * full-screen overlays over the canvas, and each carries its own way out —
     * the SAME `data-shell-panel-toggle` control the top bar uses, so there is
     * no second source of truth about whether a panel is open.
     *
     * Both halves are measured, because either alone is a lie: markup with no
     * rule shows a "Close the panel" button beside a panel that is a column,
     * and a rule with no markup leaves a full-screen overlay with nothing to
     * press. There is no headless browser in this project (.ai/rules/views.md),
     * so the rule is read out of the stylesheet rather than off a rendering.
     */
    public function test_both_panels_become_overlays_with_a_way_out_below_900px(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="panel-overlay-head"'), 'both panels need an overlay close control');
        $this->assertStringContainsString('data-shell-panel-toggle="rail" aria-controls="shell-rail"', $html);
        $this->assertStringContainsString('data-shell-panel-toggle="dock" aria-controls="shell-dock"', $html);

        $shell = file_get_contents(resource_path('css/shell.css'));

        // Hidden by default: a way out of an overlay is a control for a state
        // the reader is not in while the panel is a column.
        $this->assertMatchesRegularExpression('/\n\.panel-overlay-head \{\s*display: none;\s*\}/', $shell);

        $overlay = $this->mediaBlock($shell, '@media (max-width: 900px)');

        $this->assertStringContainsString('.rail,', $overlay);
        $this->assertStringContainsString('.dock {', $overlay);
        $this->assertStringContainsString('position: fixed;', $overlay);
        $this->assertStringContainsString('inset: var(--topbar-h) 0 0 0;', $overlay);
        $this->assertStringContainsString(".rail[data-panel-user='open'],", $overlay);
        $this->assertStringContainsString(".dock[data-panel-user='open'] {", $overlay);
        $this->assertMatchesRegularExpression('/\.panel-overlay-head \{[^}]*display: flex;/', $overlay);

        // The dock is a fixed drawer over the canvas from 1180px DOWN, not from
        // 900px, so its way out has to start where the drawer does - otherwise
        // there is a band of widths where the only control that shuts the thing
        // covering the page is the one behind it. The rail is still a column
        // here and must NOT get the control.
        $drawer = $this->mediaBlock($shell, '@media (max-width: 1180px)');

        $this->assertMatchesRegularExpression('/\.dock \.panel-overlay-head \{[^}]*display: flex;/', $drawer);
        $this->assertStringNotContainsString('.rail .panel-overlay-head', $drawer);
    }

    /**
     * Spec §6's other half: at the same width the floating contextual toolbar
     * stops chasing the selection and sits on the bottom edge.
     *
     * The two `!important`s are the point of the assertion, not an accident:
     * resources/js/editor/ui/bubble.js writes `left`/`top` as INLINE styles,
     * which beat any author rule that is not marked important — and that JS is
     * explicitly out of scope for this phase (spec §7).
     */
    public function test_the_contextual_toolbar_is_bottom_anchored_below_900px(): void
    {
        $paper = file_get_contents(resource_path('css/paper.css'));
        $sheet = $this->mediaBlock($paper, '@media (max-width: 900px)');

        $this->assertStringContainsString('.dotdoc-bubble {', $sheet);
        $this->assertStringContainsString('position: fixed;', $sheet);
        $this->assertStringContainsString('left: 0 !important;', $sheet);
        $this->assertStringContainsString('top: auto !important;', $sheet);
        $this->assertStringContainsString('bottom: 0;', $sheet);
    }

    /** The body of a named at-rule block, matched brace by brace. */
    private function mediaBlock(string $css, string $query): string
    {
        $start = strpos($css, $query);
        $this->assertNotFalse($start, "{$query} is not in this stylesheet");

        $open = strpos($css, '{', $start);
        $depth = 0;

        for ($i = $open; $i < strlen($css); $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($css, $open + 1, $i - $open - 1);
                }
            }
        }

        $this->fail("{$query} is never closed");
    }
}
