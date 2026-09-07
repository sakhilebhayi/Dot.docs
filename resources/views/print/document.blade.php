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
    </style>
</head>
<body>
    <div class="print-header">{!! $headerHtml !!}</div>

    <div class="paper">
        {!! $body !!}
    </div>

    <div class="print-footer">{!! $footerHtml !!}</div>

    @if($headerPageTextLiteral || $footerPageTextLiteral)
    <script type="text/php">
    if (isset($pdf)) {
        $font = $fontMetrics->getFont('Helvetica');
        @if($headerPageTextLiteral)
        $pdf->page_text(40, 24, {!! $headerPageTextLiteral !!}, $font, 9);
        @endif
        @if($footerPageTextLiteral)
        $pdf->page_text(40, $pdf->get_height() - 30, {!! $footerPageTextLiteral !!}, $font, 9);
        @endif
    }
    </script>
    @endif
</body>
</html>
