<?php

namespace App\Files;

use App\Models\Files\Obj;

/**
 * "Report", then "Report (2)", then "Report (3)".
 *
 * Dot.Files enforces no uniqueness at all, so two folders called Reports
 * in the same parent are legal in the database and merely unusable for a
 * person. This is where a name is made unique BEFORE it is written, on
 * both products' create paths.
 */
class UniqueName
{
    public static function for(Obj $parent, string $name, ?Obj $ignore = null): string
    {
        $taken = [];

        foreach ($parent->children()->with('objectable')->get() as $sibling) {
            if ($ignore !== null && $sibling->id === $ignore->id) {
                continue;
            }
            $taken[mb_strtolower($sibling->name())] = true;
        }

        if (! isset($taken[mb_strtolower($name)])) {
            return $name;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $name.' ('.$n.')';
            if (! isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        return $name.' ('.uniqid().')';
    }
}
