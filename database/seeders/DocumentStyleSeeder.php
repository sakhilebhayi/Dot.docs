<?php

namespace Database\Seeders;

use App\Models\DocumentStyle;
use Illuminate\Database\Seeder;

/**
 * Installs the fourteen system document styles. Each entry carries a full
 * token array (see App\Styles\StyleEngine for the token shape and how it is
 * turned into CSS). System rows are shared across all teams (team_id null)
 * and are kept in sync on every deploy via updateOrCreate.
 */
class DocumentStyleSeeder extends Seeder
{
    private const SANS_JETBRAINS = 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap';

    private const SANS_IBM_PLEX = 'https://fonts.googleapis.com/css2?family=Source+Sans+3:wght@400;600;700&family=IBM+Plex+Mono:wght@400;500&display=swap';

    private const SERIF_JETBRAINS = 'https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap';

    private const SERIF_SANS_JETBRAINS = 'https://fonts.googleapis.com/css2?family=Source+Serif+4:wght@400;600;700&family=Source+Sans+3:wght@400;600;700&family=JetBrains+Mono:wght@400;500&display=swap';

    /**
     * @var array<string, array{name:string,category:string,tokens:array}>
     */
    public const STYLES = [
        'corporate' => [
            'name' => 'Corporate',
            'category' => 'corporate',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#1f4e79', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => true, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'executive' => [
            'name' => 'Executive',
            'category' => 'executive',
            'tokens' => [
                'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SERIF_SANS_JETBRAINS],
                'sizes' => ['body' => '12pt', 'h1' => '24pt', 'h2' => '17pt', 'h3' => '14pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#9a9a94', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'none', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'rule', 'zebra' => false, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '30mm', 'right' => '30mm', 'bottom' => '30mm', 'left' => '30mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'academic' => [
            'name' => 'Academic',
            'category' => 'academic',
            'tokens' => [
                'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Serif 4', 'mono' => 'JetBrains Mono', 'import' => self::SERIF_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.6,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#2f7043', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 4, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'rule', 'zebra' => false, 'border' => 'hairline'],
                'caption' => ['position' => 'above', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'justify',
            ],
        ],
        'legal' => [
            'name' => 'Legal',
            'category' => 'legal',
            'tokens' => [
                'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Serif 4', 'mono' => 'JetBrains Mono', 'import' => self::SERIF_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#a9352d', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 5, 'figures' => 'sequential'],
                'headingCase' => 'upper',
                'table' => ['header' => 'band', 'zebra' => false, 'border' => 'grid'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '25mm', 'bottom' => '25mm', 'left' => '25mm'], 'header' => '{{ title }}', 'footer' => '{{ title }} · Page {{ page }}'],
                'align' => 'left',
            ],
        ],
        'financial' => [
            'name' => 'Financial',
            'category' => 'financial',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'IBM Plex Mono', 'import' => self::SANS_IBM_PLEX],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#f1c62e', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => false, 'border' => 'grid'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'technical' => [
            'name' => 'Technical',
            'category' => 'technical',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#3f8f5a', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 4, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => false, 'border' => 'grid'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'government' => [
            'name' => 'Government',
            'category' => 'government',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#005a2b', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'upper',
                'table' => ['header' => 'band', 'zebra' => false, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'mining' => [
            'name' => 'Mining',
            'category' => 'mining',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9.5pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#8a6d05', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => true, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'engineering' => [
            'name' => 'Engineering',
            'category' => 'engineering',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#2a2f38', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 4, 'figures' => 'byChapter'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => false, 'border' => 'grid'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'marketing' => [
            'name' => 'Marketing',
            'category' => 'marketing',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '30pt', 'h2' => '20pt', 'h3' => '15pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#c9463d', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'none', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'rule', 'zebra' => false, 'border' => 'none'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'proposal' => [
            'name' => 'Proposal',
            'category' => 'proposal',
            'tokens' => [
                'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SERIF_SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#0f766e', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 2, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => false, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'report' => [
            'name' => 'Report',
            'category' => 'report',
            'tokens' => [
                'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SERIF_SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#8a6d05', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'decimal', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'band', 'zebra' => true, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'minimal' => [
            'name' => 'Minimal',
            'category' => 'minimal',
            'tokens' => [
                'fonts' => ['body' => 'Source Sans 3', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SANS_JETBRAINS],
                'sizes' => ['body' => '11pt', 'h1' => '22pt', 'h2' => '16pt', 'h3' => '13pt', 'small' => '9pt'],
                'leading' => 1.45,
                'spacing' => ['paragraph' => '0.6em', 'headingTop' => '1.6em', 'headingBottom' => '0.5em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#1f2023', 'rule' => '#fbfaf7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'none', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'rule', 'zebra' => false, 'border' => 'hairline'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
        'creative' => [
            'name' => 'Creative',
            'category' => 'creative',
            'tokens' => [
                'fonts' => ['body' => 'Source Serif 4', 'heading' => 'Source Sans 3', 'mono' => 'JetBrains Mono', 'import' => self::SERIF_SANS_JETBRAINS],
                'sizes' => ['body' => '12pt', 'h1' => '34pt', 'h2' => '22pt', 'h3' => '16pt', 'small' => '9pt'],
                'leading' => 1.7,
                'spacing' => ['paragraph' => '0.8em', 'headingTop' => '2em', 'headingBottom' => '0.6em'],
                'colours' => ['ink' => '#1f2023', 'heading' => '#1f2023', 'accent' => '#7c3aed', 'rule' => '#d5d1c7', 'muted' => '#5d5e5a'],
                'numbering' => ['headings' => 'none', 'startLevel' => 1, 'maxLevel' => 3, 'figures' => 'sequential'],
                'headingCase' => 'none',
                'table' => ['header' => 'rule', 'zebra' => false, 'border' => 'none'],
                'caption' => ['position' => 'below', 'style' => 'italic'],
                'page' => ['size' => 'A4', 'margins' => ['top' => '25mm', 'right' => '20mm', 'bottom' => '25mm', 'left' => '20mm'], 'header' => '{{ title }}', 'footer' => 'Page {{ page }} of {{ pages }}'],
                'align' => 'left',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::STYLES as $key => $style) {
            DocumentStyle::updateOrCreate(
                ['key' => $key, 'team_id' => null],
                ['name' => $style['name'], 'category' => $style['category'], 'tokens' => $style['tokens'], 'is_system' => true]
            );
        }
    }
}
