<?php

namespace App\Documents\Outline;

use App\Documents\Schema\DocumentSchema;

/**
 * Stateful decimal-counter numberer for heading nodes, used by Outline::build()
 * as it walks the document in order. One instance covers a single build() call.
 *
 * A heading outside [startLevel, maxLevel] is skipped entirely (not numbered,
 * not listed). Within range, every heading gets a TOC entry: when
 * headings === 'none', or when the heading's own attrs.numbered is explicitly
 * false, the entry carries number '' and the heading is excluded from
 * numbers (numbering and TOC listing are orthogonal - an unnumbered
 * "Foreword" is still listed, just without a number, as in Word). Every
 * other heading gets both a computed decimal number and a TOC entry.
 */
class HeadingNumberer
{
    /** @var array<int,int> level (1-based) => running count */
    private array $counters = [];

    public function __construct(
        private int $startLevel,
        private int $maxLevel,
        private bool $headingsNone,
    ) {}

    /** @return array{id:string,level:int,text:string,number:string}|null */
    public function number(array $node, DocumentSchema $schema): ?array
    {
        $level = (int) ($node['attrs']['level'] ?? 1);
        if ($level < $this->startLevel || $level > $this->maxLevel) {
            return null;
        }

        $id = $node['attrs']['id'] ?? '';
        $text = $schema->plainText($node);
        $individuallyNumbered = ($node['attrs']['numbered'] ?? true) !== false;

        if ($this->headingsNone || ! $individuallyNumbered) {
            return ['id' => $id, 'level' => $level, 'text' => $text, 'number' => ''];
        }

        $this->counters[$level] = ($this->counters[$level] ?? 0) + 1;
        foreach ($this->counters as $deeperLevel => $count) {
            if ($deeperLevel > $level) {
                unset($this->counters[$deeperLevel]);
            }
        }
        $parts = [];
        for ($l = $this->startLevel; $l <= $level; $l++) {
            $parts[] = $this->counters[$l] ?? 0;
        }

        return ['id' => $id, 'level' => $level, 'text' => $text, 'number' => implode('.', $parts)];
    }
}
