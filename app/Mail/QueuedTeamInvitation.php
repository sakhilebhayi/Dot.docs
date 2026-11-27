<?php

namespace App\Mail;

use App\Mail\Delivery\MailNotDelivered;
use App\Mail\Delivery\SendsOnce;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Jetstream\TeamInvitation as TeamInvitationModel;
use Throwable;

/**
 * Jetstream's invitation, sent from the mail queue (EA-3 / E-05) with the same
 * safety as the other queued mail: three tries, no failed job when the
 * invitation was cancelled before the worker ran, and a log line when the
 * last try fails, a person is told (R2-4). Sent once, however often the job is
 * retried (SendsOnce). The template is still resources/views/emails/team-invitation.
 */
class QueuedTeamInvitation extends TeamInvitation
{
    use SendsOnce;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public bool $deleteWhenMissingModels = true;

    public function __construct(TeamInvitationModel $invitation)
    {
        parent::__construct($invitation);
        $this->deliveryRef();
    }

    public function failed(Throwable $e): void
    {
        MailNotDelivered::raise('TeamInvitation', $e);
    }
}
