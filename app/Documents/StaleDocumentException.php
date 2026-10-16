<?php

namespace App\Documents;

use RuntimeException;

/**
 * A save was based on an older version of the document than the one stored.
 *
 * Thrown by DocumentStore::save() when the caller states the version it
 * started from (`expectedVersion`) and somebody has saved since. Nothing is
 * written. `$currentVersion` is what the document is at now, so the caller
 * can tell the browser what it is behind.
 */
class StaleDocumentException extends RuntimeException
{
    public function __construct(public readonly int $currentVersion)
    {
        parent::__construct("The document is at version {$currentVersion}, newer than the version this save was based on.");
    }
}
