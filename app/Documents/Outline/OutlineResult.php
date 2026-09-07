<?php

namespace App\Documents\Outline;

/**
 * Result of building an outline over a Dot.Doc JSON document. This is a
 * passthrough placeholder until Task 5 implements real numbering, kind
 * detection, and table-of-contents / broken-reference tracking.
 */
class OutlineResult
{
    /** @var array<string,string> block id => number label */
    public array $numbers = [];

    /** @var array<string,string> block id => kind (heading|figure|table) */
    public array $kinds = [];

    /** @var list<array{id:string,level:int,text:string,number:string}> */
    public array $toc = [];

    /** @var list<array{id:string,level:int,text:string,number:string}> */
    public array $headings = [];

    /** @var list<array{id:string,number:string}> */
    public array $figures = [];

    /** @var list<array{id:string,number:string}> */
    public array $tables = [];

    /** @var list<string> */
    public array $broken = [];
}
