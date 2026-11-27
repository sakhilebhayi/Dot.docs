<?php

namespace App\Documents\Import;

use App\Documents\Import\Docx\DocxArchiveGuard;
use App\Documents\Import\Docx\DocxBlockReader;
use App\Documents\Import\Docx\DocxImageReader;
use App\Documents\Schema\DocumentSchema;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style;

/**
 * DOCX -> Dot.Doc JSON (see App\Documents\Schema\DocumentSchema).
 *
 * This walks PHPWord's element tree rather than going through its HTML
 * writer: the HTML writer flattens `Title` into a styled paragraph and
 * `ListItemRun` into an indented paragraph, so headings and lists — the two
 * structures an import exists to preserve — would be lost. See the mapping
 * table in .ai/rules/documents-io.md.
 *
 * The readers in App\Documents\Import\Docx do the walking: DocxBlockReader
 * for a run of sibling elements, which uses DocxListReader, DocxTableReader,
 * DocxTocReader, DocxImageReader and DocxRunReader (inline content).
 * DocxArchiveGuard runs first.
 *
 * @phpstan-import-type PmNode from DocumentSchema
 */
class DocxImporter
{
    /** Largest picture, in bytes, this importer will extract out of one .docx. */
    public const MAX_IMAGE_BYTES = 5_242_880;

    /** Most pictures this importer will extract out of one .docx. */
    public const MAX_IMAGES = 50;

    /** Largest single UNCOMPRESSED archive entry, in bytes, this importer will open. */
    public const MAX_ENTRY_BYTES = 52_428_800;

    /** Largest total UNCOMPRESSED size, in bytes, of every entry in one archive. */
    public const MAX_TOTAL_BYTES = 104_857_600;

    /**
     * The caps are constructor arguments, not constants, so a caller (and the
     * test suite) can bound a single import harder without a 5 MB fixture.
     */
    public function __construct(
        private DocumentSchema $schema = new DocumentSchema,
        private int $maxImageBytes = self::MAX_IMAGE_BYTES,
        private int $maxImages = self::MAX_IMAGES,
        private int $maxEntryBytes = self::MAX_ENTRY_BYTES,
        private int $maxTotalBytes = self::MAX_TOTAL_BYTES,
    ) {}

    /**
     * @param  string  $path  a readable .docx on the local filesystem
     * @param  string|null  $mediaKey  the owning document's uuid — images are extracted to
     *                                 its DocumentMedia folder
     * @return PmNode Dot.Doc JSON
     */
    public function import(string $path, ?string $mediaKey = null): array
    {
        // PhpOffice\PhpWord\Style is a static, process-wide registry, and the
        // reader pushes this file's numbering definitions into it under names
        // ("PHPWordList{numId}") that collide with any file read — or written
        // — earlier in the same process. Without this reset a second import
        // resolves its list types against the first document's numbering.
        Style::resetStyles();

        (new DocxArchiveGuard($this->maxEntryBytes, $this->maxTotalBytes))->check($path);

        $images = new DocxImageReader($mediaKey ?? (string) Str::uuid(), $this->maxImageBytes, $this->maxImages);
        $blocks = new DocxBlockReader($images);

        $content = [];
        foreach (IOFactory::load($path)->getSections() as $section) {
            array_push($content, ...$blocks->convertElements($section->getElements()));
        }

        $doc = ['type' => 'doc', 'content' => $content];

        return $this->schema->normalise($this->schema->ensureIds($doc));
    }
}
