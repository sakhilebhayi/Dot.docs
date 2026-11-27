<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $title }}</title>
    <style>
        {!! $css !!}
        @page {
            size: {{ $setup->size }} {{ $setup->orientation }};
            margin: {{ $setup->margins['top'] }} {{ $setup->margins['right'] }} {{ $setup->margins['bottom'] }} {{ $setup->margins['left'] }};
        }
        .print-header {
            position: fixed;
            top: -{{ $setup->margins['top'] }};
            left: 0;
            right: 0;
            height: {{ $setup->margins['top'] }};
            display: flex;
            align-items: center;
            font-size: 9pt;
            color: var(--doc-muted, #5d5e5a);
        }
        .print-footer {
            position: fixed;
            bottom: -{{ $setup->margins['bottom'] }};
            left: 0;
            right: 0;
            height: {{ $setup->margins['bottom'] }};
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9pt;
            color: var(--doc-muted, #5d5e5a);
        }
        /* Company letterhead and disclaimer (R2-3c). A table, not flex: the PDF renderer lays tables out reliably. */
        .brand-letterhead { width: 100%; border-collapse: collapse; margin: 0 0 8mm; border-bottom: 0.5pt solid #d5d1c7; }
        .brand-letterhead td { vertical-align: middle; padding: 0 0 4mm; }
        .brand-letterhead .logo { width: 1%; padding-right: 5mm; }
        .brand-letterhead img { max-height: 16mm; max-width: 50mm; }
        .brand-letterhead .name { margin: 0; font-size: 14pt; font-weight: 600; }
        .brand-letterhead .contact { margin: 1mm 0 0; font-size: 8.5pt; color: #5d5e5a; }
        .brand-disclaimer { margin-top: 10mm; padding-top: 3mm; border-top: 0.5pt solid #d5d1c7; font-size: 8pt; font-style: italic; color: #5d5e5a; }
    </style>
</head>
<body>
    <div class="print-header">{!! $headerHtml !!}</div>

    <div class="paper">
        @if ($brand)
            <table class="brand-letterhead" role="presentation">
                <tr>
                    @if ($brand['logo'])
                        <td class="logo"><img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}"></td>
                    @endif
                    <td>
                        <p class="name" style="color: {{ $brand['colour'] }}">{{ $brand['name'] }}</p>
                        @if ($brand['contact'] !== '')
                            <p class="contact">{{ $brand['contact'] }}</p>
                        @endif
                    </td>
                </tr>
            </table>
        @endif

        {!! $body !!}

        @if ($brand && $brand['disclaimer'] !== '')
            <p class="brand-disclaimer">{{ $brand['disclaimer'] }}</p>
        @endif
    </div>

    <div class="print-footer">{!! $footerHtml !!}</div>

    @if($headerPageTextLiteral || $footerPageTextLiteral)
    <script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont('Helvetica');
        @if($headerPageTextLiteral)
        $pdf->page_text({{ $pageTextX }}, {{ $headerPageTextY }}, {!! $headerPageTextLiteral !!}, $font, 9);
        @endif
        @if($footerPageTextLiteral)
        $pdf->page_text({{ $pageTextX }}, $pdf->get_height() - {{ $footerPageTextYFromBottom }}, {!! $footerPageTextLiteral !!}, $font, 9);
        @endif
    }
    </script>
    @endif
</body>
</html>
