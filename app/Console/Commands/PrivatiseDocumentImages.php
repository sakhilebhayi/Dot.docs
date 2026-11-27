<?php

namespace App\Console\Commands;

use App\Documents\DocumentMedia;
use App\Documents\DocumentStore;
use App\Models\Document;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * R2-9 / S-13: move the pictures documents stored before R2-1 out of the public `/storage/...` folder and under the
 * document they belong to (DocumentMedia, served only to the document's readers).
 *
 * For every document whose content still points at `/storage/<file>`: each file that exists on the public disk is copied
 * into the document's own media folder, the document is saved through DocumentStore with the new `src` (no version is
 * cut; open editors follow the new version through the sync poll), and the same `src` is rewritten in the document's
 * version history so its previews keep showing the picture. Nothing is deleted unless --delete-public is given, and then
 * only files in the public `document-images` folder that no document or version points at any more.
 *
 * Run it once on the host after a backup: first with --dry-run, which only counts.
 */
class PrivatiseDocumentImages extends Command
{
    protected $signature = 'documents:privatise-images {--dry-run : Count what would move, change nothing} {--delete-public : Afterwards, delete public files nothing points at any more}';

    protected $description = 'Move pictures from the public /storage folder into each document\'s private media';

    /** A public path a document may point at: no `..`, no hidden segment, an image extension. */
    private const PUBLIC_SRC = '#^/storage/((?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-][A-Za-z0-9_.-]{0,120}\.(?:webp|png|jpe?g|gif))$#i';

    public function handle(DocumentStore $store): int
    {
        $dry = (bool) $this->option('dry-run');
        $public = Storage::disk('public');
        $documents = 0;
        $pictures = 0;
        $missing = 0;
        $moved = [];

        // The JSON column holds escaped slashes (`\/storage\/`), so the query only narrows by the word and the walk
        // below decides.
        Document::query()->where('content_json', 'like', '%storage%')->orderBy('id')
            ->each(function (Document $doc) use ($store, $dry, $public, &$documents, &$pictures, &$missing, &$moved) {
                $map = [];
                $json = $this->rewrite($store->json($doc), function (string $src) use ($doc, $dry, $public, &$map, &$missing) {
                    if (preg_match(self::PUBLIC_SRC, $src, $m) !== 1) {
                        return $src;
                    }
                    if (! $public->exists($m[1])) {
                        $missing++;

                        return $src;
                    }
                    if (! isset($map[$src])) {
                        $name = preg_match('/^'.DocumentMedia::FILE.'$/', basename($m[1])) === 1
                            ? basename($m[1])
                            : Str::uuid().'.'.strtolower(pathinfo($m[1], PATHINFO_EXTENSION));
                        $map[$src] = $dry ? DocumentMedia::PREFIX.$doc->uuid.'/'.$name
                            : DocumentMedia::put((string) $doc->uuid, $name, (string) $public->get($m[1]));
                    }

                    return $map[$src];
                });

                if ($map === []) {
                    return;
                }

                $documents++;
                $pictures += count($map);
                $moved += array_fill_keys(array_keys($map), true);

                if ($dry) {
                    return;
                }

                $owner = User::find($doc->owner_id);
                if (! $owner instanceof User) {
                    $this->warn("Document {$doc->uuid} has no owner; its pictures were copied but the document was not changed.");

                    return;
                }

                $store->save($doc, $json, $owner, ['version' => 'none']);

                // The history keeps showing the same pictures: only their address changes.
                foreach ($map as $from => $to) {
                    DB::update(
                        'UPDATE document_versions SET content_json = REPLACE(content_json, ?, ?), content_snapshot = REPLACE(content_snapshot, ?, ?) WHERE document_id = ?',
                        [json_encode($from) ?: '', json_encode($to) ?: '', $from, $to, $doc->id],
                    );
                }
            });

        $this->info(sprintf('%s %d picture(s) in %d document(s); %d address(es) point at a file that is not there.',
            $dry ? 'Would move' : 'Moved', $pictures, $documents, $missing));

        if ($this->option('delete-public') && ! $dry) {
            $deleted = 0;
            // Every picture left in the public folder, not only this run's: a later run with only --delete-public, after
            // checking the documents, must still find the files an earlier run moved.
            $candidates = array_keys($moved);
            foreach ($public->allFiles('document-images') as $path) {
                $candidates[] = '/storage/'.$path;
            }
            foreach (array_unique($candidates) as $src) {
                $stillUsed = false;
                foreach ([$src, trim((string) json_encode($src), '"')] as $form) {
                    $stillUsed = $stillUsed
                        || Document::where('content_json', 'like', '%'.$form.'%')->orWhere('content', 'like', '%'.$form.'%')->exists()
                        || DB::table('document_versions')->where('content_json', 'like', '%'.$form.'%')->orWhere('content_snapshot', 'like', '%'.$form.'%')->exists();
                }
                if (! $stillUsed && preg_match(self::PUBLIC_SRC, $src, $m) === 1 && $public->delete($m[1])) {
                    $deleted++;
                }
            }
            $this->info("Deleted {$deleted} public file(s) nothing points at any more.");
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  callable(string): string  $map
     * @return array<string, mixed>
     */
    private function rewrite(array $node, callable $map): array
    {
        if (($node['type'] ?? null) === 'image' && is_string($node['attrs']['src'] ?? null)) {
            $node['attrs']['src'] = $map($node['attrs']['src']);
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn ($child) => is_array($child) ? $this->rewrite($child, $map) : $child, $node['content']);
        }

        return $node;
    }
}
