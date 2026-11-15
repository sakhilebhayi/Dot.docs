{{--
  <x-dot.search-row>: one row of the search dropdown (0.6.0), the markup js/dot-search-results.js drives from the keyboard. Same classes InfoDot's rows use.
  Props
    href    the row's link (omit for a <button type="button"> row that the host handles)
    label   the row text
    code    optional reference code (FIIT-Q-000001), printed before the label (.dsr-ref)
    tag     optional small uppercase source tag after the label (.dsr-tag)
    action  true: the bold action row (.dsr-item--action)
    icon    optional inline SVG markup for a leading icon (print <svg class="dsr-icon">...)
  Other attributes (wire:click, wire:navigate, data-*) land on the row.
--}}
@props(['href' => null, 'label' => '', 'code' => null, 'tag' => null, 'action' => false, 'icon' => null])
@php
    $inner = ($icon ? new \Illuminate\Support\HtmlString($icon) : '');
@endphp
@if ($href !== null)
<a {{ $attributes->class(['dsr-item', 'dsr-item--action' => $action])->merge(['href' => $href, 'role' => 'option']) }}>{{ $inner }}@if($code)<span class="dsr-ref">{{ $code }}</span>@endif<span class="dsr-label">{{ $label }}</span>@if($tag)<span class="dsr-tag">{{ $tag }}</span>@endif</a>
@else
<button {{ $attributes->class(['dsr-item', 'dsr-item--action' => $action])->merge(['type' => 'button', 'role' => 'option']) }}>{{ $inner }}@if($code)<span class="dsr-ref">{{ $code }}</span>@endif<span class="dsr-label">{{ $label }}</span>@if($tag)<span class="dsr-tag">{{ $tag }}</span>@endif</button>
@endif
