import { TextStyle } from '@tiptap/extension-text-style';
import { normaliseColor } from '../attrs';

/**
 * The hex out of a raw `style` attribute. `element.style.color` is read back
 * normalised (`rgb(255, 0, 0)`), which would never match the hex whitelist,
 * so the attribute text is matched directly.
 */
function hexFromStyleAttribute(element) {
    const style = element.getAttribute?.('style') || '';
    const match = style.match(/(?:^|;)\s*color\s*:\s*(#[0-9a-fA-F]{3,8})\s*(?:;|$)/);

    return match ? normaliseColor(match[1]) : null;
}

/**
 * `textStyle` from DocumentSchema::MARKS.
 *
 * The mark MUST be registered even though nothing in the editor writes it
 * yet: DocumentSchema accepts it, HtmlRenderer::wrapTextStyle() renders it,
 * and an importer or a future colour picker can put it in the JSON. Without
 * the mark here, opening such a document hits TipTap's content check, which
 * (unchecked) hands back an EMPTY doc — and the next keystroke saves that
 * emptiness over the real content.
 *
 * Only `color` is kept, and only as a hex value, because that is exactly
 * what HtmlRenderer::wrapTextStyle() will print; an arbitrary string here
 * would be interpolated into a `style` attribute.
 */
export const DocTextStyle = TextStyle.extend({
    addAttributes() {
        return {
            color: {
                default: null,
                parseHTML: (element) => hexFromStyleAttribute(element),
                renderHTML: (attributes) => {
                    const color = normaliseColor(attributes.color);

                    return color ? { style: `color: ${color}` } : {};
                },
            },
        };
    },
});
