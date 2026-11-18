<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The one-time code that proves the person owns the address of an existing Dot.docs account. Plain text, no links. */
class SsoClaimCodeMail extends Mailable
{
    public function __construct(public string $name, public string $code, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Dot.docs confirmation code');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.sso-claim-code');
    }
}
