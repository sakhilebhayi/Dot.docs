/** The block that owns the selection — what the comment sidebar anchors to. */
export function selectionInfo(editor) {
    const { selection } = editor.state;
    const $from = selection.$from;

    if (selection.node?.attrs?.id) {
        return { blockId: selection.node.attrs.id, type: selection.node.type.name };
    }

    for (let depth = $from.depth; depth > 0; depth--) {
        const node = $from.node(depth);
        if (node.attrs?.id) {
            return { blockId: node.attrs.id, type: node.type.name };
        }
    }

    return { blockId: null, type: null };
}
