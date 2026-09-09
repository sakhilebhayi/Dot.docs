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
            ->assertSee('Atkinson+Hyperlegible', false)
            ->assertSee('class="skip-link"', false)
            ->assertSee('aria-label="Navigator"', false)
            ->assertSee('aria-label="Intelligence and data"', false)
            ->assertSee('class="status-line"', false)
            ->assertSee('<title>R · Dot.Doc</title>', false);
    }

    public function test_dashboard_and_index_render_on_the_shell(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Dot.Doc')->assertDontSee('Dot.docs');
        $this->actingAs($user)->get(route('documents.index'))->assertOk()->assertSee('class="status-line"', false);
    }

    public function test_theme_cookie_switches_the_html_class_and_the_toggle_reports_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $night = $this->actingAs($user)->get(route('dashboard'));
        $night->assertOk()
            ->assertSee('<html lang="en" class="dark">', false)
            ->assertSee('aria-pressed="true"', false);

        $day = $this->actingAs($user)
            ->withUnencryptedCookie('theme', 'light')
            ->get(route('dashboard'));
        $day->assertOk()
            ->assertSee('<html lang="en" class="">', false)
            ->assertDontSee('<html lang="en" class="dark">', false)
            ->assertSee('aria-pressed="false"', false);
    }
}
