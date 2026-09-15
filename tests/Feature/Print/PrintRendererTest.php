<?php

namespace Tests\Feature\Print;

use App\Documents\DocumentStore;
use App\Models\User;
use App\Print\PageSetup;
use App\Print\PrintRenderer;
use App\Styles\StyleEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Database\Seeders\DocumentStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_setup_merges_document_over_style(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, ['page_setup' => ['orientation' => 'landscape', 'footer' => 'Confidential · {{ page }}']]);
        $setup = PageSetup::fromDocument($doc, app(StyleEngine::class)->resolve($doc));
        $this->assertSame('A4', $setup->size);
        $this->assertSame('landscape', $setup->orientation);
        $this->assertSame('Confidential · {{ page }}', $setup->footer);
    }

    public function test_print_html_has_page_rule_header_footer_and_variables(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, ['variables' => ['period' => 'August 2026'], 'page_setup' => ['header' => '{{ title }} — {{ period }}']]);
        $html = app(PrintRenderer::class)->html($doc);
        $this->assertStringContainsString('@page', $html);
        $this->assertStringContainsString('Monthly report — August 2026', $html);
        $this->assertStringContainsString('class="print-footer"', $html);
        $this->assertStringContainsString('PAGE_NUM', $html);
    }

    public function test_pdf_is_produced(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $pdf = app(PrintRenderer::class)->pdf($doc);
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_export_route_uses_print_renderer_and_logs(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R');
        $this->actingAs($user)->get(route('documents.export', [$doc->uuid, 'pdf']))
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_page_text_offsets_are_derived_from_margins(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R', null, [
            'page_setup' => [
                'margins' => ['top' => '10mm', 'right' => '10mm', 'bottom' => '15mm', 'left' => '5mm'],
                'header' => 'Page {{ page }}',
                'footer' => 'of {{ pages }}',
            ],
        ]);

        $html = app(PrintRenderer::class)->html($doc);

        // Per the finding: 1mm = 2.8346pt. x is the left margin in points;
        // header/footer y sit inside their margin band, not the old
        // hardcoded (40, 24) / (40, height-30).
        $leftPt = round(5 * 2.8346, 2);
        $topPt = round(10 * 2.8346, 2);
        $bottomPt = round(15 * 2.8346, 2);

        $this->assertStringContainsString("page_text({$leftPt},", $html);
        $this->assertStringNotContainsString('page_text(40,', $html);
        $this->assertStringNotContainsString('page_text(40, 24,', $html);

        // Header y must be within the top margin band (between 0 and topPt).
        if (preg_match('/page_text\('.preg_quote((string) $leftPt, '/').', ([\d.]+),/', $html, $m)) {
            $headerY = (float) $m[1];
            $this->assertGreaterThan(0.0, $headerY);
            $this->assertLessThanOrEqual($topPt, $headerY);
        } else {
            $this->fail('Expected a header page_text() call using the left margin in points.');
        }

        // Footer y must be offset from the bottom by less than the bottom margin band.
        if (preg_match('/get_height\(\) - ([\d.]+)/', $html, $m)) {
            $footerOffset = (float) $m[1];
            $this->assertGreaterThan(0.0, $footerOffset);
            $this->assertLessThanOrEqual($bottomPt, $footerOffset);
        } else {
            $this->fail('Expected a footer page_text() call offset from get_height() by the bottom margin.');
        }
    }

    public function test_php_eval_is_disabled_when_no_page_number_template(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R', null, [
            'page_setup' => ['header' => 'Static header', 'footer' => 'Static footer'],
        ]);

        // The dompdf facade always resolves a fresh instance per static call
        // (see Barryvdh\DomPDF\Facade\Pdf::__callStatic), so the only way to
        // observe what PrintRenderer passed to setOption() is to swap in a
        // mock via the facade's own shouldReceive() (Facade::swap() rebinds
        // the container as a shared instance, which __callStatic then
        // returns on every call within this test).
        Pdf::shouldReceive('setOption')->once()->with(['isPhpEnabled' => false])->andReturnSelf();
        Pdf::shouldReceive('loadHTML')->once()->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('output')->once()->andReturn('%PDF-fake');

        $pdf = app(PrintRenderer::class)->pdf($doc);

        $this->assertSame('%PDF-fake', $pdf);
    }

    public function test_php_eval_is_enabled_when_page_number_template_present(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'R', null, [
            'page_setup' => ['footer' => 'Page {{ page }} of {{ pages }}'],
        ]);

        Pdf::shouldReceive('setOption')->once()->with(['isPhpEnabled' => true])->andReturnSelf();
        Pdf::shouldReceive('loadHTML')->once()->andReturnSelf();
        Pdf::shouldReceive('setPaper')->once()->andReturnSelf();
        Pdf::shouldReceive('output')->once()->andReturn('%PDF-fake');

        $pdf = app(PrintRenderer::class)->pdf($doc);

        $this->assertSame('%PDF-fake', $pdf);
    }

    public function test_non_scalar_variable_values_are_coerced_to_empty_string(): void
    {
        $this->seed(DocumentStyleSeeder::class);
        $user = User::factory()->create();
        $doc = app(DocumentStore::class)->create($user, 'Monthly report', null, [
            'variables' => ['period' => ['August', '2026']],
            'page_setup' => ['header' => 'Period: {{ period }}'],
        ]);

        $html = app(PrintRenderer::class)->html($doc);

        $this->assertStringContainsString('Period: <', $html);
        $this->assertStringNotContainsString('Array', $html);
    }
}
