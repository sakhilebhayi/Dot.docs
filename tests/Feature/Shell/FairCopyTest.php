<?php

namespace Tests\Feature\Shell;

use App\Documents\DocumentStore;
use App\Models\User;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FairCopyTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_retired_chrome_survives_on_any_touched_page(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'Fair Copy check');

        foreach ([
            route('dashboard'),
            route('documents.index'),
            route('documents.edit', $doc->uuid),
            route('documents.history', $doc->uuid),
            route('documents.share', $doc->uuid),
            route('documents.settings', $doc->uuid),
        ] as $url) {
            $this->actingAs($user)->get($url)
                ->assertOk()
                ->assertDontSee('<x-shell.lamp', false)
                ->assertDontSee('class="status-line"', false)
                ->assertDontSee('class="lamp"', false)
                ->assertDontSee('Inter', false);
        }
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

    public function test_theme_defaults_to_day(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $res = $this->actingAs($user)->get(route('dashboard'));
        $res->assertOk()->assertDontSee('class="dark"', false);
    }
}
