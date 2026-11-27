<?php

namespace App\Http\Requests\Api;

use App\Models\Document;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/** POST /api/documents: authorised (create) before the body is checked (R2-7 / Q-06). */
class StoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        Gate::authorize('create', Document::class);

        return true;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'markdown' => ['present', 'nullable', 'string', 'max:2000000'],
        ];
    }
}
