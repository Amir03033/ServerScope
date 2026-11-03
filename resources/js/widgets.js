// Dashboard widgets: arrange your own dashboard. "Arrange" turns on edit
// mode: drag a widget by its handle (mouse, touch, or the arrow keys on the
// handle), choose its size (S, M, L), hide it, or start from a ready-made
// layout. Done saves it for you (only the view changes; every widget shows
// the same data as before). Moves animate (FLIP): widgets glide to their
// new place instead of jumping.

const SIZES = ['s', 'm', 'l'];

function boot() {
    const grid = document.querySelector('[data-widgets]');
    const editButton = document.querySelector('[data-widgets-edit]');

    if (!grid || !editButton) return;

    const texts = JSON.parse(grid.dataset.texts || '{}');
    const presets = JSON.parse(grid.dataset.presets || '[]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const widgets = () => [...grid.querySelectorAll(':scope > [data-widget]')];
    const byId = (id) => grid.querySelector(`:scope > [data-widget="${id}"]`);
    let editor = null;
    let snapshot = null;

    editButton.hidden = false;

    // ---------- layout <-> page ----------
    function current() {
        return {
            order: widgets().map((w) => w.dataset.widget),
            sizes: Object.fromEntries(widgets().filter((w) => w.dataset.size !== w.dataset.defaultSize).map((w) => [w.dataset.widget, w.dataset.size])),
            hidden: widgets().filter((w) => w.hidden).map((w) => w.dataset.widget),
        };
    }

    /** Animate every widget from where it was to where it is now (FLIP). */
    function flip(change) {
        const before = new Map(widgets().map((w) => [w, w.getBoundingClientRect()]));
        change();

        if (reducedMotion) return;

        for (const w of widgets()) {
            const a = before.get(w);
            if (!a || w.hidden || w.classList.contains('is-dragging')) continue;
            const b = w.getBoundingClientRect();
            const dx = a.left - b.left;
            const dy = a.top - b.top;
            if (Math.abs(dx) < 1 && Math.abs(dy) < 1) continue;
            w.animate([{ transform: `translate(${dx}px, ${dy}px)` }, { transform: 'none' }], { duration: 320, easing: 'cubic-bezier(.2, .8, .2, 1)' });
        }
    }

    function apply(layout) {
        flip(() => {
            for (const id of layout.order) {
                const w = byId(id);
                if (w) grid.append(w);
            }

            for (const w of widgets()) {
                w.dataset.size = layout.sizes?.[w.dataset.widget] ?? w.dataset.defaultSize;
                w.hidden = (layout.hidden ?? []).includes(w.dataset.widget);
            }
        });
        refreshEditor();
        // Charts in widgets that changed size or appeared draw again at their new size.
        setTimeout(() => window.dispatchEvent(new Event('resize')), 340);
    }

    // ---------- edit tools on every widget ----------
    function tools(w) {
        const bar = document.createElement('div');
        bar.className = 'ss-widget-tools';
        bar.innerHTML = `
            <button type="button" class="ss-widget-handle" data-handle><svg class="ico" aria-hidden="true"><use href="#i-grip"/></svg></button>
            <span class="ss-widget-name"></span>
            <span class="ss-widget-sizes" role="group"></span>
            <button type="button" class="ss-widget-icon-btn" data-hide><svg class="ico" aria-hidden="true"><use href="#i-eye-off"/></svg></button>`;
        const name = w.dataset.title;
        bar.querySelector('.ss-widget-name').textContent = name;
        const handle = bar.querySelector('[data-handle]');
        handle.setAttribute('aria-label', texts.move.replace(':name', name));
        handle.title = texts.move_hint;
        bar.querySelector('[data-hide]').setAttribute('aria-label', texts.hide.replace(':name', name));
        bar.querySelector('[data-hide]').title = texts.hide.replace(':name', name);
        const sizes = bar.querySelector('.ss-widget-sizes');
        sizes.setAttribute('aria-label', texts.size.replace(':name', name));

        for (const size of SIZES) {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = size.toUpperCase();
            b.title = texts.sizes[size];
            b.setAttribute('aria-pressed', String(w.dataset.size === size));
            b.addEventListener('click', () => {
                flip(() => { w.dataset.size = size; });
                sizes.querySelectorAll('button').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
                setTimeout(() => window.dispatchEvent(new Event('resize')), 340);
            });
            sizes.append(b);
        }

        bar.querySelector('[data-hide]').addEventListener('click', () => {
            flip(() => { w.hidden = true; });
            refreshEditor();
        });

        // Keyboard: arrow keys on the handle move the widget.
        handle.addEventListener('keydown', (e) => {
            const list = widgets().filter((x) => !x.hidden);
            const i = list.indexOf(w);

            if ((e.key === 'ArrowUp' || e.key === 'ArrowLeft') && i > 0) {
                e.preventDefault();
                flip(() => grid.insertBefore(w, list[i - 1]));
                handle.focus();
            } else if ((e.key === 'ArrowDown' || e.key === 'ArrowRight') && i < list.length - 1) {
                e.preventDefault();
                flip(() => grid.insertBefore(w, list[i + 1].nextSibling));
                handle.focus();
            }
        });

        handle.addEventListener('pointerdown', (e) => startDrag(e, w));

        return bar;
    }

    // ---------- dragging (mouse and touch) ----------
    function startDrag(e, w) {
        if (e.button !== 0) return;

        e.preventDefault();
        const rect = w.getBoundingClientRect();
        const offset = { x: e.clientX - rect.left, y: e.clientY - rect.top };
        const ghost = document.createElement('div');
        ghost.className = 'ss-widget-ghost';
        ghost.dataset.size = w.dataset.size;
        ghost.style.height = `${rect.height}px`;
        grid.insertBefore(ghost, w);
        Object.assign(w.style, { position: 'fixed', left: `${rect.left}px`, top: `${rect.top}px`, width: `${rect.width}px`, height: `${rect.height}px`, margin: '0' });
        w.classList.add('is-dragging');
        let scroll = 0;
        let lastMove = 0;

        let pointer = { x: e.clientX, y: e.clientY };

        // Where would it go: before or after the widget under the pointer.
        const place = (x, y) => {
            const target = widgets().find((other) => {
                if (other === w || other.hidden) return false;
                const r = other.getBoundingClientRect();

                return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
            });

            if (!target) return;

            const r = target.getBoundingClientRect();
            const sameRow = r.width < grid.clientWidth * 0.9;
            const after = sameRow ? x > r.left + r.width / 2 : y > r.top + r.height / 2;
            const anchor = after ? target.nextSibling : target;

            if (anchor !== ghost && anchor !== ghost.nextSibling) flip(() => grid.insertBefore(ghost, anchor));
        };

        const move = (ev) => {
            pointer = { x: ev.clientX, y: ev.clientY };
            w.style.left = `${ev.clientX - offset.x}px`;
            w.style.top = `${ev.clientY - offset.y}px`;
            scroll = ev.clientY < 80 ? -12 : ev.clientY > window.innerHeight - 80 ? 12 : 0;

            const now = performance.now();
            if (now - lastMove < 60) return;
            lastMove = now;
            place(ev.clientX, ev.clientY);
        };

        const tick = () => {
            if (!w.classList.contains('is-dragging')) return;
            if (scroll) window.scrollBy(0, scroll);
            requestAnimationFrame(tick);
        };
        requestAnimationFrame(tick);

        const end = (ev) => {
            // A quick drag may end between two checks: place it where it was let go.
            place(ev?.clientX ?? pointer.x, ev?.clientY ?? pointer.y);
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);
            const from = w.getBoundingClientRect();
            grid.insertBefore(w, ghost);
            ghost.remove();
            w.classList.remove('is-dragging');
            w.removeAttribute('style');

            if (!reducedMotion) {
                const to = w.getBoundingClientRect();
                w.animate([{ transform: `translate(${from.left - to.left}px, ${from.top - to.top}px)` }, { transform: 'none' }], { duration: 260, easing: 'cubic-bezier(.2, .8, .2, 1)' });
            }

            setTimeout(() => window.dispatchEvent(new Event('resize')), 280);
        };

        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);
        window.addEventListener('pointercancel', end);
    }

    // ---------- the editor panel ----------
    function refreshEditor() {
        if (!editor) return;

        const box = editor.querySelector('[data-hidden-list]');
        box.replaceChildren();
        const hidden = widgets().filter((w) => w.hidden);
        editor.querySelector('[data-hidden-row]').hidden = hidden.length === 0;

        for (const w of hidden) {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = `+ ${w.dataset.title}`;
            b.addEventListener('click', () => {
                flip(() => { w.hidden = false; });
                refreshEditor();
                w.scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' });
                setTimeout(() => window.dispatchEvent(new Event('resize')), 340);
            });
            box.append(b);
        }
    }

    async function save(body, method = 'PUT') {
        const response = await fetch(grid.dataset.layoutUrl, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            credentials: 'same-origin',
            body: method === 'DELETE' ? undefined : JSON.stringify(body),
        });

        if (!response.ok) throw new Error(String(response.status));

        return (await response.json()).layout;
    }

    function toast(text) {
        const t = document.createElement('div');
        t.className = 'ss-toast';
        t.setAttribute('role', 'status');
        t.textContent = text;
        document.body.append(t);
        setTimeout(() => t.classList.add('is-leaving'), 2200);
        setTimeout(() => t.remove(), 2600);
    }

    function openEditor() {
        snapshot = current();
        grid.classList.add('is-editing');
        editButton.setAttribute('aria-pressed', 'true');
        editButton.hidden = true;
        widgets().forEach((w) => w.prepend(tools(w)));

        editor = document.createElement('div');
        editor.className = 'ss-widgets-editor';
        editor.setAttribute('role', 'region');
        editor.setAttribute('aria-label', texts.title);
        editor.innerHTML = `
            <div class="ss-widgets-editor-row">
                <strong class="ss-widgets-editor-title"></strong>
                <label class="sr-only" for="widgets-preset"></label>
                <select id="widgets-preset" class="ss-input ss-tl-select" data-preset></select>
                <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-reset></button>
                <button type="button" class="ss-btn ss-btn-ghost ss-btn-sm" data-cancel></button>
                <button type="button" class="ss-btn ss-btn-primary ss-btn-sm" data-done></button>
            </div>
            <p class="ss-widgets-editor-hint m-0"></p>
            <div class="ss-widgets-editor-row" data-hidden-row><span class="ss-widgets-editor-hint"></span><div class="ss-widgets-hidden" data-hidden-list></div></div>`;
        editor.querySelector('.ss-widgets-editor-title').textContent = texts.title;
        editor.querySelector('label[for="widgets-preset"]').textContent = texts.preset;
        editor.querySelector('[data-reset]').textContent = texts.reset;
        editor.querySelector('[data-cancel]').textContent = texts.cancel;
        editor.querySelector('[data-done]').textContent = texts.done;
        editor.querySelector('p.ss-widgets-editor-hint').textContent = texts.hint;
        editor.querySelector('[data-hidden-row] .ss-widgets-editor-hint').textContent = texts.hidden;
        const select = editor.querySelector('[data-preset]');
        select.append(new Option(texts.preset, ''), ...presets.map((p) => new Option(texts.presets[p] ?? p, p)));

        select.addEventListener('change', async () => {
            if (!select.value) return;
            try {
                apply(await save({ preset: select.value }));
                widgets().forEach((w) => { w.querySelector('.ss-widget-tools')?.remove(); w.prepend(tools(w)); });
            } catch {
                toast(texts.failed);
            }
            select.value = '';
        });

        editor.querySelector('[data-reset]').addEventListener('click', async () => {
            try {
                apply(await save(null, 'DELETE'));
                widgets().forEach((w) => { w.querySelector('.ss-widget-tools')?.remove(); w.prepend(tools(w)); });
                toast(texts.reset_done);
            } catch {
                toast(texts.failed);
            }
        });

        editor.querySelector('[data-cancel]').addEventListener('click', () => {
            apply(snapshot);
            closeEditor();
        });

        editor.querySelector('[data-done]').addEventListener('click', async () => {
            try {
                await save(current());
                closeEditor();
                toast(texts.saved);
            } catch {
                toast(texts.failed);
            }
        });

        grid.parentNode.insertBefore(editor, grid);
        refreshEditor();
        editor.querySelector('[data-done]').focus({ preventScroll: true });
    }

    function closeEditor() {
        grid.classList.remove('is-editing');
        widgets().forEach((w) => w.querySelector('.ss-widget-tools')?.remove());
        editor?.remove();
        editor = null;
        editButton.hidden = false;
        editButton.setAttribute('aria-pressed', 'false');
        editButton.focus({ preventScroll: true });
    }

    editButton.addEventListener('click', () => (editor ? closeEditor() : openEditor()));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && editor && !document.querySelector('dialog[open]')) {
            apply(snapshot);
            closeEditor();
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
