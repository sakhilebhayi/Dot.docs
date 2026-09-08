import { Node, mergeAttributes } from '@tiptap/core';

/** The four tones CssBuilder::calloutRules() styles. Anything else falls back to 'note'. */
export const CALLOUT_TONES = ['note', 'warning', 'success', 'danger'];

/**
 * The tone in `class="callout callout-{tone}"`, which is all
 * HtmlRenderer::renderCallout() emits — it writes no `data-tone`, so parsing
 * the server's own HTML has to read the class or every callout comes back a
 * note.
 */
export function toneFromClass(className) {
    const match = String(className || '').match(/(?:^|\s)callout-([a-z]+)(?:\s|$)/i);
    const tone = (match?.[1] || '').toLowerCase();

    return CALLOUT_TONES.includes(tone) ? tone : null;
}

/**
 * `callout{tone}` from DocumentSchema. The tone label ("NOTE", "WARNING", …)
 * is drawn by the stylesheet's ::before, never stored as content.
 */
export const Callout = Node.create({
    name: 'callout',

    group: 'block',

    content: 'block+',

    defining: true,

    addAttributes() {
        return {
            tone: {
                default: 'note',
                parseHTML: (element) => {
                    const tone = (element.getAttribute('data-tone') || '').toLowerCase();
                    if (CALLOUT_TONES.includes(tone)) {
                        return tone;
                    }

                    return toneFromClass(element.getAttribute('class')) ?? 'note';
                },
                renderHTML: (attributes) => {
                    const tone = CALLOUT_TONES.includes(attributes.tone) ? attributes.tone : 'note';

                    return { 'data-tone': tone, class: `callout callout-${tone}` };
                },
            },
        };
    },

    parseHTML() {
        return [{ tag: 'aside.callout' }];
    },

    renderHTML({ HTMLAttributes }) {
        return ['aside', mergeAttributes(HTMLAttributes), 0];
    },

    addCommands() {
        return {
            setCallout:
                (tone = 'note') =>
                ({ commands }) =>
                    commands.wrapIn(this.name, {
                        tone: CALLOUT_TONES.includes(tone) ? tone : 'note',
                    }),
            toggleCallout:
                (tone = 'note') =>
                ({ editor, commands }) => {
                    const resolved = CALLOUT_TONES.includes(tone) ? tone : 'note';

                    if (editor.isActive(this.name, { tone: resolved })) {
                        return commands.lift(this.name);
                    }
                    if (editor.isActive(this.name)) {
                        return commands.updateAttributes(this.name, { tone: resolved });
                    }

                    return commands.wrapIn(this.name, { tone: resolved });
                },
        };
    },
});
