<?php

namespace App\Documents\Import\Docx;

use App\Documents\DocumentMedia;
use App\Documents\Schema\DocumentSchema;
use PhpOffice\PhpWord\Element\Image;

/**
 * Extracts the pictures of ONE import, within its caps.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
final class DocxImageReader
{
    /** Image MIME types accepted out of a .docx, and the extension each is stored under. */
    private const IMAGE_EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /** Pictures already extracted during THIS import, against $maxImages. */
    private int $imageCount = 0;

    /**
     * @param  string  $mediaKey  the owning document's uuid, whose media folder images from THIS import are written to
     */
    public function __construct(
        private string $mediaKey,
        private int $maxImageBytes,
        private int $maxImages,
    ) {}

    /**
     * Extract an embedded image into the document's media folder and
     * reference it by the path DocumentMedia serves. An image PHPWord could
     * read but this app will not serve (anything outside IMAGE_EXTENSIONS) is
     * skipped rather than stored under a wrong extension.
     *
     * An upload is a stranger's file: a .docx is a zip, so a handful of
     * megabytes of it can carry far more picture than this app should write
     * to disk in one request. Both caps SKIP — a document whose pictures are
     * too big or too many still imports its text.
     *
     * @return list<PmNode>
     */
    public function convertImage(Image $image): array
    {
        if ($this->imageCount >= $this->maxImages) {
            return [];
        }

        $binary = $this->imageBinary($image);
        if ($binary === null) {
            return [];
        }

        $info = @getimagesizefromstring($binary);
        $mime = is_array($info) ? image_type_to_mime_type($info[2]) : null;
        if ($mime === null || ! isset(self::IMAGE_EXTENSIONS[$mime])) {
            return [];
        }

        $file = sha1($binary).'.'.self::IMAGE_EXTENSIONS[$mime];
        $this->imageCount++;

        // Private, beside the document, served to its readers only (R2-1 / S-13; DocumentMedia).
        return [['type' => 'image', 'attrs' => ['src' => DocumentMedia::put($this->mediaKey, $file, $binary)]]];
    }

    private function imageBinary(Image $image): ?string
    {
        // getImageStringData() hands back chunk_split()'d hex, which is the
        // only public accessor that works for both a zip:// source (an image
        // still inside the .docx) and a memory image.
        $hex = $image->getImageStringData();
        if (! is_string($hex) || $hex === '') {
            return null;
        }
        // Two hex characters per byte, plus a newline every 76 — so a hex
        // string this long cannot decode to anything within the cap, and the
        // decode (which doubles the memory again) is skipped entirely.
        if (strlen($hex) > $this->maxImageBytes * 3) {
            return null;
        }
        $binary = @hex2bin((string) preg_replace('/\s+/', '', $hex));

        if ($binary === false || $binary === '' || strlen($binary) > $this->maxImageBytes) {
            return null;
        }

        return $binary;
    }
}
