<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The editor's poll (DocumentSyncController). Validated first, as before: the controller then looks the document up and
 * asks the policy itself, because the quiet case must stay a handful of small columns.
 */
class DocumentSyncRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:0'],
            // \A...\z, not ^...$: in PCRE `$` also matches before a trailing
            // newline, so "tab-a\n" passed and only the TrimStrings
            // middleware was saving the rule. The column is 64 wide.
            'tab' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9-]+\z/'],
            'leaving' => ['sometimes', 'boolean'],
            // Which version of this request format the caller speaks. Not
            // acted on yet: it is accepted now so that a later phase can
            // tell a tab still running old JavaScript to reload.
            'protocol' => ['sometimes', 'integer'],
        ];
    }
}
