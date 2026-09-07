<?php

namespace App\Documents\Outline;

/**
 * Builds heading/figure/table numbering, a table of contents, and
 * cross-reference resolution over a Dot.Doc JSON document.
 *
 * This is a passthrough placeholder for Task 5, which will replace the
 * internals of build() and apply() while keeping this class's shape.
 */
class Outline
{
    public function build(array $doc): OutlineResult
    {
        return new OutlineResult;
    }

    public function apply(array $doc, OutlineResult $result): array
    {
        return $doc;
    }
}
