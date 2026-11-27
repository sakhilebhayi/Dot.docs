<?php

namespace App\Documents\Import\Docx;

use ZipArchive;

/**
 * Refuse a decompression bomb BEFORE PhpWord unpacks it.
 *
 * IOFactory::load() unzips the whole archive and DOM-parses it with no
 * size guard of its own, and a PHP memory-limit fatal cannot be caught —
 * so a file inside DocumentImportController's 20 MB upload cap, filled
 * with highly compressible XML, takes the worker down instead of getting
 * the 422 the import path promises. ZipArchive reads each entry's
 * declared uncompressed size straight out of the central directory
 * without inflating anything, which is what makes this cheap enough to
 * run on every import. (A lying central directory buys an attacker
 * little: libzip stops reading an entry at the size it declared.)
 *
 * Not a zip at all is NOT this class's business — IOFactory's own
 * failure is already answered with a 422 by the controller.
 */
final class DocxArchiveGuard
{
    public function __construct(
        private int $maxEntryBytes,
        private int $maxTotalBytes,
    ) {}

    public function check(string $path): void
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return;
        }

        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += $size = is_array($stat) ? $stat['size'] : 0;

            if ($size > $this->maxEntryBytes || $total > $this->maxTotalBytes) {
                $zip->close();

                abort(422, 'This file is too large to import.');
            }
        }

        $zip->close();
    }
}
