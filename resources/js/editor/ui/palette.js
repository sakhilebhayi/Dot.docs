import { paletteCommands, run } from '../commands/registry';
import { closeList, isListOpen, openList } from './list';

/** Pretty-print a registry shortcut for the platform the reader is on. */
export function prettyShortcut(shortcut) {
    if (!shortcut) {
        return '';
    }

    const isApple = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform || '');

    return shortcut
        .replace('Mod', isApple ? '⌘' : 'Ctrl')
        .replace('Alt', isApple ? '⌥' : 'Alt')
        .replace('Shift', isApple ? '⇧' : 'Shift')
        .split('-')
        .join(isApple ? '' : '+');
}

const GROUP_LABELS = {
    text: 'Text',
    lists: 'Lists',
    insert: 'Insert',
    layout: 'Layout',
    format: 'Format',
    table: 'Table',
    media: 'Image',
    system: 'Document',
};

/** Open the command palette over the given editor. */
export function openPalette(editor) {
    return openList({
        title: 'Commands',
        placeholder: 'Search commands…',
        empty: 'No command matches',
        items: paletteCommands().map((command) => ({
            key: command.name,
            title: command.title,
            hint: prettyShortcut(command.shortcut) || GROUP_LABELS[command.group] || command.group,
            group: command.group,
        })),
        onSelect: (item) => run(editor, item.key),
    });
}

/**
 * Whether Cmd/Ctrl+K belongs to the palette right now.
 *
 * The listener is on `window` (in capture), so without this the chord is
 * stolen from every other field on the page — most visibly the document
 * title input in the toolbar, where ⌘K would open the palette instead of
 * doing whatever the browser or the field does. The palette's own input is
 * the exception: it lives inside the overlay and ⌘K there closes it.
 */
function paletteOwnsChord(editor, target) {
    if (!(target instanceof HTMLElement)) {
        // No element (or a non-DOM event target): treat it as the page.
        return true;
    }
    if (target.closest('.dotdoc-overlay')) {
        return true;
    }

    const editable = target.closest('input, textarea, select, [contenteditable=""], [contenteditable="true"]');
    if (!editable) {
        return true;
    }

    // A contenteditable inside the editor IS the editor.
    return !!editor?.view?.dom && (editable === editor.view.dom || editor.view.dom.contains(editable));
}

/**
 * Bind Cmd/Ctrl+K to the palette. The AI palette on the same page listens on
 * Cmd/Ctrl+Shift+K so the two do not fight over one chord.
 *
 * @returns {() => void} teardown
 */
export function installPalette(editor) {
    const onKeyDown = (event) => {
        if (event.key !== 'k' && event.key !== 'K') {
            return;
        }
        if (!(event.metaKey || event.ctrlKey) || event.shiftKey || event.altKey) {
            return;
        }
        if (!editor || editor.isDestroyed) {
            return;
        }
        if (!paletteOwnsChord(editor, event.target)) {
            return;
        }

        event.preventDefault();

        if (isListOpen()) {
            closeList();

            return;
        }

        openPalette(editor);
    };

    window.addEventListener('keydown', onKeyDown, true);

    return () => window.removeEventListener('keydown', onKeyDown, true);
}
