---
paths:
  - 'resources/views/livewire/**'
---

# Livewire

## Full-page Livewire views: exactly one root element, page-level <style> goes inside it
A full-page Livewire component's Blade view must render exactly ONE top-level element. Livewire scans the compiled HTML for the FIRST element and attaches wire:id/wire:snapshot/wire:effects to it as the component root; if a page-level <style> tag (or any other element) precedes the real root <div>, Livewire hydrates the wrong element and the component loses interactivity (wire:click/wire:model bindings inside the intended root never bind). Found in Task 6 review round 1: resources/views/livewire/documents/editor.blade.php had <style id="doc-style">{!! $styleCss !!}</style> BEFORE the root <div x-data=...>. Fix: put the <style> block as the FIRST CHILD inside the root <div>, never a sibling before it. Livewire docs (quickstart, 3.x): "components must have just ONE single element as its root. If multiple root elements are detected, an exception is thrown." Regression test pattern: GET the full-page route and confirm the component's own wire:snapshot (identify it by a property unique to that component, since other Livewire components render on the same page with their own snapshots) is attached to a <div>, not a <style> or other non-root tag.

## Every editor-mutating path in the bridge carries the same fail-closed gate
When the content check refuses a document, mount() calls failClosed(): read-only editor, visible banner, handle.autosaves === false. From that moment what the editor is showing is NOT the document, so every path in editor.blade.php that writes into it or reads a draft has to check handle.autosaves === false first - persist(), clearDraftIfSettled(), restoreDraftIfRestorable(), the update listener that writes the offline draft, the Echo .document.updated listener AND applySuggestion(), which was missing the gate and let an accepted suggestion mutate a read-only view. When a path refuses, say so: set this.aiError, which renders in the status area next to the @error(content) message. Adding a new path that touches the editor means adding the gate to it.
