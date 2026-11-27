<?php

namespace App\Documents;

use App\Models\Document;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Pictures inside documents (R2-1 / S-13).
 *
 * They live on the private disk, under the document they belong to, and are served by /media/{uuid}/{file}
 * (MediaController) to exactly the people who can read that document: its owner, company and collaborators, or a
 * visitor of its published or shared link once the link's expiry and password are satisfied. Before this they were
 * public files under /storage/, readable by anyone holding the address, whatever happened to the document.
 *
 * A document's `src` is the relative path `/media/{uuid}/{file}`. Older content may still carry `/storage/...`
 * paths; those keep rendering from the public disk.
 */
class DocumentMedia
{
    public const PREFIX = '/media/';

    private const DISK = 'local';

    private const ROOT = 'document-media';

    /** One file name: what upload and import write, and all the route accepts. */
    public const FILE = '[A-Za-z0-9][A-Za-z0-9-]{0,80}\.(?:webp|png|jpe?g|gif)';

    /** Session key set when a visitor unlocks a password-protected link to this document. */
    public static function unlockKey(string $uuid): string
    {
        return 'media.unlocked.'.$uuid;
    }

    /** Store a picture for $uuid and return its `src`. */
    public static function put(string $uuid, string $file, string $binary): string
    {
        Storage::disk(self::DISK)->put(self::ROOT.'/'.$uuid.'/'.$file, $binary);

        return self::PREFIX.$uuid.'/'.$file;
    }

    /** The file on disk for a `/media/...` src, or null if it is not one of ours or does not exist. */
    public static function path(string $src): ?string
    {
        if (preg_match('#^/media/([0-9a-fA-F-]{36})/('.self::FILE.')$#', $src, $m) !== 1) {
            return null;
        }

        return self::file($m[1], $m[2]);
    }

    public static function file(string $uuid, string $file): ?string
    {
        $disk = Storage::disk(self::DISK);
        $root = realpath($disk->path(self::ROOT));
        $path = realpath($disk->path(self::ROOT.'/'.$uuid.'/'.$file));

        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }

        return str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) ? $path : null;
    }

    /**
     * May the person behind $request see $document's pictures? Signed-in readers by the policy; anyone else only
     * through a live public link, and through a password-protected one only after unlocking it in this session.
     */
    public static function canSee(Request $request, Document $document): bool
    {
        $user = $request->user();

        if ($user !== null && $user->can('view', $document)) {
            return true;
        }

        if (! $document->is_public || ($document->share_expires_at && $document->share_expires_at->isPast())) {
            return false;
        }

        return blank($document->share_password)
            || ($request->hasSession() && hash_equals(self::unlockStamp($document), (string) $request->session()->get(self::unlockKey((string) $document->uuid))));
    }

    /**
     * What the session remembers after a correct password: tied to the password as it is now, so changing or
     * resetting the link's password ends every earlier unlock at once.
     */
    public static function unlockStamp(Document $document): string
    {
        return hash_hmac('sha256', (string) $document->share_password, (string) config('app.key'));
    }

    /**
     * Content copied into $uuid (a template, a paste, a copy) may carry pictures from another document's folder.
     * Each one $actor may read is copied into $uuid's own folder and its src rewritten, so the copy's readers see
     * it; one $actor may not read is left as it is, and stays invisible to anyone who cannot read its source.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    public static function adopt(string $uuid, array $json, ?User $actor): array
    {
        $walk = function (array $node) use (&$walk, $uuid, $actor): array {
            if (($node['type'] ?? null) === 'image' && is_string($node['attrs']['src'] ?? null)
                && preg_match('#^/media/([0-9a-fA-F-]{36})/('.self::FILE.')$#', $node['attrs']['src'], $m) === 1
                && strcasecmp($m[1], $uuid) !== 0) {
                $source = Document::where('uuid', $m[1])->first();
                $path = self::file($m[1], $m[2]);

                if ($source !== null && $path !== null && $actor !== null && $actor->can('view', $source)) {
                    $node['attrs']['src'] = self::put($uuid, $m[2], (string) file_get_contents($path));
                }
            }

            if (isset($node['content']) && is_array($node['content'])) {
                $node['content'] = array_map(fn ($child) => is_array($child) ? $walk($child) : $child, $node['content']);
            }

            return $node;
        };

        return $walk($json);
    }
}
