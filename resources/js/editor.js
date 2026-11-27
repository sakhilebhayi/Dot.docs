// The editor page's own entry (R2-5 / P-09). layouts/app adds it after app.js only on documents.edit, so every
// other page skips TipTap/ProseMirror. Both are module scripts in <head>: they run in order, before Livewire starts
// Alpine, so window.DotDoc exists when the editor bridge's x-data initialises - as it did when app.js imported it.
import './editor/index';
