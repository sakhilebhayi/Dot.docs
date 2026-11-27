<?php

namespace App\Http\Requests\Api;

use App\Models\Document;
use App\Models\User;
use App\Search\CompanyDocuments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * PUT /api/documents/{uuid}: the document is found in the token owner's current company (404 otherwise), authorised
 * (update), and only then is the body checked - the order the controller had (R2-7 / Q-06).
 */
class UpdateDocumentRequest extends FormRequest
{
    private ?Document $document = null;

    public function document(): Document
    {
        /** @var User $user */
        $user = $this->user();

        return $this->document ??= CompanyDocuments::find($user, (string) $this->route('uuid'));
    }

    public function authorize(): bool
    {
        Gate::authorize('update', $this->document());

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'markdown' => ['present', 'nullable', 'string', 'max:2000000'],
            'base_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
