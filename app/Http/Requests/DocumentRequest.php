<?php

namespace App\Http\Requests;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * A request about one document named by the route's {uuid} (R2-7 / Q-06). The order the controllers had is kept:
 * the document is looked up (404), the policy is asked (403), and only then are the rules checked (422) - authorize()
 * runs before rules() - so a person who may not touch the document never learns what a valid request looks like.
 * The controller takes the same model from document() instead of looking it up again.
 */
abstract class DocumentRequest extends FormRequest
{
    private ?Document $document = null;

    /** The DocumentPolicy ability this request needs. */
    abstract protected function ability(): string;

    public function document(): Document
    {
        return $this->document ??= Document::where('uuid', (string) $this->route('uuid'))->firstOrFail();
    }

    public function authorize(): bool
    {
        // Gate::authorize, not `return can()`: the same 403 (and message) the controller used to throw.
        Gate::authorize($this->ability(), $this->document());

        return true;
    }
}
