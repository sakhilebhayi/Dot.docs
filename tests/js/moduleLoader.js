/**
 * Load an editor module the way Vite loads it.
 *
 * Most of `resources/js/editor/` is dependency-free on purpose so `node --test`
 * can reach it (.ai/rules/editor.md). The two files that cannot be — the
 * command registry and the floating toolbar built on top of it — import their
 * neighbours with EXTENSIONLESS specifiers (`'../guards'`), which only a
 * bundler resolves, so a plain `import` of either dies with
 * ERR_MODULE_NOT_FOUND before a single case runs.
 *
 * Rather than rewrite every specifier in the bundle for the test runner's
 * sake, this registers one synchronous resolve hook that does what Vite does:
 * a relative specifier with no extension gets `.js` appended when that file
 * exists. Nothing in production changes; the rule lives entirely in the test
 * harness. (`module.registerHooks` is Node 22.15+/23.5+; this project runs
 * Node 24.)
 *
 * It is deliberately NOT named `*.test.js`: `npm test` globs that pattern and
 * this file holds no cases of its own.
 */

import { existsSync } from 'node:fs';
import { registerHooks } from 'node:module';
import { fileURLToPath } from 'node:url';

registerHooks({
    resolve(specifier, context, nextResolve) {
        if (specifier.startsWith('.') && !/\.[a-z]+$/i.test(specifier) && context.parentURL) {
            const withExtension = new URL(`${specifier}.js`, context.parentURL);

            if (existsSync(fileURLToPath(withExtension))) {
                return { url: withExtension.href, shortCircuit: true };
            }
        }

        return nextResolve(specifier, context);
    },
});

const EDITOR = new URL('../../resources/js/editor/', import.meta.url);

/**
 * @param {string} path relative to `resources/js/editor/`, e.g. `'ui/bubble.js'`
 * @returns {Promise<Record<string, unknown>>}
 */
export function loadEditorModule(path) {
    return import(new URL(path, EDITOR).href);
}
