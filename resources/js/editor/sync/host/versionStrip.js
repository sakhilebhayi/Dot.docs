/**
 * The version figure in the status strip ([data-doc-version]). A successful
 * save no longer re-renders the page (EA-5 / P-03) and the view's fields are
 * written here without Alpine noticing, so the strip is painted directly every
 * time the base version moves. No document (the node tests): nothing to do.
 */
export function paintVersion(version) {
    if (typeof document === 'undefined') {
        return;
    }
    const text = 'v' + Number(version).toLocaleString('en');
    document.querySelectorAll('[data-doc-version]').forEach((el) => {
        el.textContent = text;
    });
}
