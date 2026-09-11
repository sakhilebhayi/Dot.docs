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
}
