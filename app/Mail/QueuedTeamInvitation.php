<?php

namespace App\Mail;

use Illuminate\Support\Facades\Log;
use Laravel\Jetstream\Mail\TeamInvitation;
use Throwable;

/**
 * Jetstream's invitation, sent from the mail queue (EA-3 / E-05) with the same
 * safety as the other queued mail: three tries, no failed job when the
 * invitation was cancelled before the worker ran, and a log line when the
 * last try fails. The template is still resources/views/emails/team-invitation.
 */
class QueuedTeamInvitation extends TeamInvitation
{
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public bool $deleteWhenMissingModels = true;

    public function failed(Throwable $e): void
    {
        Log::error('Team invitation email could not be sent.', ['error' => $e::class]);
    }
}
