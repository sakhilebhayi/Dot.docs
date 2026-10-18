<x-app-layout>
    <x-slot name="title">Request access</x-slot>

    <div class="page">
        <div class="page-head">
            <div>
                <h1 class="page-title">You are not in the company that owns this document</h1>
                <p class="page-lede">The link is real, but the document belongs to a company you have not joined, so it cannot be shown. Ask an owner or administrator of that company to add you in InfoDot, then open the link again.</p>
            </div>
        </div>
        <p><a href="{{ route('documents.index') }}" class="btn btn-primary">Back to my documents</a></p>
    </div>
</x-app-layout>
