<?php

namespace Tests\Feature\Shell;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_uses_vite_tokens_and_landmarks_not_cdns(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $res = $this->actingAs($user)->get(route('documents.edit', $doc->uuid));
        $res->assertOk()
            ->assertDontSee('cdn.tailwindcss.com', false)
            ->assertDontSee('unpkg.com/alpinejs', false)
            ->assertDontSee('Inter', false)
            ->assertSee('Fraunces', false)
            ->assertSee('Work+Sans', false)
            ->assertDontSee('Atkinson+Hyperlegible', false)
            ->assertSee('class="skip-link"', false)
            ->assertSee('aria-label="Navigator"', false)
            ->assertSee('aria-label="Intelligence and data"', false)
            ->assertSee('class="topbar"', false)
            ->assertDontSee('class="status-line"', false)
            ->assertSee('<title>R · Dot.Doc</title>', false);
    }

    public function test_dashboard_and_index_render_on_the_shell(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Dot.Doc')->assertDontSee('Dot.docs');
        $this->actingAs($user)->get(route('documents.index'))->assertOk()->assertSee('class="topbar"', false);
    }

    /**
     * Jetstream's pages hand their heading to the layout through the `header`
     * slot. The shell dropped that slot in the first pass, so profile, teams
     * and API tokens rendered inside the shell with no page heading at all.
     */
    public function test_jetstream_pages_render_one_heading_on_the_shell_tokens(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        foreach (['profile.show', 'api-tokens.index', 'teams.create'] as $route) {
            $res = $this->actingAs($user)->get(route($route));
            $res->assertOk();

            $html = $res->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), "{$route} must render exactly one <h1>");
            $this->assertStringNotContainsString('text-gray-', $html, "{$route} must not carry Jetstream greys");
            $this->assertStringNotContainsString('rounded-md', $html, "{$route} must not carry Jetstream radii");
            $this->assertStringNotContainsString('shadow-', $html, "{$route} must not carry Jetstream shadows");
        }

        $team = $user->currentTeam;
        $res = $this->actingAs($user)->get(route('teams.show', $team));
        $res->assertOk();
        $this->assertSame(1, substr_count($res->getContent(), '<h1'));
        $this->assertStringNotContainsString('text-gray-', $res->getContent());
    }

    /**
     * A dialog that does not trap focus lets Tab walk straight out of it into
     * the page behind - including, on the profile page, "Delete account".
     * x-trap is Alpine's Focus plugin, which Livewire 3 bundles.
     */
    public function test_dialogs_trap_focus_and_are_labelled(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get(route('profile.show'))->assertOk()->getContent();

        $this->assertStringContainsString('x-trap.inert.noscroll="show"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertMatchesRegularExpression('/aria-labelledby="[0-9a-f]{32}-title"/', $html);
    }

    /**
     * The top bar's status word is the page's to set. It is only wired to the
     * `shell:save-state` event on a page that owns a document; elsewhere the
     * server-rendered word stays put.
     */
    public function test_only_the_editor_owns_the_topbar_save_word(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');

        $this->actingAs($user)->get(route('documents.edit', $doc->uuid))
            ->assertOk()
            ->assertSee('data-shell-save-owner', false);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('data-shell-save-owner', false);
    }

    /**
     * Fair Copy inverts Task 9's default: DAY is what a writing tool opens on,
     * so no cookie means no class at all and the `prefers-color-scheme` guard
     * in shell.css decides. An explicit day choice is stamped html.light so it
     * beats that guard; night is still html.dark.
     */
    public function test_theme_cookie_switches_the_html_class_and_the_toggle_reports_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $unset = $this->actingAs($user)->get(route('dashboard'));
        $unset->assertOk()
            ->assertSee('<html lang="en" class="">', false)
            ->assertSee('aria-pressed="false"', false);

        $night = $this->actingAs($user)
            ->withUnencryptedCookie('theme', 'dark')
            ->get(route('dashboard'));
        $night->assertOk()
            ->assertSee('<html lang="en" class="dark">', false)
            ->assertSee('aria-pressed="true"', false);

        $day = $this->actingAs($user)
            ->withUnencryptedCookie('theme', 'light')
            ->get(route('dashboard'));
        $day->assertOk()
            ->assertSee('<html lang="en" class="light">', false)
            ->assertDontSee('<html lang="en" class="dark">', false)
            ->assertSee('aria-pressed="false"', false);
    }

    /**
     * The panels are the redesign's structural claim: the editor opens with the
     * canvas at full width and both panels shut, every listing page opens with
     * the rail as its navigation. Both states are SERVER-rendered, so neither
     * flashes open before resources/js/shell.js runs.
     */
    public function test_panels_default_collapsed_on_the_editor_and_open_elsewhere(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Panels');

        $editor = $this->actingAs($user)->get(route('documents.edit', $doc->uuid))->getContent();
        $this->assertStringContainsString('id="shell-rail" class="rail" data-panel-state="collapsed"', $editor);
        $this->assertStringContainsString('id="shell-dock" class="dock" data-panel-state="collapsed"', $editor);

        $index = $this->actingAs($user)->get(route('documents.index'))->getContent();
        $this->assertStringContainsString('id="shell-rail" class="rail" data-panel-state="expanded"', $index);
    }
}
