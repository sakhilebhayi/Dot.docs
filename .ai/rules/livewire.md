---
paths:
  - 'resources/views/livewire/**'
---

# Livewire

## Full-page Livewire views: exactly one root element, page-level <style> goes inside it
A full-page Livewire component's Blade view must render exactly ONE top-level element. Livewire scans the compiled HTML for the FIRST element and attaches wire:id/wire:snapshot/wire:effects to it as the component root; if a page-level <style> tag (or any other element) precedes the real root <div>, Livewire hydrates the wrong element and the component loses interactivity (wire:click/wire:model bindings inside the intended root never bind). Found in Task 6 review round 1: resources/views/livewire/documents/editor.blade.php had <style id="doc-style">{!! $styleCss !!}</style> BEFORE the root <div x-data=...>. Fix: put the <style> block as the FIRST CHILD inside the root <div>, never a sibling before it. Livewire docs (quickstart, 3.x): "components must have just ONE single element as its root. If multiple root elements are detected, an exception is thrown." Regression test pattern: GET the full-page route and confirm the component's own wire:snapshot (identify it by a property unique to that component, since other Livewire components render on the same page with their own snapshots) is attached to a <div>, not a <style> or other non-root tag.
