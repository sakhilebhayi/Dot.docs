<?php

namespace App\Mail\Delivery;

use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Str;

/**
 * For a queued mailable: a reference made once, when the mailable is created, and serialized with it, so every retry of
 * the job carries the same one. MailDeliveryListener reads it from the X-Dot-Mail-Ref header and sends it only once.
 *
 * The class's constructor must call $this->deliveryRef(): a reference first made in the worker would be new on every
 * retry and stop nothing.
 */
trait SendsOnce
{
    public ?string $deliveryRef = null;

    public function deliveryRef(): string
    {
        return $this->deliveryRef ??= 'm:'.Str::uuid()->toString();
    }

    public function headers(): Headers
    {
        return new Headers(text: [MailDeliveryListener::REF_HEADER => $this->deliveryRef()]);
    }
}
