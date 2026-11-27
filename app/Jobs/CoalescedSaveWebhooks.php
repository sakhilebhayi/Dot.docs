<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;

/**
 * A document's on_save webhooks, coalesced (EA-5 / P-06): while one delivery
 * waits (delayed a minute), further saves of that document queue nothing, so a
 * burst of autosaves is one POST carrying the document as it is when it runs.
 *
 * Unique only UNTIL PROCESSING: the lock is released as the job starts, so a
 * save that lands while the POSTs are going out queues the next delivery and
 * the receiver always ends up with the final state.
 */
class CoalescedSaveWebhooks extends DeliverDocumentWebhooks implements ShouldBeUniqueUntilProcessing
{
    public function uniqueId(): string
    {
        return 'save:'.$this->documentId;
    }

    public function uniqueFor(): int
    {
        return self::SAVE_DELAY_SECONDS * 3;
    }
}
