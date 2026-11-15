<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R-52: Roboto is the one face of the chrome, served from this origin; the shared header ships no inline style or script and
 * its phone menu markup is the contract dot-nav.js drives.
 */
class R52TypographyTest extends TestCase
{
    use RefreshDatabase;

    public function test_roboto_is_declared_and_its_files_are_served_from_this_site(): void
    {
        $face = file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $face);
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-wght-normal.woff2'));
        $this->assertFileExists(public_path('dot-ui/fonts/roboto/roboto-latin-ext-wght-normal.woff2'));

        $shell = file_get_contents(resource_path('css/shell.css'));
        foreach (['--font-display', '--font-chrome', '--font-sheet', '--font-sheet-head'] as $token) {
            $this->assertMatchesRegularExpression('/'.preg_quote($token, '/').': var\(--dot-font-sans/', $shell, "{$token} maps to Roboto");
        }
    }

    public function test_no_view_or_stylesheet_names_another_chrome_face_or_an_external_font_host(): void
    {
        $files = array_merge(
            glob(resource_path('views/*.blade.php')) ?: [],
            glob(resource_path('views/*/*.blade.php')) ?: [],
            glob(resource_path('views/*/*/*.blade.php')) ?: [],
            glob(resource_path('css/*.css')) ?: [],
        );

        foreach ($files as $file) {
            $text = (string) file_get_contents($file);
            $this->assertStringNotContainsString('fonts.googleapis.com', $text, basename($file));
            $this->assertStringNotContainsString('fonts.gstatic.com', $text, basename($file));
            $this->assertDoesNotMatchRegularExpression("/font-family:[^;]*'(Fraunces|Work Sans|IBM Plex Mono|Atkinson Hyperlegible)/", $text, basename($file));
            $this->assertDoesNotMatchRegularExpression("/--font-[a-z-]+:\s*'(Fraunces|Work Sans|IBM Plex Mono|Source Serif 4|Source Sans 3|Atkinson)/", $text, basename($file));
        }
    }

    public function test_every_layout_loads_roboto_from_this_origin(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        foreach ([route('dashboard'), route('documents.index')] as $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('class="dot-roboto', $html);
            $this->assertStringContainsString(asset('dot-ui/css/dot-roboto.css'), $html);
            $this->assertStringContainsString(asset('dot-ui/css/dot-type.css'), $html);
            $this->assertStringNotContainsString('dot-fonts.css', $html);
            $this->assertStringNotContainsString('googleapis', $html);
        }

    }

    public function test_the_guest_layout_is_roboto_too(): void
    {
        foreach (['login', 'password.request'] as $guest) {
            $html = $this->get(route($guest))->assertOk()->getContent();
            $this->assertStringContainsString('dot-roboto', $html, $guest);
            $this->assertStringNotContainsString('googleapis', $html, $guest);
        }
    }

    public function test_the_header_has_no_inline_style_or_script_and_the_phone_menu_markup(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<header class="dh [^"]*shell-header.*?<\/header>/s', $html, $header));
        $this->assertStringNotContainsString(' style=', $header[0]);
        $this->assertStringNotContainsString('<script', $header[0]);
        $this->assertStringNotContainsString('<style', $header[0]);

        // The phone menu is the rail (dot-ui adopt mode): the button points at it, the rail carries a close button.
        $this->assertStringContainsString('data-dh-menu-button', $header[0]);
        $this->assertStringContainsString('aria-controls="shell-rail"', $header[0]);
        $this->assertStringContainsString('dh--adopt', $header[0]);
        $this->assertStringContainsString('dh--nonav', $header[0]);
        $this->assertSame(1, preg_match('/<aside id="shell-rail".*?<\/aside>/s', $html, $rail));
        $this->assertStringContainsString('data-dh-close', $rail[0]);
        $this->assertStringContainsString('Assistant and document tools', $rail[0], 'the dock stays reachable on a phone');
    }

    public function test_the_bell_is_dot_uis_panel_bell_with_a_labelled_panel(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('dh-bell--panel', $html);
        $this->assertStringContainsString('id="notifications-panel"', $html);
        $this->assertStringContainsString('Nothing has come in yet.', $html);
    }

    public function test_the_rail_has_no_duplicate_workspace_labels_and_no_account_block(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, preg_match('/<aside id="shell-rail".*?<\/aside>/s', $html, $rail));
        $this->assertSame(0, substr_count($rail[0], '>Workspace<'));
        $this->assertStringContainsString('>Library<', $rail[0]);
        $this->assertStringNotContainsString('Log out', $rail[0], 'sign out lives in the account menu');
    }
}
