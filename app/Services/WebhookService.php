<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentWebhook;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WebhookService
{
    /**
     * Fire all active webhooks for a document and event type.
     *
     * @param  string  $event  'on_save' | 'on_export'
     * @param  array  $payload  Extra data merged into the body
     */
    public function fire(Document $document, string $event, array $payload = []): void
    {
        $webhooks = DocumentWebhook::where('document_id', $document->id)
            ->where('status', 'active')
            ->get();

        foreach ($webhooks as $webhook) {
            if (! in_array($event, $webhook->events ?? [])) {
                continue;
            }

            // Re-checked here, not only when the URL was saved: DNS for a
            // stored hostname can change between the two (DNS rebinding), so
            // a target that was public when a writer configured it must
            // still be public right before this app posts to it. A failed
            // check SKIPS the delivery — it never throws into the request
            // that happened to trigger the webhook — and the warning names
            // the row, not the payload or the URL.
            if (! SsrfGuard::isSafeUrl((string) $webhook->url)) {
                Log::warning('Webhook delivery blocked: target does not resolve to a public address', [
                    'webhook_id' => $webhook->id,
                    'document_id' => $document->id,
                ]);

                continue;
            }

            $body = array_merge([
                'event' => $event,
                'document_id' => $document->id,
                'document_uuid' => $document->uuid,
                'title' => $document->title,
                'version' => $document->version,
                'timestamp' => now()->toIso8601String(),
            ], $payload);

            $headers = ['Content-Type' => 'application/json'];

            if ($webhook->secret) {
                $sig = hash_hmac('sha256', json_encode($body), $webhook->secret);
                $headers['X-Dotdocs-Signature'] = "sha256={$sig}";
            }

            try {
                // withoutRedirecting() is part of the SSRF control, not a
                // nicety: the guard above vets the URL we are about to call,
                // and Guzzle would otherwise follow up to five hops from the
                // response — unchecked — so a public target answering 302
                // http://169.254.169.254/ would walk this app's own network
                // context to the metadata endpoint. A webhook receiver has no
                // legitimate reason to redirect; a 3xx is simply a failed
                // delivery.
                Http::withHeaders($headers)
                    ->withoutRedirecting()
                    ->timeout(5)
                    ->post($webhook->url, $body);
            } catch (\Throwable $e) {
                Log::warning("Webhook delivery failed [{$webhook->id}]: ".$e->getMessage());
            }
        }
    }
}
