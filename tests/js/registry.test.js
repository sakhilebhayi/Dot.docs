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

const { commands, paletteCommands, insertableCommands } =
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
