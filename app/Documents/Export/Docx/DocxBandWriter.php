<?php

namespace App\Documents\Export\Docx;

use App\Models\Document;
use App\Models\Team;
use App\Print\PageSetup;
use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Section;

/**
 * The running header and footer. `{{ page }}`/`{{ pages }}` become Word's
 * own PAGE/NUMPAGES fields — a literal number would be wrong on every page
 * but one — and every other `{{ key }}` is substituted here, exactly as
 * PrintRenderer does for the PDF surface.
 */
final class DocxBandWriter
{
    /** A running band segment that is literal text, not a Word field. */
    private const BAND_TEXT = 'text';

    /** A running band segment that is one of the two Word fields this writer emits. */
    private const BAND_FIELD = 'field';

    public function __construct(private DocxContext $context) {}

    public function writeBands(Section $section, PageSetup $setup, Document $doc): void
    {
        $team = $doc->team;
        $vars = array_merge($this->context->variables, [
            'title' => (string) $doc->title,
            'date' => now()->format('Y-m-d'),
            'team' => $team instanceof Team ? (string) $team->name : '',
        ]);

        $font = ['name' => $this->context->font('body'), 'size' => $this->context->size('small', 9.0), 'color' => $this->context->colour('muted', '5D5E5A')];

        $header = $this->band($setup->header, $vars);
        if ($header !== []) {
            $this->writeBand($section->addHeader(), $header, $font);
        }
        $footer = $this->band($setup->footer, $vars);
        if ($footer !== []) {
            $this->writeBand($section->addFooter(), $footer, $font, ['alignment' => 'center']);
        }
    }

    /**
     * Write one running band as a single paragraph of literal text runs and
     * Word fields.
     *
     * The fields are added with `addField()`, whose instruction this writer
     * builds from a fixed type name — NEVER from the band's text. PhpWord's
     * `PreserveText` (which addPreserveText() creates) splits whatever string
     * it is handed on every `{...}` and writes each match as a real field
     * instruction, so passing it a band that has had a document title or a
     * variable substituted into it turns `{INCLUDEPICTURE "http://…"}` in
     * that value into a genuine external-fetch field. band() has already
     * decided which segments are fields; a text segment's braces are just
     * characters and reach the file inside `w:t`.
     *
     * @param  list<array{0:string,1:string}>  $segments
     * @param  array<string,mixed>  $font
     * @param  array<string,mixed>  $paragraph
     */
    private function writeBand(Header|Footer $band, array $segments, array $font, array $paragraph = []): void
    {
        $run = $band->addTextRun($paragraph);

        foreach ($segments as [$kind, $value]) {
            if ($kind === self::BAND_FIELD) {
                // The fifth argument is Field's $fontStyle constructor
                // parameter, which AbstractContainer::__call() passes through;
                // PhpWord's @method tag for addField() lists only four.
                // @phpstan-ignore arguments.count
                $run->addField($value, [], [], null, $font);

                continue;
            }
            // escape() like every other text-writing path in this export: a
            // band's text is the page-setup template with $doc->variables
            // substituted into it, and both are user-controlled.
            $run->addText($this->context->escape($value), $font);
        }
    }

    /**
     * Split a band template into literal-text and field segments.
     *
     * The split happens on the app's OWN `{{ page }}`/`{{ pages }}`
     * placeholders, before any substitution, so a `{`/`}` that arrives inside
     * a substituted value can never be mistaken for one — see writeBand().
     *
     * @param  array<string,string>  $vars
     * @return list<array{0:string,1:string}>
     */
    private function band(string $template, array $vars): array
    {
        if (trim($template) === '') {
            return [];
        }

        $pieces = preg_split('/(\{\{\s*\w+\s*\}\})/', $template, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        $segments = [];
        foreach (is_array($pieces) ? $pieces : [] as $piece) {
            if (preg_match('/^\{\{\s*(\w+)\s*\}\}$/', $piece, $m) !== 1) {
                $segments[] = [self::BAND_TEXT, $piece];

                continue;
            }
            $segments[] = match ($m[1]) {
                'page' => [self::BAND_FIELD, 'PAGE'],
                'pages' => [self::BAND_FIELD, 'NUMPAGES'],
                default => [self::BAND_TEXT, $vars[$m[1]] ?? ''],
            };
        }

        // An all-text band that substituted down to nothing is no band at all,
        // which is what the plain `$header !== ''` test used to say.
        $text = implode('', array_map(
            fn (array $s): string => $s[0] === self::BAND_TEXT ? $s[1] : '',
            $segments
        ));

        $hasField = array_filter($segments, fn (array $s): bool => $s[0] === self::BAND_FIELD) !== [];

        if (! $hasField && trim($text) === '') {
            return [];
        }

        return array_values(array_filter($segments, fn (array $s): bool => $s[0] === self::BAND_FIELD || $s[1] !== ''));
    }
}
