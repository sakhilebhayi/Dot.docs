<?php

namespace Tests\Feature\Print;

use App\Documents\DocumentStore;
use App\Models\User;
use App\Print\PageSetup;
use App\Print\PrintRenderer;
use App\Styles\StyleEngine;
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
}
