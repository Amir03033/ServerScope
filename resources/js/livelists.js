// Live lists: on the servers, websites, incidents and alerts lists the page
// refreshes itself in place every 30 seconds, without a reload. It fetches
// the same page (same filters, same sort) and updates only what changed:
// new rows slide in, changed rows light up (green when a status recovered),
// rows that left fade out, and the numbers in the summary count to their
// new value. The server renders everything as before; nothing new is
// fetched. It pauses while you type, select text, have a menu or dialog
// open or have rows ticked, and while the tab is hidden.

const INTERVAL_MS = 30_000;

const rowKey = (row, i) => row.dataset.key || row.querySelector('a[href]')?.getAttribute('href') || `#${i}`;
const textOf = (node) => node.textContent.replace(/\s+/g, ' ').trim();
const isGood = (row) => Boolean(row.querySelector('.ss-status-ok')) && !row.querySelector('.ss-status-bad, .ss-status-warn');
const isBad = (row) => Boolean(row.querySelector('.ss-status-bad, .ss-status-warn'));

function busy(main) {
    const active = document.activeElement;

    return document.hidden
        || document.querySelector('dialog[open]')
        || main.querySelector('[data-menu][open], details.ss-menu[open]')
        || (active && main.contains(active) && active.matches('input, select, textarea, [contenteditable]'))
        || (window.getSelection()?.toString() ?? '') !== ''
        || main.querySelector('tbody input[type="checkbox"]:checked');
}

function animate(node, className, reducedMotion) {
    if (reducedMotion) return;

    node.classList.remove(className);
    void node.offsetWidth; // restart the animation
    node.classList.add(className);
    node.addEventListener('animationend', () => node.classList.remove(className), { once: true });
}

/** Count a number in an element from its old to its new text (only plain numbers). */
function countTo(node, next, reducedMotion) {
    const from = Number(node.textContent.trim());
    const to = Number(next);

    if (!Number.isFinite(from) || !Number.isFinite(to) || reducedMotion) {
        node.textContent = next;

        return;
    }

    const start = performance.now();
    const step = (now) => {
        const p = Math.min(1, (now - start) / 700);
        node.textContent = String(Math.round(from + (to - from) * (1 - (1 - p) ** 3)));
        if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
    animate(node.closest('.ss-stat') ?? node, 'is-bump', false);
}

function updateTable(oldBody, newBody, reducedMotion) {
    const oldRows = new Map([...oldBody.rows].map((row, i) => [rowKey(row, i), row]));
    const wanted = [];

    [...newBody.rows].forEach((fresh, i) => {
        const key = rowKey(fresh, i);
        const old = oldRows.get(key);

        if (!old) {
            const row = document.importNode(fresh, true);
            wanted.push(row);
            animate(row, 'is-new', reducedMotion);

            return;
        }

        oldRows.delete(key);

        if (textOf(old) === textOf(fresh) && old.className === fresh.className) {
            wanted.push(old);

            return;
        }

        const row = document.importNode(fresh, true);
        // Green when it recovered, red when it just failed, a soft light otherwise.
        const effect = isBad(old) && isGood(fresh) ? 'is-recovered' : isGood(old) && isBad(fresh) ? 'is-alarm' : 'is-changed';
        wanted.push(row);
        old.replaceWith(row);
        animate(row, effect, reducedMotion);
    });

    // Rows that left: fade out, then go.
    for (const row of oldRows.values()) {
        if (reducedMotion) {
            row.remove();
        } else {
            row.classList.add('is-gone');
            setTimeout(() => row.remove(), 450);
        }
    }

    // Put every row in the new order (moving a node keeps it as it is).
    wanted.forEach((row) => oldBody.append(row));
    for (const row of oldRows.values()) if (row.isConnected) oldBody.append(row);
}

function boot() {
    const meta = document.querySelector('meta[name="ss-live-list"]');
    const main = document.querySelector('main');

    if (!meta || !main) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    let running = false;

    async function refresh() {
        if (running || busy(main)) return;

        running = true;

        try {
            const response = await fetch(location.href, { headers: { Accept: 'text/html' }, credentials: 'same-origin', cache: 'no-store' });

            if (!response.ok || !response.headers.get('content-type')?.includes('text/html')) return;

            const fresh = new DOMParser().parseFromString(await response.text(), 'text/html').querySelector('main');

            // Something may have started in the meantime (a menu, typing): try again later.
            if (!fresh || busy(main)) return;

            // Tables: row by row.
            const oldTables = [...main.querySelectorAll('table.ss-table')];
            const newTables = [...fresh.querySelectorAll('table.ss-table')];

            if (oldTables.length === newTables.length) {
                oldTables.forEach((table, i) => {
                    const a = table.tBodies[0];
                    const b = newTables[i].tBodies[0];
                    if (a && b) updateTable(a, b, reducedMotion);
                });
            }

            // Summary numbers and tab counts: count to the new value.
            const oldNumbers = [...main.querySelectorAll('.ss-stat-value, .ss-segmented .ss-count, [data-live-number]')];
            const newNumbers = [...fresh.querySelectorAll('.ss-stat-value, .ss-segmented .ss-count, [data-live-number]')];

            if (oldNumbers.length === newNumbers.length) {
                oldNumbers.forEach((node, i) => {
                    const next = newNumbers[i].textContent.trim();
                    if (node.textContent.trim() !== next) countTo(node, next, reducedMotion);
                });
            }
        } catch {
            // Keep the page as it is; the next round tries again.
        } finally {
            running = false;
        }
    }

    setInterval(refresh, Number(meta.content) * 1000 || INTERVAL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
