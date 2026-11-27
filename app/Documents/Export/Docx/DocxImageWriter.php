<?php

namespace App\Documents\Export\Docx;

use App\Documents\DocumentMedia;
use App\Documents\Schema\DocumentSchema;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Shared\Converter;
use Throwable;

/**
 * Embeds an `image` node's picture, block-level or inline.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
final class DocxImageWriter
{
    /**
     * The `image.attrs.src` values this writer will embed: a path under the
     * public disk, every segment starting with an alphanumeric (so no `.`
     * or `..` segment can appear), ending in an image extension this app
     * stores. `DocumentSchema` puts no format constraint on the src and
     * content reaches the store straight from the autosave endpoint, so this
     * is where a hand-written traversal src is refused.
     */
    private const IMAGE_SRC = '#^/storage/[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*\.(?:png|jpe?g|gif|webp)$#i';

    public function __construct(private DocxContext $context) {}

    /**
     * Only images this application actually stores are embedded: a remote
     * `https://` src would make the export fetch an arbitrary URL server-side
     * on every download, and a missing local file must not abort the whole
     * document.
     *
     * @param  PmNode  $node
     */
    public function writeImage(array $node, Section|Cell|TextRun $container): void
    {
        $src = $node['attrs']['src'] ?? null;
        if (! is_string($src)) {
            return;
        }
        // A detached run (DocxBlockWriter::writeHeading()'s Title run) is not
        // in the document's element tree, so an image added to it would never
        // reach the media collection and would write a picture reference
        // pointing at nothing. getPhpWord() is documented as never null, but
        // an element never added to a container has none.
        // @phpstan-ignore identical.alwaysFalse
        if ($container->getPhpWord() === null) {
            return;
        }
        $path = $this->publicDiskPath($src);
        if ($path === null) {
            return;
        }

        $style = [];
        $width = $node['attrs']['width'] ?? null;
        if (is_numeric($width) && (float) $width > 0) {
            $style['width'] = Converter::pixelToPoint((float) $width);
        }

        try {
            $container->addImage($path, $style);
        } catch (Throwable) {
            // An unreadable or unsupported picture is not a reason to fail
            // the export; the rest of the document still exports.
        }
    }

    /**
     * The absolute path an `image.attrs.src` names inside the public disk, or
     * null when it names anything else — a remote URL, a file type this app
     * does not store, or a path that climbs out of the disk.
     *
     * Both checks are load-bearing. The pattern refuses `.`/`..` segments and
     * anything but an image extension before a path is built at all;
     * realpath() then proves that what the src actually resolves to is still
     * under the disk root, which is the only check a symlink cannot walk
     * around. A src that fails is SKIPPED — the rest of the document still
     * exports — never thrown, because this is stored content and an export
     * that 500s is worse than an export missing a picture.
     */
    private function publicDiskPath(string $src): ?string
    {
        // Pictures on the private disk (R2-1 / S-13): the exporter already
        // passed the document's own access check, so it reads them directly.
        // Only this document's own pictures: a src naming another document's
        // folder is skipped, so pasting another document's address cannot pull
        // its file into this export.
        if (str_starts_with($src, DocumentMedia::PREFIX)) {
            return $this->context->mediaOwner !== null && str_starts_with($src, DocumentMedia::PREFIX.$this->context->mediaOwner.'/')
                ? DocumentMedia::path($src)
                : null;
        }

        if (preg_match(self::IMAGE_SRC, $src) !== 1) {
            return null;
        }

        $root = realpath(Storage::disk('public')->path(''));
        $path = realpath(Storage::disk('public')->path(substr($src, strlen('/storage/'))));
        if ($root === false || $path === false || ! is_file($path)) {
            return null;
        }

        $root = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        return str_starts_with($path, $root) ? $path : null;
    }
}
