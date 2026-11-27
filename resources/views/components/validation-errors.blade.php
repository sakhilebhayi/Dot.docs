{{--
  The summary of a refused form (R2-12 / A-05). It is an alert, and on load resources/js/app.js moves the focus to it
  (`data-error-summary`, tabindex -1), so a screen reader reads it at once instead of starting at the top of the page.
  Each message links to the field it is about: the key names the field's id, as on every sign-in page, and the field
  itself carries aria-invalid and points at its own message (<x-input error="..."> with <x-input-error field="...">).
--}}
@if ($errors->any())
    <div {{ $attributes->merge(['class' => 'note note-danger error-summary']) }} role="alert" tabindex="-1" aria-labelledby="error-summary-title" data-error-summary>
        <x-shell.status-word tone="danger" word="{{ __('Failed') }}" />
        <div>
            <h2 class="error-summary-title" id="error-summary-title">{{ __('That did not go through.') }}</h2>
            <ul class="error-summary-list">
                @foreach ($errors->getMessages() as $key => $messages)
                    @foreach ($messages as $message)
                        <li><a href="#{{ $key }}">{{ $message }}</a></li>
                    @endforeach
                @endforeach
            </ul>
        </div>
    </div>
@endif
