<?php

namespace Tests\Feature\Print;

use App\Print\HeaderFooterBands;
use Tests\TestCase;

class HeaderFooterBandsTest extends TestCase
{
    private function bands(): HeaderFooterBands
    {
        return new HeaderFooterBands;
    }

    public function test_plain_text_with_no_tokens_is_one_text_segment(): void
    {
        $segments = $this->bands()->segments('Confidential', []);

        $this->assertSame([['type' => 'text', 'value' => 'Confidential']], $segments);
    }

    public function test_empty_template_produces_no_segments(): void
    {
        $this->assertSame([], $this->bands()->segments('', ['title' => 'x']));
    }

    public function test_variable_tokens_substitute_and_coalesce_with_surrounding_text(): void
    {
        $segments = $this->bands()->segments('{{ title }} — {{ team }}', ['title' => 'Monthly report', 'team' => 'Acme']);

        $this->assertSame([['type' => 'text', 'value' => 'Monthly report — Acme']], $segments);
    }

    public function test_page_and_pages_become_field_segments(): void
    {
        $segments = $this->bands()->segments('Page {{ page }} of {{ pages }}', []);

        $this->assertSame([
            ['type' => 'text', 'value' => 'Page '],
            ['type' => 'field', 'value' => 'PAGE'],
            ['type' => 'text', 'value' => ' of '],
            ['type' => 'field', 'value' => 'NUMPAGES'],
        ], $segments);
    }

    public function test_mixed_variables_and_page_fields(): void
    {
        $segments = $this->bands()->segments('{{ title }} · {{ page }}/{{ pages }}', ['title' => 'Report']);

        $this->assertSame([
            ['type' => 'text', 'value' => 'Report · '],
            ['type' => 'field', 'value' => 'PAGE'],
            ['type' => 'text', 'value' => '/'],
            ['type' => 'field', 'value' => 'NUMPAGES'],
        ], $segments);
    }

    public function test_unknown_variable_is_blank_not_left_as_a_token(): void
    {
        $segments = $this->bands()->segments('{{ nope }}', []);

        $this->assertSame([], $segments);
    }

    public function test_non_scalar_variable_value_coerces_to_empty_string(): void
    {
        $segments = $this->bands()->segments('Period: {{ period }}', ['period' => ['August', '2026']]);

        $this->assertSame([['type' => 'text', 'value' => 'Period: ']], $segments);
    }

    public function test_only_page_and_pages_are_ever_recognised_as_fields(): void
    {
        // A key that merely CONTAINS "page" must not be treated as a field —
        // the whitelist is exact-match, not a substring test.
        $segments = $this->bands()->segments('{{ pageTitle }}', ['pageTitle' => 'Q3']);

        $this->assertSame([['type' => 'text', 'value' => 'Q3']], $segments);
    }

    public function test_raw_text_is_not_html_escaped_here(): void
    {
        // Escaping is the CALLER's job (HTML-escape for PrintRenderer's html
        // output, textContent for the live JS view) — segments() must hand
        // back the literal substituted text, unescaped, or a caller that
        // needs the raw value (dompdf's page_text canvas draw) would get a
        // double-escaped string instead.
        $segments = $this->bands()->segments('{{ title }}', ['title' => 'A & B <em>'])[0];

        $this->assertSame('A & B <em>', $segments['value']);
    }

    public function test_a_substituted_variable_value_containing_literal_page_syntax_is_never_treated_as_a_field(): void
    {
        // Fields are classified from the TEMPLATE's own {{ }} tokens BEFORE
        // substitution. A variable's value that literally contains "{{ page }}"
        // is never re-scanned and misdetected as a field — it renders as
        // literal text. The old PrintRenderer::band() had the opposite bug:
        // it substituted first, then re-scanned the result, so a variable
        // containing "{{ page }}" would be silently converted to a page number.
        // This is the injection/corruption surface the extraction closes.
        $segments = $this->bands()->segments('{{ title }}', ['title' => 'A {{ page }} B']);

        $this->assertSame([['type' => 'text', 'value' => 'A {{ page }} B']], $segments);
    }
}
