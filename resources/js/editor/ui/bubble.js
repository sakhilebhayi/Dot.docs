import { run } from '../commands/registry';

/**
 * The selection bubble: the marks a writer reaches for mid-sentence, plus a
 * way to start a comment on the current block. Everything structural lives in
 * the palette and the slash menu instead.
 */
const BUTTONS = [
    { name: 'bold', label: 'B', title: 'Bold', className: 'is-bold' },
    { name: 'italic', label: 'I', title: 'Italic', className: 'is-italic' },
    { name: 'underline', label: 'U', title: 'Underline', className: 'is-underline' },
    { name: 'highlight', label: '▮', title: 'Highlight' },
];

/**
 * @returns {() => void} teardown
 */
export function installBubble(editor) {
    const dom = document.createElement('div');
    dom.className = 'dotdoc-bubble';
    dom.hidden = true;

    const row = document.createElement('div');
    row.className = 'dotdoc-bubble-row';
    dom.appendChild(row);

    const linkRow = document.createElement('form');
    linkRow.className = 'dotdoc-bubble-row dotdoc-bubble-link';
    linkRow.hidden = true;

    const linkInput = document.createElement('input');
    linkInput.type = 'url';
    linkInput.placeholder = 'https://…';
    linkInput.className = 'dotdoc-bubble-input';
    linkRow.appendChild(linkInput);

    const linkApply = document.createElement('button');
    linkApply.type = 'submit';
    linkApply.textContent = 'Link';
    linkApply.className = 'dotdoc-bubble-btn';
    linkRow.appendChild(linkApply);

    const linkClear = document.createElement('button');
    linkClear.type = 'button';
    linkClear.textContent = 'Unlink';
    linkClear.className = 'dotdoc-bubble-btn';
    linkClear.addEventListener('mousedown', (event) => {
        event.preventDefault();
        editor.chain().focus().unsetLink().run();
        closeLink();
    });
    linkRow.appendChild(linkClear);

    linkRow.addEventListener('submit', (event) => {
        event.preventDefault();
        const href = linkInput.value.trim();
        // HtmlRenderer only prints http(s) and mailto links; anything else
        // would be silently dropped server-side, so refuse it here too.
        if (!/^(https?:\/\/|mailto:)/i.test(href)) {
            linkInput.classList.add('is-invalid');

            return;
        }
        editor.chain().focus().setLink({ href }).run();
        closeLink();
    });

    dom.appendChild(linkRow);

    const markButtons = BUTTONS.map((button) => {
        const el = document.createElement('button');
        el.type = 'button';
        el.title = button.title;
        el.textContent = button.label;
        el.className = `dotdoc-bubble-btn${button.className ? ` ${button.className}` : ''}`;
        el.addEventListener('mousedown', (event) => {
            event.preventDefault();
            if (button.name === 'highlight') {
                run(editor, 'highlight');
            } else {
                editor.chain().focus().toggleMark(button.name).run();
            }
            paint();
        });
        row.appendChild(el);

        return { ...button, el };
    });

    const linkButton = document.createElement('button');
    linkButton.type = 'button';
    linkButton.title = 'Link';
    linkButton.textContent = '🔗';
    linkButton.className = 'dotdoc-bubble-btn';
    linkButton.addEventListener('mousedown', (event) => {
        event.preventDefault();
        linkRow.hidden = !linkRow.hidden;
        if (!linkRow.hidden) {
            linkInput.classList.remove('is-invalid');
            linkInput.value = editor.getAttributes('link').href || '';
            linkInput.focus();
        }
    });
    row.appendChild(linkButton);

    const commentButton = document.createElement('button');
    commentButton.type = 'button';
    commentButton.title = 'Comment';
    commentButton.textContent = '💬';
    commentButton.className = 'dotdoc-bubble-btn';
    commentButton.addEventListener('mousedown', (event) => {
        event.preventDefault();
        run(editor, 'comment');
    });
    row.appendChild(commentButton);

    document.body.appendChild(dom);

    function closeLink() {
        linkRow.hidden = true;
        linkInput.classList.remove('is-invalid');
    }

    function paint() {
        markButtons.forEach((button) => {
            button.el.classList.toggle('is-active', editor.isActive(button.name));
        });
        linkButton.classList.toggle('is-active', editor.isActive('link'));
    }

    function place() {
        const { state, view } = editor;
        const { from, to, empty } = state.selection;

        if (empty || !editor.isEditable || !view.hasFocus()) {
            if (linkRow.hidden) {
                dom.hidden = true;
            }

            return;
        }

        const start = view.coordsAtPos(from);
        const end = view.coordsAtPos(to, -1);

        dom.hidden = false;
        // Measure after unhiding, so offsetWidth/Height are real.
        const left = Math.max(8, (start.left + end.right) / 2 - dom.offsetWidth / 2);
        const top = Math.max(8, start.top - dom.offsetHeight - 8);

        dom.style.left = `${left + window.scrollX}px`;
        dom.style.top = `${top + window.scrollY}px`;
        paint();
    }

    const onSelection = () => place();
    const onBlur = () => {
        if (linkRow.hidden) {
            dom.hidden = true;
        }
    };

    editor.on('selectionUpdate', onSelection);
    editor.on('blur', onBlur);

    return () => {
        editor.off('selectionUpdate', onSelection);
        editor.off('blur', onBlur);
        dom.remove();
    };
}
