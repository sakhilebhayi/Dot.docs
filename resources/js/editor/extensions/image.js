import Image from '@tiptap/extension-image';
import { isValidImageSrc } from '../attrs';

/**
 * `image` from DocumentSchema, with the same `src` whitelist
 * App\Documents\Render\HtmlRenderer::isValidImageSrc() applies: an absolute
 * http(s) URL, or a path served by the app's own storage.
 *
 * Without it the editor happily renders a `javascript:` or `data:` src that
 * the server would refuse to print — the writer sees something that will not
 * survive export, and a pasted document can put a script URL in the DOM.
 */
export const DocImage = Image.extend({
    addAttributes() {
        return {
            ...this.parent?.(),
            src: {
                default: null,
                parseHTML: (element) => {
                    const src = element.getAttribute('src');

                    return isValidImageSrc(src) ? src : null;
                },
                renderHTML: (attributes) =>
                    isValidImageSrc(attributes.src) ? { src: attributes.src } : {},
            },
        };
    },
});
