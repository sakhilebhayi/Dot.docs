/**
 * The paragraph-level merge: three documents in, one document out.
 *
 * `base` is the last server document this tab held (the document of the
 * version its typing is based on), `mine` is what the editor holds now and
 * `theirs` is the newer server document that arrived. Every top-level block
 * is matched by the id the schema already gives it, and decided on its own
 * (section 4.3 of the Phase 2 spec):
 *
 *   - a block only one side changed takes that side's version;
 *   - a block both sides changed takes THEIRS, and mine is offered back
 *     (owner decision D1: the later save loses that block only);
 *   - a block they deleted while I typed in it goes, and my words are
 *     offered back with the ids that preceded it, so Put it back can return
 *     it to where it was (D2);
 *   - a block I deleted that they edited goes too, and the result says so
 *     (`overwrites`), so the save that carries it keeps their version in
 *     the history first. A draft merge turns that rule off (`deletionsWin`
 *     false): a days-old draft's deletion does not beat a change made since.
 *
 * Nothing is compared as JSON. Each input is parsed twice through the
 * schema: a COMPARE copy with the server-stamped attributes blanked
 * (`toc.entries`, `crossRef.label`) and `[]` levelled to `{}` (a section
 * break's `setup`, the document's `vars`), compared with `Node.eq()`, so
 * `align: null` against a missing `align` and a re-stamped contents list
 * are not changes; and an OUTPUT copy with the stamps kept, which is what
 * goes on screen. The merged document is built from output nodes and keeps
 * every block's own id, so no two TOP-LEVEL blocks share one: it passes the
 * server's "Duplicate block id" check by construction for those. Ids are
 * checked at the top level only. A nested id can come out twice (a draft
 * merge that moves p1 into a box while theirs edited p1 keeps both copies);
 * the editor's block-id repair plugin gives one of them a fresh id when the
 * merged document is applied (spec section 13).
 *
 * `echo` is the document of a save this tab sent whose answer was lost
 * (section 5.6). Wherever theirs agrees with it, that block's base is
 * theirs: the change is this tab's own stored save, not somebody else's,
 * so typing on top of it is "mine changed" and kept, not a D1.
 *
 * The order of the result is theirs (the spine), unless only I moved
 * blocks, in which case it is mine. A block one side inserted is anchored
 * after the nearest preceding block of its own document that is on the
 * page; with no predecessor at all it goes first, and with every
 * predecessor gone it goes last (a typist's new paragraph lands after an
 * imported document, not before its title).
 *
 * TipTap's TrailingNode gives this editor an empty paragraph after a
 * document that does not end in one; that paragraph is this editor's own.
 * When mine ends in such a paragraph that theirs lacks, it is carried into
 * BOTH the merged document and `theirs`, so a merge in which nothing of mine
 * survived still leaves `confirmed` equal to what the editor serialises.
 *
 * Dependency-free: `tests/js/sync.merge.test.js` runs it under `node --test`
 * with prosemirror-model's Schema. It imports nothing, not even derived.js:
 * the DERIVED table is duplicated here and `tests/js/derived.test.js`
 * asserts the two are identical.
 */

/** The attributes the server stamps into a stored document (derived.js has the same table). */
export const DERIVED = {
    toc: ['entries'],
    crossRef: ['label'],
};

/** What a blanked stamp reads as: a NEW value every time, so no caller can change what another got. */
const blankStamp = (key) => (key === 'entries' ? [] : null);

/** How many characters of a lost block the notice shows. */
export const EXCERPT_LENGTH = 80;

/** How many preceding ids a lost block remembers, nearest first. */
export const ANCHOR_LIMIT = 5;

/** The word the notice uses for each block type (section 6.2). */
const WORDS = {
    paragraph: 'paragraph',
    heading: 'heading',
    bulletList: 'list',
    orderedList: 'list',
    taskList: 'task list',
    blockquote: 'quote',
    codeBlock: 'code block',
    horizontalRule: 'rule',
    image: 'image',
    figure: 'figure',
    table: 'table',
    toc: 'contents list',
    pageBreak: 'page break',
    sectionBreak: 'section break',
    callout: 'callout',
    columns: 'columns',
};

/**
 * @param {string} type a node type name
 * @returns {string}
 */
export function blockWord(type) {
    return WORDS[type] ?? 'block';
}

const isEmptyValue = (value) => value !== null && typeof value === 'object' && Object.keys(value).length === 0;
const copy = (json) => JSON.parse(JSON.stringify(json));

/**
 * `[]` to `{}` where PHP sends an empty list for the editor's empty object:
 * a section break's `setup` and the document's `vars`. A deep copy.
 *
 * @param {object} json a Dot.Doc document
 * @returns {object}
 */
export function levelEmpty(json) {
    const out = copy(json);
    if (out && out.attrs && isEmptyValue(out.attrs.vars)) {
        out.attrs.vars = {};
    }
    const walk = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        if (node.type === 'sectionBreak' && node.attrs && isEmptyValue(node.attrs.setup)) {
            node.attrs.setup = {};
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(walk);
        }
    };
    walk(out);

    return out;
}

/**
 * Blank the server-stamped attributes (DERIVED), so a document the server
 * re-stamped compares equal to the editor's copy. A deep copy.
 *
 * @param {object} json a Dot.Doc document
 * @returns {object}
 */
export function levelDerived(json) {
    const out = copy(json);
    const walk = (node) => {
        if (!node || typeof node !== 'object') {
            return;
        }
        const derived = DERIVED[node.type];
        if (derived && node.attrs) {
            derived.forEach((key) => {
                node.attrs[key] = blankStamp(key);
            });
        }
        if (Array.isArray(node.content)) {
            node.content.forEach(walk);
        }
    };
    walk(out);

    return out;
}

/**
 * Whether a block is empty padding: a paragraph or heading with nothing in
 * it. `content.size`, never `textContent`: a paragraph holding only a
 * cross-reference or a variable chip has no text and is not empty.
 *
 * @param {import('prosemirror-model').Node} node
 * @returns {boolean}
 */
export function isEmptyBlock(node) {
    return (node.type.name === 'paragraph' || node.type.name === 'heading') && node.content.size === 0;
}

/**
 * What the notice shows of a lost block.
 *
 * @param {import('prosemirror-model').Node} node
 * @returns {string}
 */
export function excerpt(node) {
    const text = node.textContent;
    if (text === '') {
        return `[${blockWord(node.type.name)}]`;
    }

    // By code point, never by UTF-16 unit: a cut inside a surrogate pair leaves a broken character in the notice.
    const characters = Array.from(text);

    return characters.length > EXCERPT_LENGTH ? `${characters.slice(0, EXCERPT_LENGTH).join('')}…` : text;
}

/**
 * One entry of the lost row (section 6.1), from one loss of a merge.
 *
 * @param {{id: string, type: string, reason: 'both'|'deleted', json: object, anchors: string[], text: string}} loss
 * @param {{source: 'merge'|'draft'|'suggestion'|'parked', version: number, n: number, sentence: string}} context
 */
export function lostEntry(loss, { source, version, n, sentence }) {
    return {
        key: `${loss.id}:${version}:${n}`,
        id: loss.id,
        type: loss.type,
        reason: loss.reason,
        source,
        json: loss.json,
        anchors: loss.anchors,
        version,
        sentence,
        excerpt: loss.text,
    };
}

/**
 * The lost list with `entries` appended. Append, never replace: a paragraph
 * lost twice in a row (both versions of a D1 ping-pong) is two entries. The
 * only entry skipped is an exact duplicate, the same id with identical JSON.
 *
 * @param {object[]} list
 * @param {object[]} entries
 * @returns {object[]} a new array
 */
export function appendLost(list, entries) {
    const out = [...list];
    entries.forEach((entry) => {
        const json = JSON.stringify(entry.json);
        if (out.some((held) => held.id === entry.id && JSON.stringify(held.json) === json)) {
            return;
        }
        out.push(entry);
    });

    return out;
}

/**
 * One input, parsed twice: `doc` is the output copy, `blocks` its top-level
 * children with their compare twins. Null when a top-level block has no id
 * or two share one; a throw from the schema propagates to the caller.
 */
function read(schema, json) {
    const out = schema.nodeFromJSON(levelEmpty(json));
    out.check();
    const cmp = schema.nodeFromJSON(levelDerived(levelEmpty(json)));
    const blocks = [];
    const byId = new Map();
    for (let index = 0; index < out.childCount; index += 1) {
        const id = out.child(index).attrs.id;
        if (id === null || id === undefined || byId.has(id)) {
            return null;
        }
        const block = { id, cmp: cmp.child(index), out: out.child(index), index };
        blocks.push(block);
        byId.set(id, block);
    }

    return { doc: out, blocks, byId };
}

/** Whether the blocks common to x and y are in a different order in x than in y. */
function orderChanged(x, y) {
    const xs = x.blocks.filter((block) => y.byId.has(block.id)).map((block) => block.id);
    const ys = y.blocks.filter((block) => x.byId.has(block.id)).map((block) => block.id);

    return xs.length !== ys.length || xs.some((id, index) => id !== ys[index]);
}

const START = Symbol('start');
const END = Symbol('end');

/**
 * @param {import('prosemirror-model').Schema} schema
 * @param {{base: object, mine: object, theirs: object, echo?: object|null}} documents
 * @param {{deletionsWin?: boolean}} options
 * @returns {{merged: object, theirs: string, changed: boolean, lost: object[], overwrites: boolean}|null}
 *          null when the merge cannot be done (the caller shows the conflict notice)
 */
export function mergeDocuments(schema, { base, mine, theirs, echo = null }, { deletionsWin = true } = {}) {
    let B;
    let M;
    let T;
    let E = null;
    try {
        B = read(schema, base);
        M = read(schema, mine);
        T = read(schema, theirs);
        if (echo !== null && echo !== undefined) {
            E = read(schema, echo);
        }
    } catch (_) {
        return null;
    }
    if (B === null || M === null || T === null || (echo !== null && echo !== undefined && E === null)) {
        return null;
    }

    // 4.2 The base of a block is theirs wherever theirs agrees with the echo.
    const baseFor = (id) => {
        if (E !== null) {
            const t = T.byId.get(id);
            const e = E.byId.get(id);
            if ((!t && !e) || (t && e && t.cmp.eq(e.cmp))) {
                return t ?? null;
            }
        }

        return B.byId.get(id) ?? null;
    };

    // 4.4 This editor's own trailing paragraph.
    const skip = new Set();
    let theirsOut = T.doc;
    let carried = null;
    const mineLast = M.blocks[M.blocks.length - 1];
    const theirsLast = T.blocks[T.blocks.length - 1];
    if (
        mineLast &&
        mineLast.cmp.type.name === 'paragraph' &&
        mineLast.cmp.content.size === 0 &&
        !T.byId.has(mineLast.id) &&
        theirsLast
    ) {
        if (theirsLast.cmp.type.name !== 'paragraph') {
            carried = mineLast;
            skip.add(mineLast.id);
            theirsOut = T.doc.copy(T.doc.content.addToEnd(mineLast.out));
        } else if (theirsLast.cmp.content.size === 0) {
            skip.add(mineLast.id);
        }
    }

    // 4.3 One decision per block, over the union of the three id sets.
    const ids = [];
    const seen = new Set();
    [B, M, T].forEach((document) =>
        document.blocks.forEach((block) => {
            if (!seen.has(block.id) && !skip.has(block.id)) {
                seen.add(block.id);
                ids.push(block.id);
            }
        })
    );

    const emit = new Map();
    const lost = [];
    let overwrites = false;
    const eq = (x, y) => x.cmp.eq(y.cmp);
    const precedingIds = (block) =>
        M.blocks
            .slice(Math.max(0, block.index - ANCHOR_LIMIT), block.index)
            .map((candidate) => candidate.id)
            .reverse();
    const lose = (block, reason, anchors) =>
        lost.push({
            id: block.id,
            type: block.out.type.name,
            reason,
            // A plain copy: Node.toJSON() builds attrs with Object.create(null),
            // and the entry goes into IndexedDB, Alpine and strict deepEqual.
            json: copy(block.out.toJSON()),
            anchors,
            text: excerpt(block.out),
        });

    ids.forEach((id) => {
        const b = baseFor(id);
        const m = M.byId.get(id) ?? null;
        const t = T.byId.get(id) ?? null;

        if (b && m && t) {
            if (eq(m, b)) {
                emit.set(id, t.out); // a1 unchanged, a2 theirs changed
            } else if (eq(t, b)) {
                emit.set(id, m.out); // a3 mine changed
            } else if (eq(m, t)) {
                emit.set(id, t.out); // a4 the same change
            } else {
                emit.set(id, t.out); // a5 both changed: D1
                lose(m, 'both', [id]);
            }

            return;
        }

        if (b && m && !t) {
            // b1 they deleted, I did not touch it; b2 they deleted while I typed: D2.
            if (!eq(m, b) && !isEmptyBlock(m.cmp)) {
                lose(m, 'deleted', precedingIds(m));
            }

            return;
        }

        if (b && !m && t) {
            // c1 I deleted, they did not touch it; c2 I deleted, they edited.
            if (eq(t, b)) {
                return;
            }
            if (deletionsWin) {
                overwrites = true;
            } else {
                emit.set(id, t.out);
            }

            return;
        }

        if (!b && m && !t) {
            emit.set(id, m.out); // d mine inserted

            return;
        }

        if (!b && !m && t) {
            emit.set(id, t.out); // e theirs inserted

            return;
        }

        if (!b && m && t) {
            emit.set(id, t.out); // f both inserted one id
            if (!eq(m, t)) {
                lose(m, 'both', [id]);
            }
        }
        // g both deleted: nothing.
    });

    // 4.5 The spine and the anchors.
    const isOut = (id) => emit.has(id);
    const spine = orderChanged(M, B) && !orderChanged(T, B) ? M : T;
    const other = spine === M ? T : M;
    const spineIds = new Set(spine.blocks.map((block) => block.id));
    const anchorOf = new Map();
    const groups = new Map();
    other.blocks.forEach((block, index) => {
        if (spineIds.has(block.id) || !isOut(block.id)) {
            return;
        }
        let anchor = index === 0 ? START : END;
        for (let before = index - 1; before >= 0; before -= 1) {
            const previous = other.blocks[before];
            if (!isOut(previous.id)) {
                continue;
            }
            anchor = spineIds.has(previous.id) ? previous.id : anchorOf.get(previous.id);
            break;
        }
        anchorOf.set(block.id, anchor);
        if (!groups.has(anchor)) {
            groups.set(anchor, []);
        }
        groups.get(anchor).push(block.id);
    });

    const output = [];
    const push = (id) => output.push(emit.get(id));
    (groups.get(START) ?? []).forEach(push);
    spine.blocks.forEach((block) => {
        if (!isOut(block.id)) {
            return;
        }
        push(block.id);
        (groups.get(block.id) ?? []).forEach(push);
    });
    (groups.get(END) ?? []).forEach(push);
    if (carried !== null) {
        output.push(carried.out);
    }

    // Nothing left: each side deleted what the other kept. The document is
    // `block+`, so the result is one empty paragraph, under the id of mine's
    // last block (every id of the union is gone from the output, so none is
    // duplicated). Without it the merge was impossible and two deletions,
    // in which nothing was lost, ended in the notice.
    if (output.length === 0 && schema.nodes.paragraph && M.blocks.length > 0) {
        output.push(schema.nodes.paragraph.create({ id: M.blocks[M.blocks.length - 1].id }));
    }

    // 4.6 Document attributes, per key: mine where mine changed it, else theirs.
    const same = (x, y) => JSON.stringify(x ?? null) === JSON.stringify(y ?? null);
    const keys = [...new Set([...Object.keys(T.doc.attrs), ...Object.keys(M.doc.attrs)])];
    const attrs = {};
    keys.forEach((key) => {
        attrs[key] = same(M.doc.attrs[key], B.doc.attrs[key]) ? T.doc.attrs[key] : M.doc.attrs[key];
    });
    const attrsDiffer = keys.some((key) => !same(attrs[key], T.doc.attrs[key]));

    let merged;
    try {
        merged = schema.topNodeType.create(attrs, output);
        merged.check();
    } catch (_) {
        return null;
    }

    return {
        merged: merged.toJSON(),
        theirs: JSON.stringify(theirsOut.toJSON()),
        changed: !merged.content.eq(theirsOut.content) || attrsDiffer,
        lost,
        overwrites,
    };
}
