import { Extension } from '@tiptap/core';

/**
 * DocumentSchema::empty() puts `schema`, `style` and `vars` on the `doc`
 * node. ProseMirror drops attributes a node type has not declared, so without
 * this extension every `editor.getJSON()` would strip them and the server
 * would silently re-stamp defaults on each save — churn in the version diffs
 * for something the writer never touched.
 */
export const DocAttrs = Extension.create({
    name: 'docAttrs',

    addGlobalAttributes() {
        return [
            {
                types: ['doc'],
                attributes: {
                    schema: { default: 1, rendered: false },
                    style: { default: 'report', rendered: false },
                    vars: { default: {}, rendered: false },
                },
            },
        ];
    },
});
