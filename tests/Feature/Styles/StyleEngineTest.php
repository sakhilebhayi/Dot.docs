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

    public function test_css_covers_every_node_in_every_mode(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $engine = app(StyleEngine::class);
        foreach (DocumentStyle::all() as $style) {
            foreach (['canvas', 'print', 'share'] as $mode) {
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

    /**
     * Round 1 finding: CssBuilder interpolated token values into CSS with
     * no validation — a team style's tokens are attacker-controlled JSON.
     * A malicious fonts.body must render the report style's fallback font
     * and must never leak the payload into the generated CSS.
     */
    public function test_malicious_team_style_font_renders_fallback_and_never_leaks_the_payload(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->withPersonalTeam()->create();

        $reportTokens = DocumentStyle::where('key', 'report')->whereNull('team_id')->first()->tokens;
        $tokens = $reportTokens;
        $tokens['fonts']['body'] = "X'; } * { background:url(https://evil) } .y{";

        $malicious = DocumentStyle::create([
            'key' => 'legal',
            'name' => 'Malicious legal',
            'category' => 'legal',
            'team_id' => $user->currentTeam->id,
            'tokens' => $tokens,
        ]);

        $css = app(StyleEngine::class)->css($malicious, 'canvas');

        $this->assertStringContainsString($reportTokens['fonts']['body'], $css);
        $this->assertStringNotContainsString('evil', $css);
        $this->assertStringNotContainsString("X';", $css);
    }

    /**
     * Round 1 finding: heading weights were hardcoded per level (700/600/
     * 500); marketing needs the same bold weight at every level.
     */
    public function test_marketing_heading_weight_applies_uniformly_to_every_level(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $marketing = DocumentStyle::where('key', 'marketing')->whereNull('team_id')->first();
        $this->assertSame(700, $marketing->tokens['fonts']['headingWeight'] ?? null);

        $css = app(StyleEngine::class)->css($marketing, 'canvas');
        $this->assertStringContainsString('.paper h1{font-family:var(--doc-font-heading);font-size:var(--doc-size-h1);font-weight:700;', $css);
        $this->assertStringContainsString('.paper h2{font-family:var(--doc-font-heading);font-size:var(--doc-size-h2);font-weight:700;', $css);
        $this->assertStringContainsString('.paper h3{font-family:var(--doc-font-heading);font-size:var(--doc-size-h3);font-weight:700;', $css);

        // Unaffected styles keep the 700/600/500 default gradient.
        $report = DocumentStyle::where('key', 'report')->whereNull('team_id')->first();
        $reportCss = app(StyleEngine::class)->css($report, 'canvas');
        $this->assertStringContainsString('font-weight:600;', $reportCss);
        $this->assertStringContainsString('font-weight:500;', $reportCss);
    }

    /**
     * Round 1 finding: engineering/executive/technical/financial accents
     * were taken from the spec's NIGHT column onto white paper; they must
     * use the DAY-column equivalents instead.
     */
    public function test_day_column_accents_replace_night_column_leftovers(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $expected = [
            'engineering' => '#5d5e5a',
            'executive' => '#5d5e5a',
            'technical' => '#2f7043',
            'financial' => '#8a6d05',
        ];

        foreach ($expected as $key => $accent) {
            $style = DocumentStyle::where('key', $key)->whereNull('team_id')->first();
            $this->assertSame($accent, $style->tokens['colours']['accent'], "{$key} accent");
            $this->assertStringContainsString("--doc-accent:{$accent}", app(StyleEngine::class)->css($style, 'canvas'));
        }
    }
}
