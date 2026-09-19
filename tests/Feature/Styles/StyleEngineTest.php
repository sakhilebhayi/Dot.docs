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

    /**
     * Design spec §3: Focus mode hides "all chrome (rail/dock/topbar)", not
     * just the page-break decorations - `.editor-main.dotdoc-mode-focus`
     * cannot reach any of the three with a descendant selector, since they
     * are its own ancestor's siblings inside `.shell` (layouts/app.blade.
     * php), so the fix reaches them via `:has()` instead. Only `canvas`
     * mode carries a `.shell` at all (the editor's own chrome) - `share`
     * (the published page) and `print` (export/PDF) render no topbar/rail/
     * dock, so the rule has nothing to do there and paginationRule() only
     * ever emits it for `canvas` regardless.
     */
    public function test_focus_mode_hides_the_topbar_rail_and_dock(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $engine = app(StyleEngine::class);
        $style = DocumentStyle::where('is_system', true)->first();

        $canvasCss = $engine->css($style, 'canvas');
        foreach (['.topbar', '.rail', '.dock'] as $chrome) {
            $this->assertStringContainsString(
                ".shell:has(.editor-main.dotdoc-mode-focus) {$chrome}",
                $canvasCss,
                "canvas CSS is missing the Focus-mode hide rule for {$chrome}",
            );
        }
        $this->assertStringContainsString(
            '.shell:has(.editor-main.dotdoc-mode-focus){grid-template-rows:0 auto minmax(0,1fr)}',
            $canvasCss,
            'canvas CSS is missing the Focus-mode topbar row collapse - .topbar{display:none} alone leaves its fixed-height grid row reserved',
        );

        foreach (['print', 'share'] as $mode) {
            $css = $engine->css($style, $mode);
            $this->assertStringNotContainsString('dotdoc-mode-focus', $css, "{$mode} CSS should carry no pagination view-mode rules at all");
        }
    }

    /**
     * A mid-table page-boundary widget is `position:absolute` inside
     * `.paper` (.ai/rules/editor.md's "mid-table page-boundary widget"
     * rule), so its `left`/`right` are resolved against `.paper`'s
     * containing block, which - per CSS 2.1 §10.3.7 - is the PADDING box
     * of the nearest positioned ancestor. `.paper` carries the page
     * margins as its own `padding`, so `left:0;right:0` would span that
     * padding too, bleeding the widget's header/footer band across the
     * full page instead of stopping at the content column every normal
     * (non-table) boundary's band already respects. This asserts the
     * mid-table rule uses the exact same margin values `.dotdoc-page-band`
     * already keys its own padding off, rather than hardcoded zeros.
     */
    public function test_mid_table_page_boundary_aligns_with_the_page_margins_not_the_full_paper_width(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $engine = app(StyleEngine::class);
        $style = DocumentStyle::where('is_system', true)->first();

        $canvasCss = $engine->css($style, 'canvas');

        $this->assertStringNotContainsString(
            '.dotdoc-page-boundary.dotdoc-page-boundary-in-table{position:absolute;left:0;right:0}',
            $canvasCss,
            'a mid-table split boundary must not span the full .paper width - see this test\'s own docblock',
        );

        $this->assertMatchesRegularExpression(
            '/\.dotdoc-page-band\{background:#fff;padding:\.4em ([^ ]+) \.4em ([^;]+);/',
            $canvasCss,
        );
        preg_match('/\.dotdoc-page-band\{background:#fff;padding:\.4em ([^ ]+) \.4em ([^;]+);/', $canvasCss, $bandMatch);
        [, $bandRight, $bandLeft] = $bandMatch;

        $this->assertStringContainsString(
            ".dotdoc-page-boundary.dotdoc-page-boundary-in-table{position:absolute;left:{$bandLeft};right:{$bandRight}}",
            $canvasCss,
            'the mid-table boundary\'s left/right insets must match the same page-margin values .dotdoc-page-band already uses',
        );
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
