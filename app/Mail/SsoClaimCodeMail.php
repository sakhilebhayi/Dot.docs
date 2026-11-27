<?php

namespace App\Mail;

use App\Mail\Delivery\SendsOnce;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The one-time code that proves the person owns the address of an existing Dot.docs account. Plain text, no links.
 * Sent from the queue by App\Jobs\SendSsoClaimCode (R2-4 / E-05), which carries it encrypted and sends it once.
 */
class SsoClaimCodeMail extends Mailable
{
    use SendsOnce;

    public function __construct(public string $name, public string $code, public int $minutes)
    {
        $this->deliveryRef();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your Dot.docs confirmation code');
    }

    public function content(): Content
    {
        return new Content(text: 'emails.sso-claim-code');
    }
}
