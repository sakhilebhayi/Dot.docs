/**
 * How the parts of createSyncHost() (../host.js) call each other.
 *
 * Every part is a factory over the same `view` and `env`, and they all add
 * their functions to one object, `h`. A part calls another part's function
 * through `h`, looked up at the moment of the call, never when the part is
 * built: so the parts can call each other in any direction (the save queue
 * starts a merge, the merge sends a save) without importing each other, and
 * the order in which host.js builds them does not matter.
 *
 * @param {object} h the host being built
 * @param {string[]} names the other parts' functions this part calls
 * @returns {Record<string, (...args: unknown[]) => unknown>} a forwarder per name
 */
export function late(h, names) {
    const bound = {};
    for (const name of names) {
        bound[name] = (...args) => h[name](...args);
    }

    return bound;
}
