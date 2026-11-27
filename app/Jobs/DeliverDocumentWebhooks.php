<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a document's webhooks from the queue (EA-5 / P-06), never inside the
 * request that caused them: a DNS check and a POST with a 5 s timeout used to
 * run on every autosave. Exports use this job directly; saves use
 * CoalescedSaveWebhooks, which sends at most one a minute.
 */
class DeliverDocumentWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const SAVE_DELAY_SECONDS = 60;

    public int $tries = 1;

    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public readonly int $documentId,
        public readonly string $event,
        public readonly array $payload = [],
    ) {}

    public function handle(WebhookService $webhooks): void
    {
        $document = Document::find($this->documentId);

        if ($document !== null) {
            $webhooks->fire($document, $this->event, $this->payload);
        }
    }
}
