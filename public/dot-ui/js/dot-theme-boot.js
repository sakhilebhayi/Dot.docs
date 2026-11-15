/*
 * dot-ui theme boot (0.6.0): the first-paint half of the header's theme control. Load it SYNCHRONOUSLY in <head> (no `defer`, no `async`), before the
 * stylesheet or at least before <body>, so the page is already dark when it is first painted:
 *
 *   <html lang="en" class="dot-roboto" data-dh-theme-class="dark" data-dh-theme-key="my-theme" data-dh-theme-default="dark">
 *   <head> ... <script src="/dot-ui/js/dot-theme-boot.js"></script> ... </head>
 *
 * It reads the same options as js/dot-nav.js (which is `defer` and cannot do this part): data-dh-theme-class (also toggle this class), data-dh-theme-key
 * (storage key, default `dot-theme`), data-dh-theme-default (`light`, `dark` or `system`, what "no choice yet" means, default `system`), data-dh-theme-attr="false"
 * (leave data-theme alone). A stored choice is `light`, `dark` or `system`. External file, no inline script: it works under script-src 'self'.
 * After this, dot-nav.js keeps the attribute and class in step with the Light / Dark / System buttons and fires `dot:theme` ({ mode, dark }) on every change.
 */
(function () {
    var d = document.documentElement;
    var m = null;
    try { m = window.localStorage.getItem(d.getAttribute('data-dh-theme-key') || 'dot-theme'); } catch (e) { m = null; }
    if (m !== 'light' && m !== 'dark' && m !== 'system') { m = d.getAttribute('data-dh-theme-default') || 'system'; }
    var dark = m === 'dark' || (m !== 'light' && !!window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    if (d.getAttribute('data-dh-theme-attr') !== 'false') {
        if (m === 'light' || m === 'dark') { d.setAttribute('data-theme', m); } else { d.removeAttribute('data-theme'); }
    }
    var c = d.getAttribute('data-dh-theme-class');
    if (c) { d.classList.toggle(c, dark); }
})();
