<?php

namespace App\Http\Requests;

/** The editor's unload beacon (DocumentAutosaveController). A shape check: DocumentSchema vets the content itself. */
class AutosaveRequest extends DocumentRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'content' => ['required', 'array'],
            'content.type' => ['required', 'string', 'in:doc'],
            'content.content' => ['sometimes', 'array'],
            'content.attrs' => ['sometimes', 'array'],
            // The version the page's copy was based on. A beacon that states
            // none comes from a tab running JavaScript from before saves
            // carried one, and is refused: it would overwrite blind.
            'base_version' => ['required', 'integer', 'min:1'],
            // True when the page owes an overwrite: the writer chose "Keep
            // mine" or "Put it back", so this save replaces a newer version
            // their base is on, on purpose. See the controller's $opts.
            'overwrite' => ['sometimes', 'boolean'],
        ];
    }
}
