<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\WebhookService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sends a document's webhooks from the queue (EA-5 / P-06).
 *
 * They used to run inside the save request: a DNS check and a POST with a
 * 5 s timeout, on every autosave (about every 1.5 s while someone types).
 * Now a save queues at most ONE delivery per document per minute: the job is
 * unique while it waits and is delayed a minute, so a burst of autosaves
 * becomes a single POST carrying the document as it is when the job runs.
 * Exports are queued without the delay.
 */
class DeliverDocumentWebhooks implements ShouldBeUnique, ShouldQueue
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

    public function uniqueId(): string
    {
        // Saves coalesce; each export is its own delivery.
        return $this->event === 'on_save'
            ? 'save:'.$this->documentId
            : 'export:'.$this->documentId.':'.md5(serialize($this->payload)).':'.microtime(true);
    }

    public function uniqueFor(): int
    {
        return self::SAVE_DELAY_SECONDS * 3;
    }

    public function handle(WebhookService $webhooks): void
    {
        $document = Document::find($this->documentId);

        if ($document !== null) {
            $webhooks->fire($document, $this->event, $this->payload);
        }
    }
}
