<?php

namespace Database\Seeders;

use App\Documents\Import\MarkdownImporter;
use App\Documents\Render\HtmlRenderer;
use App\Documents\Render\RenderContext;
use App\Documents\Schema\DocumentSchema;
use App\Models\DocumentTemplate;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The South African starter set: three global templates whose content is
 * authored as Markdown under resources/templates/ and imported here through
 * MarkdownImporter, so the file on disk stays the editable source and the
 * stored JSON is always what the importer produces from it (variables and
 * the `[[toc]]` marker included - see .ai/rules/documents-io.md).
 *
 * `content` is written too, as the rendered HTML: the column is NOT NULL and
 * is still what the gallery preview and any legacy reader look at, while
 * `content_json` is the source of truth DocumentTemplate::contentJson()
 * hands to DocumentStore.
 */
class StarterTemplateSeeder extends Seeder
{
    /**
     * @var list<array{file:string,name:string,category:string,description:string,style_key:string,page_setup:array<string,mixed>}>
     */
    private const TEMPLATES = [
        [
            'file' => 'monthly-production-report.md',
            'name' => 'Monthly production report',
            'category' => 'general',
            'description' => 'Production against target for a mine or plant, with grade, safety and the next period\'s commitments.',
            'style_key' => 'mining',
            // Wide production tables read badly down a portrait page.
            'page_setup' => ['size' => 'A4', 'orientation' => 'landscape'],
        ],
        [
            'file' => 'safety-incident-report.md',
            'name' => 'Safety incident report',
            'category' => 'general',
            'description' => 'A mining safety incident write-up: details, description, immediate actions, preliminary root cause and sign-off.',
            'style_key' => 'government',
            'page_setup' => ['size' => 'A4', 'orientation' => 'portrait'],
        ],
        [
            'file' => 'board-memorandum.md',
            'name' => 'Board memorandum',
            'category' => 'general',
            'description' => 'Purpose, background, discussion, financial implications, recommendation and the decision the board is asked to take.',
            'style_key' => 'executive',
            'page_setup' => ['size' => 'A4', 'orientation' => 'portrait'],
        ],
    ];

    public function __construct(
        private MarkdownImporter $importer = new MarkdownImporter,
        private DocumentSchema $schema = new DocumentSchema,
        private HtmlRenderer $renderer = new HtmlRenderer,
    ) {}

    public function run(): void
    {
        foreach (self::TEMPLATES as $template) {
            $json = $this->importer->import($this->markdown($template['file']));

            $errors = $this->schema->validate($json);
            if ($errors !== []) {
                throw new RuntimeException("{$template['file']} imported as invalid Dot.Doc JSON: ".implode('; ', $errors));
            }

            DocumentTemplate::updateOrCreate(
                ['name' => $template['name'], 'is_global' => true],
                [
                    'category' => $template['category'],
                    'description' => $template['description'],
                    'style_key' => $template['style_key'],
                    'page_setup' => $template['page_setup'],
                    'content_json' => $json,
                    'content' => $this->renderer->render($json, RenderContext::share()),
                    'team_id' => null,
                    'created_by' => null,
                ]
            );
        }
    }

    private function markdown(string $file): string
    {
        $path = resource_path('templates/'.$file);
        $markdown = is_file($path) ? file_get_contents($path) : false;

        if ($markdown === false) {
            throw new RuntimeException("Starter template source is missing: {$path}");
        }

        return $markdown;
    }
}
