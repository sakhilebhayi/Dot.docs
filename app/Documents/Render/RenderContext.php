<?php

namespace App\Documents\Render;

final class RenderContext
{
    /** @var array<string,string> block id => number label */
    public array $numbers = [];

    /** @var array<string,string> */
    public array $vars = [];

    /** @var array<string,string> block id => kind (heading|figure|table) */
    public array $kinds = [];

    /** @var list<array{id:string,level:int,text:string,number:string}> */
    public array $toc = [];

    private function __construct(public readonly string $mode) {}

    public static function editor(): self
    {
        return new self('editor');
    }

    public static function share(): self
    {
        return new self('share');
    }

    public static function print(): self
    {
        return new self('print');
    }
}
