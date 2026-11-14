<?php

namespace Tests\Feature;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Index;
use App\Models\Document;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The shell carries the shared Dot UI (dot-ui): the platform switcher in the top bar and the compact filter on the
 * documents ledger. Docs has no header search, so none was added. Presentation changed; the ledger's search (its
 * Livewire property, DocumentSearch, scope and tenant rules) did not, and the switcher only ever shows what InfoDot
 * says the CURRENT company holds, linking through InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiHeaderTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ecosystem.infodot' => ['redeem_url' => self::REDEEM, 'platform' => 'docs', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => true],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** InfoDot's read endpoint, as it answers this platform about one company (docs is core: always entitled). */
    private static function body(string $uuid, array $held = ['docs', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['docs' => 'Dot.docs', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => $key === 'docs', 'access_mode' => 'everyone'];
        }

        return ['platform' => 'docs', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    private function write(User $author, ?Team $team, string $title, string $body = 'Plain body.'): Document
    {
        return app(DocumentStore::class)->create($author, $title, [
            'type' => 'doc',
            'attrs' => ['schema' => 1, 'style' => 'report', 'vars' => []],
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $body]]]],
        ], $team ? ['team_id' => $team->id] : []);
    }

    /** The markup of the switcher only, so the assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the top bar carries the switcher');

        return $m[0];
    }

    // ── The switcher ────────────────────────────────────────────────────────

    public function test_a_member_sees_the_switcher_with_what_their_company_holds_and_docs_marked_as_here(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $owner = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($owner)->get(route('documents.index'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="docs"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel);

        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.docs', $here[0]);

        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel, 'a platform the company does not hold is not a tile');
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_the_switcher_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('documents.index'))->assertOk();

        Http::assertSent(fn (OutgoingRequest $request) => str_contains($request->url(), '/companies/'.self::ACME.'/platforms')
            && $request->hasHeader('Authorization', 'Bearer a-test-secret')
            && $request->hasHeader('X-Dot-Platform', 'docs'));
        Http::assertNotSent(fn (OutgoingRequest $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['docs', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['docs', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('documents.index'))->getContent());
        $globex = $this->switcherHtml($this->actingAs($this->memberOf(self::GLOBEX, 'Globex Ltd'))->get(route('documents.index'))->getContent());

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_when_infodot_is_down_the_switcher_says_so_and_the_page_still_works(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('documents.index'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_the_page(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('documents.index'))->assertOk()->getContent();

        $this->assertStringContainsString('data-state="degraded"', $html);
    }

    public function test_a_personal_workspace_keeps_working_gets_the_personal_state_and_infodot_is_not_asked(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel, 'the way to InfoDot is kept');
        Http::assertNothingSent();
    }

    public function test_the_editor_gets_no_switcher_and_the_dock_toggle_is_still_there(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = $this->write($user, null, 'Quiet page');

        $html = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-dsw', $html, 'the writing surface gets no extra chrome');
        $this->assertStringNotContainsString('dot-ui/js/dot-switcher.js', $html, 'the editor does not load the switcher script');
        $this->assertStringContainsString('data-shell-panel-toggle="dock"', $html);
        Http::assertNothingSent();
    }

    public function test_the_dashboard_with_its_collapsed_dock_carries_the_switcher(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dsw', $html);
        $this->assertStringContainsString('data-shell-panel-toggle="dock"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
    }

    public function test_a_guest_sees_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-dsw', false);
    }

    // ── The ledger filter: the logic and the permissions are unchanged ──────

    public function test_the_ledger_search_is_the_shared_compact_filter_with_the_same_property_and_debounce(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('documents.index'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<input[^>]*\bid="doc-search"[^>]*>/', $html, $input));
        $this->assertStringContainsString('class="dsr-filter"', $input[0]);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="search"', $input[0]);
        $this->assertStringContainsString('type="search"', $input[0]);
        $this->assertStringContainsString('placeholder="A word you remember writing"', $input[0]);
        $this->assertStringContainsString('for="doc-search"', $html, 'the visible label still points at it');
        $this->assertSame(0, substr_count($html, 'role="search"'), 'docs has no header search and none was added');
    }

    public function test_ledger_search_finds_by_title_and_text_and_never_shows_another_business(): void
    {
        $this->fakeInfoDot([]);
        $acme = $this->memberOf(self::ACME, 'Acme Ltd');
        $globex = $this->memberOf(self::GLOBEX, 'Globex Ltd');
        $acmeTeam = $acme->currentTeam;
        $globexTeam = $globex->currentTeam;

        $this->write($acme, $acmeTeam, 'Borrowdale rota', 'Zebra crossing schedule.');
        $this->write($globex, $globexTeam, 'Zeta holdings plan', 'Zebra herd budget.');

        Livewire::actingAs($acme)->test(Index::class)
            ->set('search', 'Borrowdale')->assertSee('Borrowdale rota')->assertDontSee('Zeta holdings plan')
            ->set('search', 'Zebra')->assertSee('Borrowdale rota')->assertDontSee('Zeta holdings plan')
            ->set('search', 'Zeta')->assertDontSee('Zeta holdings plan');

        Livewire::actingAs($globex)->test(Index::class)
            ->set('search', 'Zebra')->assertSee('Zeta holdings plan')->assertDontSee('Borrowdale rota');
    }

    // ── Self-hosted, nothing inline, nothing external ───────────────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('documents.index'))->assertOk()->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-search.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $this->switcherHtml($html), $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('documents.index'))->getContent();

        $this->assertSame(1, preg_match('/<input[^>]*\bid="doc-search"[^>]*>/', $html, $filter));
        foreach ([$filter[0], $this->switcherHtml($html)] as $fragment) {
            $this->assertStringNotContainsString(' style=', $fragment);
            $this->assertStringNotContainsString('<style', $fragment);
            $this->assertStringNotContainsString('<script', $fragment);
            $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $fragment, 'no inline event handlers');
        }
    }
}
