{{--
  <x-dot.search>: the Dot header search pill. Presentation only: no search logic, no routes, no required JS.
  Works as a plain GET form. Copied from InfoDot's header search (.ix-search), see docs/SEARCH.md.

  Props
    name         input name (default "search")
    placeholder  placeholder text
    value        current value
    action       form action URL (omit for a Livewire/JS-only host)
    method       form method (default get)
    label        visually hidden label and submit-button name (default "Search")
    wire         optional Livewire property: renders wire:model.live.debounce.400ms="<wire>" on the input
    hint         optional shortcut hint shown inside the pill, e.g. "Ctrl K" (default: none). With no hint the root gets
                 .dsr--nohint, which reserves only the circle's width on the right instead of 104px
    hint-key     true (default): a hint also BINDS Ctrl/Cmd+K to focus the box (js/dot-search.js). false (0.6.0): the hint is only printed, nothing is bound
                 (a platform that owns Ctrl K itself, or shows "/" as its key: `hint="/" :hint-key="false"`)
    results-nav  true (0.6.0): the dropdown's rows are driven from the keyboard by js/dot-search-results.js (ArrowUp/Down, aria-activedescendant, Enter opens the
                 highlighted row, Escape clears the highlight). Rows are `.dsr-item` elements, see <x-dot.search-row>.
    enter-first  true (0.6.0, with results-nav): Enter with nothing highlighted opens the FIRST row instead of submitting the form
    id           optional input id. Default: "dsr-<name>", the same on every render (snapshots, Livewire morphs). Pass
                 an id when two searches with the same name share a page.
    root-attributes  optional attributes for the root <div class="dsr"> (array or attribute bag), e.g. ['wire:ignore.self' => '']
                 in a Livewire host: Livewire's morph then leaves the root's own attributes (is-open, data-closed) alone.
                 dot-search.js also recovers them by itself after a morph, so this is a belt and braces option. A class is added to
                 the package's, and data-dsr / data-dsr-shortcut cannot be overridden.
    form-attributes  optional attributes for the <form> (array or attribute bag): wire:submit.prevent, data-*, id, class...
                 Merged over role="search", method and action; the host's values win.
    hidden-fields  optional array of extra hidden inputs rendered INSIDE the form, name => value (a value may be an array: one
                 input per item, name[]), e.g. :hidden-fields="['status' => 'open', 'sort' => 'new']". For anything the host's search
                 route needs besides the query; no `form` attribute workaround.
    loading      true: the root carries data-dsr-loading (the dropdown's loading line shows, see docs/SEARCH.md "Loading"). A host that
                 re-renders on its own (Livewire) usually toggles the attribute itself and leaves this false.
    loading-text optional: renders InfoDot's loading line, <p class="dsr-foot dsr-loading" aria-live="polite">, at the end of the
                 dropdown (InfoDot's reads "Looking in Files and Documents..."). Hidden unless data-dsr-loading is on the root.
    escape-clears  true: Escape also empties the box, as InfoDot's does (data-dsr-escape-clear; needs js/dot-search.js)
  Slots
    results      the host's dropdown markup, rendered inside .dsr-panel
    extra-hidden raw markup rendered inside the form, after the hidden fields, e.g. a CSRF field or hidden inputs the host builds itself
  Other attributes are merged onto the <input>.
--}}
@props([
    'name' => 'search',
    'placeholder' => 'Search',
    'value' => '',
    'action' => null,
    'method' => 'get',
    'label' => 'Search',
    'wire' => null,
    'hint' => null,
    'hintKey' => true,
    'resultsNav' => false,
    'enterFirst' => false,
    'id' => null,
    'formAttributes' => [],
    'rootAttributes' => [],
    'hiddenFields' => [],
    'loading' => false,
    'loadingText' => null,
    'escapeClears' => false,
])
@php
    $inputId = $id ?? $attributes->get('id') ?? 'dsr-'.(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-') ?: 'search');
    $formBag = $formAttributes instanceof \Illuminate\View\ComponentAttributeBag ? $formAttributes : new \Illuminate\View\ComponentAttributeBag((array) $formAttributes);
    $formDefaults = ['role' => 'search', 'method' => $method] + ($action ? ['action' => $action] : []);
    $rootBag = $rootAttributes instanceof \Illuminate\View\ComponentAttributeBag ? $rootAttributes : new \Illuminate\View\ComponentAttributeBag((array) $rootAttributes);
    $listId = $inputId.'-list';
    $hiddenInputs = [];
    foreach ((array) $hiddenFields as $hiddenName => $hiddenValue) {
        foreach (is_array($hiddenValue) ? array_values($hiddenValue) : [$hiddenValue] as $one) {
            $hiddenInputs[] = [is_array($hiddenValue) ? $hiddenName.'[]' : (string) $hiddenName, (string) $one];
        }
    }
    $hasResults = (isset($results) && ! $results->isEmpty()) || filled($loadingText);
    $bindKey = $hint && (is_string($hintKey) ? ! in_array(strtolower(trim($hintKey)), ['false', '0', 'no', 'off', ''], true) : (bool) $hintKey);
    $inputAttrs = [
        'id' => $inputId,
        'type' => 'search',
        'name' => $name,
        'value' => $value,
        'placeholder' => $placeholder,
        'autocomplete' => 'off',
    ];
    if ($hasResults) {
        $inputAttrs += ['role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-controls' => $listId, 'aria-expanded' => 'false'];
    }
@endphp
<div {{ $rootBag->except(['data-dsr', 'data-dsr-shortcut', 'data-dsr-loading', 'data-dsr-escape-clear', 'data-dsr-nav', 'data-dsr-enter-first'])->class(['dsr', 'dsr--nohint' => ! $hint]) }} data-dsr @if($bindKey) data-dsr-shortcut @endif @if($loading) data-dsr-loading @endif @if($escapeClears) data-dsr-escape-clear @endif @if($resultsNav) data-dsr-nav @endif @if($resultsNav && $enterFirst) data-dsr-enter-first @endif>
    <label for="{{ $inputId }}" class="dsr-sr">{{ $label }}</label>
    <form {{ $formBag->merge($formDefaults) }}>
        @foreach($hiddenInputs as [$hiddenName, $hiddenValue])
            <input type="hidden" name="{{ $hiddenName }}" value="{{ $hiddenValue }}">
        @endforeach
        @isset($extraHidden){{ $extraHidden }}@endisset
        <input
            {{ $attributes->except(['id'])->merge($inputAttrs)->class(['dsr-input']) }}
            @if($wire) wire:model.live.debounce.400ms="{{ $wire }}" @endif
        />
        @if($hint)<kbd class="dsr-kbd" aria-hidden="true">{{ $hint }}</kbd>@endif
        <button type="submit" class="dsr-go" aria-label="{{ $label }}"><span class="dsr-arrow" aria-hidden="true"></span></button>
    </form>
    @if($hasResults)
        <div id="{{ $listId }}" class="dsr-panel" role="listbox" aria-label="Suggestions" tabindex="-1">@isset($results){{ $results }}@endisset @if(filled($loadingText))<p class="dsr-foot dsr-loading" aria-live="polite">{{ $loadingText }}</p>@endif</div>
    @endif
</div>
