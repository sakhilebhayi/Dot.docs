<?php

namespace App\Services\InfoDot;

use App\Models\Document;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells InfoDot that a document exists, so InfoDot can open it with a stable reference.
 * Only a pointer is sent: the company's InfoDot uuid, the document's uuid and the path it
 * opens at. Never the title, the content or who wrote it. Best effort by design: a failure
 * is logged and the editor carries on; `documents:register-resources` catches up later.
 */
class ResourceRegistry
{
    public function enabled(): bool
    {
        return filled(config('ecosystem.infodot.secret')) && filled($this->url());
    }

    public function register(Document $document): bool
    {
        return $this->registerPointer((string) $document->uuid, $document->team_id);
    }

    /**
     * Register by identifiers rather than by model: the observer queues this to run after the
     * response, and a queued closure that holds a model is serialised and re-read from the
     * database first (a needless query, and one that fails if the row has changed meanwhile).
     */
    public function registerPointer(string $documentUuid, ?int $teamId): bool
    {
        $team = $teamId !== null ? Team::find($teamId) : null;

        // Personal documents (no company) have nothing to register against.
        if (! $this->enabled() || ! $team || $team->personal_team || blank($team->infodot_team_uuid)) {
            return false;
        }

        try {
            $response = Http::withToken((string) config('ecosystem.infodot.secret'))
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->post($this->url(), [
                    'platform' => (string) config('ecosystem.infodot.platform'),
                    'team_uuid' => $team->infodot_team_uuid,
                    'type' => 'document',
                    'object_uuid' => $documentUuid,
                    'path' => '/o/'.$documentUuid,
                ]);
        } catch (Throwable $e) {
            Log::warning('InfoDot resource registration failed.', ['error' => class_basename($e)]);

            return false;
        }

        if (! $response->successful() || ! is_string($response->json('reference'))) {
            Log::warning('InfoDot refused a resource registration.', ['status' => $response->status()]);

            return false;
        }

        // Quietly: this is bookkeeping, not an edit, so no version, webhook or cache bust.
        Document::withTrashed()->where('uuid', $documentUuid)->toBase()->update([
            'infodot_reference' => $response->json('reference'),
            'infodot_resource_uuid' => $response->json('uuid'),
        ]);

        return true;
    }

    public function remove(string $documentUuid): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            Http::withToken((string) config('ecosystem.infodot.secret'))
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->delete($this->url().'/'.$documentUuid, ['platform' => (string) config('ecosystem.infodot.platform')]);
        } catch (Throwable $e) {
            Log::warning('InfoDot resource removal failed.', ['error' => class_basename($e)]);
        }
    }

    private function url(): string
    {
        return (string) (config('ecosystem.infodot.resources_url')
            ?: str_replace('/redeem', '/resources', (string) config('ecosystem.infodot.redeem_url')));
    }
}
