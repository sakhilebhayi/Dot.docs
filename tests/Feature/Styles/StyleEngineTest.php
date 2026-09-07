<?php

namespace Tests\Feature\Styles;

use App\Documents\DocumentStore;
use App\Livewire\Documents\Editor;
use App\Models\DocumentStyle;
use App\Models\User;
use App\Styles\StyleEngine;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;

class StyleEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_installs_fourteen_complete_system_styles(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $this->assertSame(14, DocumentStyle::where('is_system', true)->count());
        foreach (DocumentStyle::all() as $style) {
            foreach (['fonts', 'sizes', 'leading', 'spacing', 'colours', 'numbering', 'headingCase', 'table', 'caption', 'page', 'align'] as $key) {
                $this->assertArrayHasKey($key, $style->tokens, "{$style->key} missing {$key}");
            }
        }
    }

    public function test_css_covers_every_node_in_both_modes(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $engine = app(StyleEngine::class);
        foreach (DocumentStyle::all() as $style) {
            foreach (['canvas', 'print'] as $mode) {
                $css = $engine->css($style, $mode);
                foreach (['.paper h1', '.paper h2', '.paper p', '.paper .doc-table', '.paper figure', '.paper figcaption', '.paper .toc', '.paper .callout', '.paper .page-break', '.paper .num'] as $sel) {
                    $this->assertStringContainsString($sel, $css, "{$style->key}/{$mode} lacks {$sel}");
                }
                $this->assertStringContainsString($style->tokens['fonts']['body'], $css);
            }
            $this->assertStringContainsString('@page', $engine->css($style, 'print'));
        }
    }

    public function test_team_style_overrides_system_and_editor_can_switch(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $this->assertSame('report', app(StyleEngine::class)->resolve($doc)->key);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->call('setStyle', 'legal');
        $this->assertSame('legal', $doc->fresh()->style_key);

        $custom = DocumentStyle::create(['key' => 'legal', 'name' => 'Our legal', 'category' => 'legal', 'team_id' => $user->currentTeam->id, 'tokens' => DocumentStyle::where('key', 'legal')->whereNull('team_id')->first()->tokens]);
        $this->assertSame($custom->id, app(StyleEngine::class)->resolve($doc->fresh())->id);

        Livewire::actingAs($user)->test(Editor::class, ['uuid' => $doc->uuid])->call('setStyle', 'nonsense')->assertHasErrors('style');
    }

    public function test_style_numbering_rules_drive_outline_heading_numbers(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();
        Auth::login($user);

        $store = app(DocumentStore::class);

        $json = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [['type' => 'text', 'text' => 'Intro']]],
            ],
        ];

        $executive = $store->create($user, 'Executive doc', $json, ['style_key' => 'executive']);
        $this->assertStringNotContainsString('<span class="num">', $executive->content);

        $report = $store->create($user, 'Report doc', $json, ['style_key' => 'report']);
        $this->assertStringContainsString('<span class="num">1</span>', $report->content);
    }
}
