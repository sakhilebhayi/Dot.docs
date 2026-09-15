<?php

namespace App\Print;

/**
 * Splits a header/footer template into an ordered list of segments — the
 * ONE place `{{ page }}`/`{{ pages }}` are recognised as live page-number
 * fields; every other `{{ key }}` is always substituted-then-literal text,
 * never a field. That whitelist is the actual security boundary Task 10's
 * field-injection fix depends on (see PrintRenderer::phpStringLiteral()),
 * so this class must stay the ONLY place the distinction is made — both
 * PrintRenderer (server PDF) and Editor::outline() (live view) call this,
 * never re-parsing `{{ }}` tokens themselves.
 *
 * segments() returns RAW, unescaped text in every 'text' segment on
 * purpose: PrintRenderer's dompdf page_text() canvas draw needs the raw
 * string, its HTML band needs htmlspecialchars(), and the live JS view
 * renders every segment via `textContent` (inherently escape-safe). Each
 * consumer picks the escaping appropriate to where the text lands —
 * escaping once, here, for only one of those three destinations would be
 * wrong for the other two.
 */
class HeaderFooterBands
{
    /**
     * @param  array<string,mixed>  $vars
     * @return list<array{type: 'text'|'field', value: string}>
     */
    public function segments(string $template, array $vars): array
    {
        if ($template === '') {
            return [];
        }

        $pieces = preg_split('/(\{\{\s*\w+\s*\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];

        foreach ($pieces as $piece) {
            if (preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $piece, $m)) {
                $key = $m[1];

                if ($key === 'page') {
                    $segments[] = ['type' => 'field', 'value' => 'PAGE'];

                    continue;
                }

                if ($key === 'pages') {
                    $segments[] = ['type' => 'field', 'value' => 'NUMPAGES'];

                    continue;
                }

                $value = $vars[$key] ?? '';
                $text = is_scalar($value) ? (string) $value : '';
            } else {
                $text = $piece;
            }

            if ($text === '') {
                continue;
            }

            $last = count($segments) - 1;
            if ($last >= 0 && $segments[$last]['type'] === 'text') {
                $segments[$last]['value'] .= $text;
            } else {
                $segments[] = ['type' => 'text', 'value' => $text];
            }
        }

        return $segments;
    }
}
