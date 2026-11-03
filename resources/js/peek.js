// Side panel and hover cards. A plain click on a link to a server, website
// or incident (in the page, not the menu) opens a panel from the right with
// a short, read-only look; the panel links on to the full page. Holding the
// mouse over such a link shows a small card. Shift/Cmd/Ctrl-click, middle
// click and links with data-no-peek still open the page as before; without
// JavaScript every link simply opens its page.

import { t } from './i18n';

const PATTERNS = [
    [/^\/servers\/(\d+)$/, 'server'],
    [/^\/domains\/(\d+)$/, 'domain'],
    [/^\/incidents\/(\d+)$/, 'incident'],
];
const HOVER_DELAY = 450;
const CACHE_MS = 20_000;
const cache = new Map();

/** [type, id] of a link that can be peeked, or null. */
function target(link) {
    if (!link || link.closest('[data-no-peek], .ss-sidebar, .ss-peek, .ss-peek-card, form') || link.target || link.hasAttribute('download')) return null;

    const url = new URL(link.href, location.href);

    if (url.origin !== location.origin || url.search || url.hash) return null;

    for (const [pattern, type] of PATTERNS) {
        const m = url.pathname.match(pattern);
        if (m) return [type, m[1]];
    }

    return null;
}

async function load(base, type, id) {
    const key = `${type}:${id}`;
    const hit = cache.get(key);

    if (hit && Date.now() - hit.at < CACHE_MS) return hit.data;

    const response = await fetch(`${base}/${type}/${id}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

    if (!response.ok) throw new Error(String(response.status));

    const data = (await response.json()).data;
    cache.set(key, { at: Date.now(), data });

    return data;
}

// ---------- rendering (text only; nothing is parsed as HTML) ----------
function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;

    return node;
}

function spark(values, max) {
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 100 30');
    svg.setAttribute('preserveAspectRatio', 'none');
    svg.setAttribute('class', 'ss-peek-spark');
    svg.setAttribute('aria-hidden', 'true');
    const top = max ?? Math.max(...values, 1);
    const points = values.map((v, i) => `${((i / (values.length - 1)) * 100).toFixed(2)},${(28 - (v / top) * 26).toFixed(2)}`);
    const area = document.createElementNS(ns, 'path');
    area.setAttribute('class', 'area');
    area.setAttribute('d', `M${points.join(' L')} L100,30 L0,30 Z`);
    const line = document.createElementNS(ns, 'path');
    line.setAttribute('class', 'line');
    line.setAttribute('d', `M${points.join(' L')}`);
    svg.append(area, line);

    return svg;
}

function render(data, { compact = false } = {}) {
    const frag = document.createDocumentFragment();
    const head = el('div', 'ss-peek-head');
    const status = el('span', `ss-status ss-status-${data.status.tone}`, data.status.label);
    head.append(el('div', 'ss-peek-kind', t(`search.types`)?.[data.type] ?? ''), el('h2', 'ss-peek-title', data.title), status);
    if (data.subtitle) head.append(el('p', 'ss-peek-sub', data.subtitle));
    frag.append(head);

    const stats = el('dl', 'ss-peek-stats');
    for (const s of compact ? data.stats.slice(0, 3) : data.stats) {
        const box = el('div', s.tone ? `is-${s.tone}` : '');
        box.append(el('dt', '', s.label), el('dd', '', s.value));
        stats.append(box);
    }
    frag.append(stats);

    if (data.spark) {
        const wrap = el('div', 'ss-peek-chart');
        wrap.append(el('div', 'ss-peek-label', data.spark.label), spark(data.spark.values, data.spark.max));
        frag.append(wrap);
    }

    if (compact) return frag;

    if (data.text) frag.append(el('p', 'ss-peek-text', data.text));

    for (const section of data.sections) {
        const box = el('section', 'ss-peek-section');
        box.append(el('h3', 'ss-peek-label', section.title));
        const list = el('ul', 'ss-peek-list');

        for (const item of section.items) {
            const li = el('li', item.tone ? `is-${item.tone}` : '');
            const label = item.url ? el('a', 'ss-peek-link', item.label) : el('span', '', item.label);
            if (item.url) label.href = item.url;
            li.append(el('span', 'ss-peek-dot'), label);
            if (item.meta) li.append(el('span', 'ss-peek-meta', item.meta));
            list.append(li);
        }

        box.append(list);
        frag.append(box);
    }

    return frag;
}

function boot() {
    const base = document.querySelector('meta[name="ss-peek"]')?.content;

    if (!base) return;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // ---------- side panel ----------
    const panel = el('aside', 'ss-peek');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'false');
    panel.setAttribute('aria-label', t('peek.panel'));
    panel.hidden = true;
    const close = el('button', 'ss-icon-btn ss-peek-close');
    close.type = 'button';
    close.setAttribute('aria-label', t('peek.close'));
    close.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-x"/></svg>';
    const body = el('div', 'ss-peek-body');
    const foot = el('div', 'ss-peek-foot');
    const open = el('a', 'ss-btn ss-btn-primary', t('peek.open'));
    foot.append(open, el('p', 'ss-peek-hint', t('peek.hint')));
    panel.append(close, body, foot);
    const backdrop = el('div', 'ss-peek-backdrop');
    backdrop.hidden = true;
    document.body.append(backdrop, panel);
    let returnTo = null;
    let request = 0;

    function hidePanel() {
        if (panel.hidden) return;

        panel.classList.remove('is-open');
        backdrop.classList.remove('is-open');
        setTimeout(() => { panel.hidden = true; backdrop.hidden = true; }, reducedMotion ? 0 : 220);
        returnTo?.focus?.();
    }

    async function showPanel(link, type, id) {
        const mine = ++request;
        returnTo = link;
        open.href = link.href;
        body.replaceChildren(el('p', 'ss-peek-loading', t('peek.loading')));
        panel.hidden = false;
        backdrop.hidden = false;
        requestAnimationFrame(() => { panel.classList.add('is-open'); backdrop.classList.add('is-open'); });
        close.focus({ preventScroll: true });

        try {
            const data = await load(base, type, id);
            if (mine !== request) return;
            open.href = data.url;
            body.replaceChildren(render(data));
        } catch {
            if (mine === request) body.replaceChildren(el('p', 'ss-peek-loading', t('peek.failed')));
        }
    }

    close.addEventListener('click', hidePanel);
    backdrop.addEventListener('click', hidePanel);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !panel.hidden) hidePanel(); });

    document.addEventListener('click', (e) => {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;

        const link = e.target.closest?.('a[href]');
        const found = target(link);

        if (!found) return;

        e.preventDefault();
        hideCard();
        showPanel(link, ...found);
    });

    // ---------- hover cards (mouse only) ----------
    const card = el('div', 'ss-peek-card');
    card.setAttribute('role', 'tooltip');
    card.hidden = true;
    document.body.append(card);
    let timer = null;
    let current = null;

    function hideCard() {
        clearTimeout(timer);
        current = null;
        card.hidden = true;
    }

    function place(link) {
        const r = link.getBoundingClientRect();
        const w = card.offsetWidth;
        const h = card.offsetHeight;
        let left = Math.min(Math.max(8, r.left), window.innerWidth - w - 8);
        let top = r.bottom + 8;
        if (top + h > window.innerHeight - 8) top = Math.max(8, r.top - h - 8);
        card.style.left = `${left}px`;
        card.style.top = `${top}px`;
    }

    document.addEventListener('pointerover', (e) => {
        if (e.pointerType !== 'mouse') return;

        const link = e.target.closest?.('a[href]');
        const found = target(link);

        if (!found || link === current) return;

        hideCard();
        current = link;
        timer = setTimeout(async () => {
            try {
                const data = await load(base, ...found);
                if (current !== link || !panel.hidden && panel.contains(link)) return;
                card.replaceChildren(render(data, { compact: true }));
                card.hidden = false;
                place(link);
            } catch {
                // No card; the link still works.
            }
        }, HOVER_DELAY);
    });

    document.addEventListener('pointerout', (e) => {
        if (current && !current.contains(e.relatedTarget)) hideCard();
    });
    window.addEventListener('scroll', hideCard, { passive: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
