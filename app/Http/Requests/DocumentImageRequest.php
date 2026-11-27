<?php

namespace App\Http\Requests;

/** A picture pasted or dropped into the editor (DocumentImageController). It is re-encoded to WebP there. */
class DocumentImageRequest extends DocumentRequest
{
    protected function ability(): string
    {
        return 'update';
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['image' => 'required|image|max:4096'];
    }
}
