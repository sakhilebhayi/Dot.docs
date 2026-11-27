<?php

namespace App\Livewire\Documents\Concerns;

use App\Models\Document;
use App\Services\PresenceService;

/**
 * Who else has the document open (split out of Editor, R2-10). The sync poll keeps presence fresh; these are the Livewire-side calls.
 */
trait TracksPresence
{
    /**
     * Called by tabs opened before the sync poll shipped: their JavaScript
     * still calls this every 60 seconds. It does nothing now - presence is
     * recorded by the sync poll - but it must exist, or each of those calls
     * would be answered with an error page. Remove one release later.
     */
    public function heartbeat(): void {}

    /**
     * Called by tabs opened before the sync poll shipped, when they close.
     * It does nothing now, for the same reason as heartbeat(). Remove one
     * release later.
     */
    public function leaving(): void {}

    /**
     * Re-read who is here for the presence strip. The editor's sync poll
     * calls this when the set of people it is told about changes - presence
     * itself is recorded by that poll (DocumentSyncController), not here.
     */
    public function refreshPresence(): void
    {
        $this->authorize('view', $this->document);

        $this->activeUsers = $this->presentMembers();
    }

    /**
     * @return list<array{id:int,name:string,avatar:string}>
     */
    private function presentMembers(): array
    {
        $members = app(PresenceService::class)->members($this->document->id);
        $me = $this->actor();

        if (! collect($members)->contains('id', $me->id)) {
            $members[] = ['id' => $me->id, 'name' => $me->name, 'avatar' => $me->profile_photo_url];
        }

        return $members;
    }
}
