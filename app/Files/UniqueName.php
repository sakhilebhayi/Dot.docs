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
    /** The width of `folders.name` and `files.name`. */
    private const MAX_LENGTH = 255;

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
            $candidate = self::withSuffix($name, ' ('.$n.')');
            if (! isset($taken[mb_strtolower($candidate)])) {
                return $candidate;
            }
        }

        return self::withSuffix($name, ' ('.uniqid().')');
    }

    /**
     * `$name (2)`, shortened to fit the column.
     *
     * The BASE is trimmed, never the counter: the counter is the part that
     * makes the name unique, and handing the database a 259-character string
     * because somebody named a folder right at the boundary is a raw
     * QueryException on postgres and mysql (sqlite quietly accepts it, which
     * is why the tests did not notice).
     */
    private static function withSuffix(string $name, string $suffix): string
    {
        $room = self::MAX_LENGTH - mb_strlen($suffix);

        if ($room <= 0) {
            return mb_substr($suffix, 0, self::MAX_LENGTH);
        }

        return mb_strlen($name) <= $room
            ? $name.$suffix
            : rtrim(mb_substr($name, 0, $room)).$suffix;
    }
}
