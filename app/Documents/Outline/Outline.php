<?php

namespace App\Documents\Outline;

use App\Documents\Schema\DocumentSchema;

/**
 * Builds heading/figure/table numbering, a table of contents, and
 * cross-reference resolution over a Dot.Doc JSON document.
 *
 * Numbering rules:
 * - headings: 'decimal' (default) numbers eligible headings 1, 1.1, 1.2.1 ...;
 *   'none' numbers no heading but still lists every heading in the TOC.
 * - startLevel/maxLevel bound which heading levels participate in numbering
 *   and the TOC. A heading outside that range is neither numbered nor listed.
 * - A heading's own attrs.numbered defaults to true; explicitly false excludes
 *   it from numbers only - it is still listed in the TOC, with number ''
 *   (numbering and listing are orthogonal, as in Word). headings === 'none'
 *   applies the same "listed with number ''" treatment to every in-range
 *   heading, numbered or not.
 * - figures: 'sequential' (default) numbers images and tables in two separate
 *   running counters; 'byChapter' prefixes the current top-level (level 1)
 *   heading's number and resets each counter when the chapter changes. Before
 *   the first heading, byChapter falls back to a plain running sequence.
 */
class Outline
{
    private const DEFAULT_RULES = [
        'headings' => 'decimal',
        'startLevel' => 1,
        'maxLevel' => 3,
        'figures' => 'sequential',
    ];

    public function build(array $doc, array $rules = []): OutlineResult
    {
        $rules = array_merge(self::DEFAULT_RULES, $rules);
        $result = new OutlineResult;
        $numberer = new HeadingNumberer($rules['startLevel'], $rules['maxLevel'], $rules['headings'] === 'none');

        $chapter = null;
        $figureCounters = ['sequential' => 0, 'chapter' => 0];
        $tableCounters = ['sequential' => 0, 'chapter' => 0];

        $schema = new DocumentSchema;
        $schema->walk($doc, function (array $node) use (&$result, &$chapter, &$figureCounters, &$tableCounters, $rules, $numberer, $schema): void {
            $type = $node['type'] ?? '';
            if ($type === 'heading') {
                $entry = $numberer->number($node, $schema);
                if ($entry === null) {
                    return;
                }
                if ($entry['number'] !== '') {
                    $result->numbers[$entry['id']] = $entry['number'];
                    $result->kinds[$entry['id']] = 'heading';
                    if ($entry['level'] === 1) {
                        $chapter = $entry['number'];
                        $figureCounters['chapter'] = 0;
                        $tableCounters['chapter'] = 0;
                    }
                }
                $result->headings[] = $entry;
                $result->toc[] = $entry;

                return;
            }
            if ($type === 'figure') {
                $kind = $node['attrs']['kind'] ?? 'image';
                $id = $node['attrs']['id'] ?? '';
                $number = $kind === 'table'
                    ? $this->nextFigureNumber($tableCounters, $rules['figures'], $chapter)
                    : $this->nextFigureNumber($figureCounters, $rules['figures'], $chapter);
                $result->numbers[$id] = $number;
                $result->kinds[$id] = $kind === 'table' ? 'table' : 'figure';
                $entry = ['id' => $id, 'number' => $number];
                if ($kind === 'table') {
                    $result->tables[] = $entry;
                } else {
                    $result->figures[] = $entry;
                }

                return;
            }
        });

        // Cross-references are resolved in a second pass, after numbering is
        // complete, so a reference to a target later in the document isn't
        // wrongly reported as broken.
        $schema->walk($doc, function (array $node) use (&$result): void {
            if (($node['type'] ?? '') !== 'crossRef') {
                return;
            }
            $targetId = $node['attrs']['targetId'] ?? '';
            if (! isset($result->numbers[$targetId])) {
                $result->broken[] = $targetId;
            }
        });
        $result->broken = array_values(array_unique($result->broken));

        return $result;
    }

    public function apply(array $doc, OutlineResult $result): array
    {
        return $this->applyNode($doc, $result);
    }

    private function applyNode(array $node, OutlineResult $result): array
    {
        $type = $node['type'] ?? '';
        if ($type === 'toc') {
            $depth = $node['attrs']['depth'] ?? 3;
            $node['attrs']['entries'] = array_values(array_filter(
                $result->toc,
                fn (array $entry): bool => $entry['level'] <= $depth
            ));
        }
        if ($type === 'crossRef') {
            $kindPrefix = match ($node['attrs']['kind'] ?? 'heading') {
                'figure' => 'Figure ',
                'table' => 'Table ',
                default => 'Section ',
            };
            $number = $result->numbers[$node['attrs']['targetId'] ?? ''] ?? null;
            $node['attrs']['label'] = $number !== null ? $kindPrefix.$number : '?';
        }
        if (isset($node['content']) && is_array($node['content'])) {
            $node['content'] = array_map(fn (array $c) => $this->applyNode($c, $result), $node['content']);
        }

        return $node;
    }

    /** @param array{sequential:int,chapter:int} $counters */
    private function nextFigureNumber(array &$counters, string $rule, ?string $chapter): string
    {
        if ($rule === 'byChapter' && $chapter !== null) {
            $counters['chapter']++;

            return "{$chapter}.{$counters['chapter']}";
        }
        $counters['sequential']++;

        return (string) $counters['sequential'];
    }
}
