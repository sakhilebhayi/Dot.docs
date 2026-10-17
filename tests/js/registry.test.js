import assert from 'node:assert/strict';
import test from 'node:test';

import { loadEditorModule } from './moduleLoader.js';

/*
 * The command registry, measured as the LIST IT BUILDS rather than as source
 * text.
 *
 * `tests/Feature/Documents/ContextualToolbarTest.php` used to assert the
 * absence of a feature by grepping registry.js for `'ai.analyze'` — a string
 * that could never appear there, because the AI entries are generated as
 * `ai.${name}` from a tuple list. The assertion therefore could not fail, on
 * any tree, ever. The names only exist once the module has been evaluated, so
 * that is where they are checked now.
 */

const { commands, paletteCommands, insertableCommands, run } =
    await loadEditorModule('commands/registry.js');

const names = commands.map((command) => command.name);

test('every command has a unique name', () => {
    assert.equal(new Set(names).size, names.length, 'two commands share one name');
});

test('the registry names nothing the application cannot actually do', () => {
    /*
     * The owner brief asks for find/replace and insert-chart rows. Neither has
     * an implementation anywhere in the repo, and a palette row that does
     * nothing is worse than an absent one (spec §4), so neither is here — nor
     * is an `analyze` AI pass, which `AiAssistant` has no branch for.
     *
     * These are the names those features would take once built, matched
     * against the generated list: add the feature and the entry and the
     * assertion fails, which is the whole point of it.
     */
    for (const absent of [
        'chart',
        'insert.chart',
        'chart.insert',
        'find',
        'replace',
        'find.replace',
        'ai.analyze',
    ]) {
        assert.ok(
            !names.includes(absent),
            `'${absent}' is in the registry — either it has an implementation now (say so) or it is a dead row`,
        );
    }
});

test('the palette lists no row that needs an argument it cannot collect', () => {
    const listed = paletteCommands().map((command) => command.name);

    // `image.alt` needs the alt text; `image.remove` needs an image to be
    // selected. Chosen from ⌘K neither can do anything but refuse, silently,
    // so neither is offered there. The floating toolbar's image variant is
    // where both live, and it only exists when an image IS selected.
    for (const contextual of ['image.alt', 'image.remove']) {
        assert.ok(!listed.includes(contextual), `${contextual} is a dead palette row`);
        assert.ok(names.includes(contextual), `${contextual} left the registry altogether`);
    }

    // The flag is the mechanism, and nothing else carries it.
    assert.deepEqual(
        commands.filter((command) => command.contextual === true).map((command) => command.name),
        ['image.alt', 'image.remove'],
    );
});

test('every remaining palette row has somewhere to go', () => {
    const listed = paletteCommands().map((command) => command.name);

    // `style.switch` is listed BECAUSE it now has a destination: chosen with
    // no key it opens the document-style picker. That half lives in the page,
    // and is measured by ContextualToolbarTest::
    // test_every_host_command_the_registry_names_has_a_branch_in_the_page.
    for (const expected of [
        'style.switch',
        'search',
        'recent.open',
        'share',
        'comment',
        'export.pdf',
        'export.word',
        'export.html',
        'export.markdown',
        'ai.summarize',
        'table.addRow',
    ]) {
        assert.ok(listed.includes(expected), `the palette lost ${expected}`);
    }
});

test('the slash menu offers only things you can insert into a blank line', () => {
    const slash = insertableCommands().map((command) => command.name);

    // A `/` menu that lists "Delete this column" or "Export as PDF" is a menu
    // you have to read past.
    for (const absent of ['table.deleteColumn', 'image.alt', 'export.pdf', 'share', 'undo']) {
        assert.ok(!slash.includes(absent), `the slash menu offers ${absent}`);
    }

    for (const present of ['table', 'image', 'heading.1', 'callout.note', 'pageBreak']) {
        assert.ok(slash.includes(present), `the slash menu lost ${present}`);
    }
});

test('a read only editor is offered what reads: the exports, searching, opening another document, the assistant passes and commenting', () => {
    const listed = paletteCommands({ readOnly: true }).map((command) => command.name);

    // The assistant's passes are reads: AiAssistant asks only view access to run one, and only
    // Accept (update access) writes. The palette agrees with the server.
    assert.deepEqual(listed, [
        'export.pdf',
        'export.word',
        'export.html',
        'export.markdown',
        'search',
        'recent.open',
        'ai.summarize',
        'ai.rewrite',
        'ai.grammar',
        'ai.continue',
        'ai.outline',
        'comment',
    ]);
    // Nothing that writes, and nothing that sends the person to a page they may not open.
    for (const absent of ['paragraph', 'heading.1', 'table', 'undo', 'redo', 'share', 'style.switch']) {
        assert.ok(!listed.includes(absent), `a read only palette offers ${absent}`);
    }
    assert.ok(paletteCommands().length > listed.length, 'the ordinary palette is unchanged');
});

/**
 * A stand-in editor whose commands all SUCCEED: every chain method answers with the chain and run() answers true,
 * and each call is written down. Without the read only gate in run() these commands would run and answer true, so a
 * test of the gate cannot pass by accident of a command that fails on its own.
 */
function standInEditor({ isEditable }) {
    const calls = [];
    const chain = new Proxy(
        {},
        {
            get: (_, method) => (method === 'run' ? () => (calls.push('run'), true) : () => (calls.push(String(method)), chain)),
        },
    );

    return {
        calls,
        editor: {
            isDestroyed: false,
            isEditable,
            chain: () => chain,
            commands: new Proxy({}, { get: (_, method) => () => (calls.push(`commands.${String(method)}`), true) }),
            can: () => chain,
            isActive: () => false,
            getAttributes: () => ({}),
            dotdoc: { onCommand: (name) => calls.push(`host:${name}`), selection: {} },
            state: { selection: { empty: true, from: 0, to: 0 }, doc: { textBetween: () => '' } },
        },
    };
}

test('run() does nothing to a read only editor except the commands that read', () => {
    const readOnly = standInEditor({ isEditable: false });

    const writing = ['paragraph', 'heading.1', 'quote', 'code', 'list.bullet', 'undo', 'redo'];
    for (const name of writing) {
        assert.ok(names.includes(name), `${name} is a registry command (a name that is not one answers false for another reason)`);
        assert.equal(run(readOnly.editor, name), false, name);
    }
    assert.deepEqual(readOnly.calls, [], 'no command was run');

    assert.notEqual(run(readOnly.editor, 'export.pdf'), false);
    assert.notEqual(run(readOnly.editor, 'ai.summarize'), false);
    assert.deepEqual(readOnly.calls, ['host:export.pdf', 'host:ai']);

    // The same stand-in, editable, runs them: the gate is what stopped the calls above.
    const editable = standInEditor({ isEditable: true });
    for (const name of writing) {
        assert.equal(run(editable.editor, name), true, name);
    }
    assert.notDeepEqual(editable.calls, []);
});
