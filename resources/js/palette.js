// Command palette (Ctrl/⌘ K). One field for three things:
// - commands: go to any page, quick filters ("servers offline", "slow
//   websites"), view actions (theme, focus). They navigate or change the
//   view only; nothing changes data;
// - search: servers, websites, incidents and deployments (the JSON answer
//   of /search, as before);
// - recently viewed servers, websites and incidents (this browser only).
// The selected server, website or incident gets a preview on the right
// (the same quick look as the side panel). Without JavaScript the search
// triggers are plain links to /search.

import { t } from './i18n';
import { peek, render as renderPeek, target as peekTarget } from './peek';

const RECENT_KEY = 'ss-recent';
const RECENT_MAX = 6;

function recent() {
    try {
        return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]').filter((r) => r && r.url && r.title);
    } catch {
        return [];
    }
}

/** Remember the server, website or incident page that is open now. */
function rememberThisPage() {
    const found = peekTarget(location.pathname);

    if (!found) return;

    const title = document.title.split(' — ')[0].replace(/^\(\d+\+?\)\s/, '').trim();
    const list = [{ type: found[0], id: found[1], title, url: location.pathname }, ...recent().filter((r) => r.url !== location.pathname)].slice(0, RECENT_MAX);

    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list)); } catch { /* not remembered */ }
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined && text !== null) node.textContent = text;

    return node;
}

function icon(name) {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'ico');
    svg.setAttribute('aria-hidden', 'true');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `#${name}`);
    svg.append(use);

    return svg;
}

function highlight(text, q) {
    const span = el('span');
    const index = q ? text.toLowerCase().indexOf(q.toLowerCase()) : -1;

    if (index < 0) {
        span.textContent = text;

        return span;
    }

    span.append(text.slice(0, index), el('mark', '', text.slice(index, index + q.length)), text.slice(index + q.length));

    return span;
}

function boot() {
    const dialog = document.querySelector('[data-search-dialog]');
    const configElement = document.getElementById('palette-config');

    rememberThisPage();

    if (!dialog || typeof dialog.showModal !== 'function' || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const tx = (key, replace = {}) => {
        const text = key.split('.').reduce((v, part) => (v && typeof v === 'object' ? v[part] : undefined), config.texts);

        return Object.entries(replace).reduce((s, [k, v]) => s.replaceAll(`:${k}`, String(v)), typeof text === 'string' ? text : key);
    };
    const types = (() => {
        try {
            return JSON.parse(document.getElementById('ss-i18n')?.textContent || '{}')['search.types'] || {};
        } catch {
            return {};
        }
    })();
    const input = dialog.querySelector('input[name="q"]');
    const list = dialog.querySelector('[data-search-results]');
    const status = dialog.querySelector('[data-search-status]');
    const preview = dialog.querySelector('[data-palette-preview]');
    let options = [];
    let active = -1;
    let timer = null;
    let controller = null;
    let previewTimer = null;
    let searchResults = [];

    // ---------- what to show ----------
    function matchCommands(q) {
        const tokens = q.toLowerCase().split(/\s+/).filter(Boolean);

        return config.commands
            .map((c) => {
                const hay = `${c.label} ${c.keywords}`.toLowerCase();
                const hit = tokens.every((token) => hay.includes(token));
                const score = c.label.toLowerCase().startsWith(tokens[0] ?? '') ? 0 : 1;

                return hit ? { c, score } : null;
            })
            .filter(Boolean)
            .sort((a, b) => a.score - b.score)
            .map(({ c }) => c);
    }

    function build(q) {
        const groups = [];
        const command = (c) => ({ label: c.label, sub: c.url ? new URL(c.url, location.href).pathname : tx('view_only'), icon: c.icon, url: c.url ?? null, action: c.action ?? null, peek: null, kind: 'command' });

        if (!q) {
            const seen = recent();
            if (seen.length) groups.push([tx('groups.recent'), seen.map((r) => ({ label: r.title, sub: types[r.type] ?? r.type, badge: types[r.type] ?? r.type, url: r.url, peek: [r.type, r.id], kind: 'recent' }))]);
            groups.push([tx('groups.filter'), config.commands.filter((c) => c.group === 'filter').map(command)]);
            groups.push([tx('groups.view'), config.commands.filter((c) => c.group === 'view' || c.group === 'do').map(command)]);
            groups.push([tx('groups.go'), config.commands.filter((c) => c.group === 'go').map(command)]);

            return groups;
        }

        const commands = matchCommands(q).slice(0, 6).map(command);
        if (commands.length) groups.push([tx('groups.commands'), commands]);

        let group = null;
        let items = [];
        for (const r of searchResults) {
            if (r.type !== group) {
                if (items.length) groups.push([types[group] ?? group, items]);
                group = r.type;
                items = [];
            }
            items.push({ label: r.title, sub: r.subtitle, badge: types[r.type] ?? r.type, state: r.status_label, url: r.url, peek: peekTarget(r.url), kind: 'result' });
        }
        if (items.length) groups.push([types[group] ?? group, items]);

        return groups;
    }

    function render(q) {
        list.replaceChildren();
        options = [];
        active = -1;

        for (const [title, items] of build(q)) {
            if (!items.length) continue;

            const heading = el('li', 'ss-palette-group', title);
            heading.setAttribute('role', 'presentation');
            list.append(heading);

            for (const item of items) {
                const index = options.length;
                const li = el('li', 'ss-result ss-palette-item');
                li.id = `quick-search-${index}`;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', 'false');
                const lead = item.icon ? el('span', 'ss-palette-icon') : el('span', 'ss-badge shrink-0', item.badge);
                if (item.icon) lead.append(icon(item.icon));
                const text = el('span', 'min-w-0 flex-1');
                const label = highlight(item.label, q);
                label.className = 'block truncate text-sm font-medium';
                text.append(label, el('span', 'block truncate text-xs text-muted', item.sub ?? ''));
                li.append(lead, text);
                if (item.state) li.append(el('span', 'shrink-0 text-xs text-muted', item.state));
                li.addEventListener('mousemove', () => { if (active !== index) select(index); });
                li.addEventListener('click', () => run(item));
                list.append(li);
                options.push({ li, item });
            }
        }

        input.setAttribute('aria-expanded', options.length ? 'true' : 'false');
        status.textContent = options.length ? '' : (q.length >= 2 ? t('search.no_results', { q }) : '');
        status.hidden = !status.textContent;

        if (options.length) select(0);
        else showPreview(null);
    }

    function select(index) {
        active = options.length ? (index + options.length) % options.length : -1;
        options.forEach((o, i) => o.li.setAttribute('aria-selected', i === active ? 'true' : 'false'));
        input.setAttribute('aria-activedescendant', active >= 0 ? options[active].li.id : '');
        options[active]?.li.scrollIntoView({ block: 'nearest' });
        showPreview(options[active]?.item ?? null);
    }

    // ---------- preview ----------
    function showPreview(item) {
        clearTimeout(previewTimer);

        if (!item?.peek) {
            preview.hidden = !item;
            if (item) {
                preview.replaceChildren(el('div', 'ss-palette-preview-cmd'));
                const box = preview.firstChild;
                if (item.icon) { const i = el('span', 'ss-palette-icon is-large'); i.append(icon(item.icon)); box.append(i); }
                box.append(el('strong', '', item.label), el('span', '', item.action ? tx('view_only') : item.sub));
            }

            return;
        }

        preview.hidden = false;
        previewTimer = setTimeout(async () => {
            preview.replaceChildren(el('p', 'ss-peek-loading', t('peek.loading')));

            try {
                const data = await peek(...item.peek);
                if (options[active]?.item === item) preview.replaceChildren(renderPeek(data, { compact: false }));
            } catch {
                preview.replaceChildren(el('p', 'ss-peek-loading', t('peek.failed')));
            }
        }, 120);
    }

    // ---------- running a choice ----------
    function run(item) {
        if (item.action === 'theme') {
            dialog.close();
            document.querySelector('[data-theme-toggle]')?.click();

            return;
        }

        if (item.action === 'focus') {
            dialog.close();
            const button = document.querySelector('[data-mission-focus]');

            if (button) {
                button.click();
            } else {
                try { localStorage.setItem('ss-mission-focus', '1'); } catch { /* ignore */ }
                const home = config.commands.find((c) => c.id === 'go:dashboard');
                if (home) location.href = home.url;
            }

            return;
        }

        if (item.url) location.href = item.url;
    }

    // ---------- search ----------
    async function search() {
        const q = input.value.trim();

        if (q.length < 2) {
            searchResults = [];
            render(q);

            return;
        }

        render(q); // commands at once
        controller?.abort();
        controller = new AbortController();

        try {
            const url = new URL(dialog.dataset.searchUrl, location.origin);
            url.searchParams.set('q', q);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', signal: controller.signal });

            if (!response.ok) throw new Error(String(response.status));

            const data = await response.json();

            if (input.value.trim() === q) {
                searchResults = data.results || [];
                render(q);
            }
        } catch (error) {
            if (error.name !== 'AbortError' && !options.length) {
                status.textContent = t('search.error');
                status.hidden = false;
            }
        }
    }

    function open() {
        if (dialog.open) return;
        dialog.showModal();
        input.select();
        searchResults = [];
        render(input.value.trim());
        if (input.value.trim().length >= 2) search();
    }

    document.querySelectorAll('[data-search-open]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            open();
        });
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            dialog.open ? dialog.close() : open();
        }
    });

    input.addEventListener('input', () => {
        clearTimeout(timer);
        // Commands react at once; the search waits for a pause in typing.
        searchResults = [];
        render(input.value.trim());
        timer = setTimeout(search, 150);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            dialog.close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            select(active + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            select(active - 1);
        } else if (event.key === 'Enter' && active >= 0) {
            // Run the chosen item; without a choice the form goes to /search.
            event.preventDefault();
            run(options[active].item);
        }
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
