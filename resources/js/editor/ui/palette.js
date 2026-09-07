import { commands, run } from '../commands/registry';
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
    system: 'Document',
};

/** Open the command palette over the given editor. */
export function openPalette(editor) {
    return openList({
        title: 'Commands',
        placeholder: 'Search commands…',
        empty: 'No command matches',
        items: commands.map((command) => ({
            key: command.name,
            title: command.title,
            hint: prettyShortcut(command.shortcut) || GROUP_LABELS[command.group] || command.group,
            group: command.group,
        })),
        onSelect: (item) => run(editor, item.key),
    });
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
