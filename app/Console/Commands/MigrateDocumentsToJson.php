<?php

namespace App\Console\Commands;

use App\Documents\DocumentStore;
use App\Documents\Import\HtmlToJson;
use App\Models\Document;
use App\Models\DocumentTemplate;
use Illuminate\Console\Command;

/**
 * Backfills content_json on legacy rows that only have a rendered HTML
 * blob (content column) so they can be picked up by DocumentStore's JSON
 * write path. Safe to re-run: only rows with a null content_json are
 * touched.
 */
class MigrateDocumentsToJson extends Command
{
    protected $signature = 'dot:documents:migrate-json {--dry-run}';

    protected $description = 'Backfill content_json on legacy documents and document templates from their HTML content';

    public function handle(DocumentStore $store, HtmlToJson $converter): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $documentsCount = 0;
        Document::whereNull('content_json')->withTrashed()->cursor()->each(function (Document $document) use ($store, $converter, $dryRun, &$documentsCount) {
            $documentsCount++;
            if ($dryRun) {
                return;
            }
            $json = $converter->convert($document->content ?? '');
            $store->refill($document, $json);
        });

        $templatesCount = 0;
        DocumentTemplate::whereNull('content_json')->cursor()->each(function (DocumentTemplate $template) use ($converter, $dryRun, &$templatesCount) {
            $templatesCount++;
            if ($dryRun) {
                return;
            }
            $template->content_json = $converter->convert($template->content ?? '');
            $template->saveQuietly();
        });

        $prefix = $dryRun ? '[dry-run] Would backfill' : 'Backfilled';
        $this->info("{$prefix} {$documentsCount} document(s) and {$templatesCount} template(s).");

        return self::SUCCESS;
    }
}
