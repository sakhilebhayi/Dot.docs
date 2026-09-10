@include('documents._password-gate', ['action' => route('documents.published.unlock', $document->slug), 'noindex' => true])
