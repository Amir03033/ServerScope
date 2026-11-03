// Mission control on the dashboard: keeps the score, the tiles and the
// 24-hour timeline live (every 30 s), counts the score up or down, lets a
// tile that recovers glow briefly, and offers focus mode: only what needs
// attention, or "Nothing to do" (remembered in this browser). The page is
// rendered by the server; this only updates it.

import { numberLocale } from './i18n';
import { append, clear, h } from './nexus/dom.js';

const POLL_MS = 30_000;
const RING = 2 * Math.PI * 52;

function boot() {
    const root = document.querySelector('[data-mission]');
    const configElement = document.getElementById('mission-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // The mission widgets (health, tiles, timeline) can stand anywhere on the dashboard.
    const $ = (name) => document.querySelector(`[data-mission-${name}]`);
    const widgets = document.querySelector('[data-widgets]');
    const details = document.querySelector('[data-dashboard-details]');
    const detailsHead = document.querySelector('[data-dashboard-details-head]');
    const locale = numberLocale();
    let data = config.initial;
    let shownScore = data.score ?? 0;
    let focus = false;

    try { focus = localStorage.getItem('ss-mission-focus') === '1'; } catch { focus = false; }

    // ---------- score: count to the new value ----------
    function setScore(target) {
        const ring = $('ring');
        const number = $('score');

        if (target === null) {
            number.textContent = '—';
            ring.style.strokeDashoffset = String(RING);

            return;
        }

        const from = shownScore;
        const start = performance.now();
        const step = (now) => {
            const p = reducedMotion ? 1 : Math.min(1, (now - start) / 900);
            const eased = 1 - (1 - p) ** 3;
            const value = Math.round(from + (target - from) * eased);
            number.textContent = String(value);
            ring.style.strokeDashoffset = String(RING * (1 - value / 100));

            if (p < 1) requestAnimationFrame(step);
        };

        shownScore = target;
        requestAnimationFrame(step);
    }

    // ---------- tiles: update in place; a tile that recovers glows briefly ----------
    function renderTiles() {
        const box = $('tiles');
        const existing = new Map([...box.querySelectorAll('[data-key]')].map((a) => [a.dataset.key, a]));
        const keep = new Set();

        for (const tile of data.tiles) {
            keep.add(tile.key);
            let el = existing.get(tile.key);

            if (!el) {
                el = h('a', { class: 'ss-mtile is-new', href: tile.url, 'data-key': tile.key, title: tile.name },
                    h('span', { class: 'ss-mtile-dot', 'aria-hidden': 'true' }), h('span', { class: 'ss-mtile-name' }, tile.name), h('span', { class: 'ss-mtile-value' }));
                append(box, [el]);
            }

            const before = el.dataset.health;

            if (before && before !== tile.health && tile.health === 'ok' && !reducedMotion) {
                el.classList.remove('is-recovered');
                void el.offsetWidth; // restart the animation
                el.classList.add('is-recovered');
            }

            el.dataset.health = tile.health;
            el.querySelector('.ss-mtile-value').textContent = tile.value === null ? '' : tile.type === 'server' ? `${Math.round(tile.value)}%` : `${Math.round(tile.value).toLocaleString(locale)} ms`;
        }

        for (const [key, el] of existing) {
            if (!keep.has(key)) el.remove();
        }

        // Two rows at first (problems come first); the rest behind "+N more".
        const limit = Number(box.dataset.limit || 8);
        const order = data.tiles.map((tile) => tile.key);
        [...box.querySelectorAll('[data-key]')]
            .sort((a, b) => order.indexOf(a.dataset.key) - order.indexOf(b.dataset.key))
            .forEach((el, i) => { box.append(el); el.classList.toggle('is-extra', i >= limit); });

        const more = $('more');
        if (more) {
            more.hidden = data.tiles.length <= limit;
            more.dataset.more = more.dataset.more.replace(/\d+/, String(Math.max(0, data.tiles.length - limit)));
            more.textContent = box.classList.contains('is-all') ? more.dataset.less : more.dataset.more;
        }
    }

    $('more')?.addEventListener('click', () => {
        const box = $('tiles');
        const all = box.classList.toggle('is-all');
        $('more').setAttribute('aria-expanded', String(all));
        $('more').textContent = all ? $('more').dataset.less : $('more').dataset.more;
    });

    // ---------- timeline ----------
    function renderTimeline() {
        const box = $('timeline');
        const from = Date.parse(data.timeline.from);
        const span = Math.max(1, Date.parse(data.timeline.to) - from);
        const pos = (iso) => (iso ? Math.max(0, Math.min(100, ((Date.parse(iso) - from) / span) * 100)) : 100);
        const time = (iso) => new Date(iso).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });

        box.querySelectorAll('.ss-mevent').forEach((e) => e.remove());

        for (const e of data.timeline.events) {
            const left = pos(e.at);
            const width = e.kind === 'incident' ? Math.max(0.8, pos(e.until) - left) : 0;
            const label = config.texts.legend[e.kind];
            const el = h('a', { class: `ss-mevent is-${e.kind} tone-${e.tone}`, href: e.url ?? '#', title: `${label} · ${time(e.at)} · ${e.label}` }, h('span', { class: 'sr-only' }, `${label}: ${e.label}`));
            el.style.left = `${left}%`;
            el.style.width = `${width}%`;
            box.prepend(el);
        }

        const empty = root.querySelector('[data-mission-timeline-empty]');
        if (empty) empty.hidden = data.timeline.events.length > 0;
    }

    function renderText() {
        root.dataset.grade = data.grade;
        $('headline').textContent = data.headline;
        $('counts').textContent = config.texts.counts.replace(/:(\w+)/g, (_, k) => String(data.counts[k] ?? ''));
        clear($('issues'));
        append($('issues'), data.issues.map((i) => h('li', {}, h('a', { class: `ss-mission-issue is-${i.tone}`, href: i.url }, i.label))));
    }

    // ---------- focus: only what needs attention ----------
    function applyFocus() {
        // Focus: only the health widget and the tiles that need attention; "Nothing to do" when all is well.
        const calm = focus && data.issues.length === 0;
        root.classList.toggle('is-focus', focus);
        widgets?.classList.toggle('is-focus', focus);
        $('focus').setAttribute('aria-pressed', String(focus));
        $('calm').hidden = !calm;
        const tiles = $('tiles-block')?.closest('[data-widget]');
        if (tiles) tiles.classList.toggle('is-calm', calm);

        if (details) details.hidden = focus;
        if (detailsHead) detailsHead.hidden = focus;
    }

    $('focus').addEventListener('click', () => {
        focus = !focus;
        try { localStorage.setItem('ss-mission-focus', focus ? '1' : '0'); } catch { /* not remembered */ }
        applyFocus();
    });

    function apply(next) {
        data = next;
        setScore(data.score);
        renderText();
        renderTiles();
        renderTimeline();
        applyFocus();
    }

    async function refresh() {
        if (document.hidden) return;

        try {
            const response = await fetch(config.url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });

            if (response.ok) apply((await response.json()).data);
        } catch {
            // Keep the last picture; the next poll tries again.
        }
    }

    // First paint: the score counts up from 0 once.
    shownScore = 0;
    setScore(data.score);
    applyFocus();
    setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
