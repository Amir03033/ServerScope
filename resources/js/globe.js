// NEXUS globe page: a list of measuring locations and websites (works
// without WebGL) and, where WebGL is available, the 3D globe (three.js,
// loaded on demand). Polls the team's globe data every minute.

import { numberLocale } from './i18n';
import { append, clear, h, status } from './nexus/dom.js';
import { translator } from './nexus/model.js';
import { locationStates, worst } from './globe/geo.js';

const POLL_MS = 60_000;
const TONE = { ok: 'ok', down: 'bad', unknown: 'muted', paused: 'muted', maintenance: 'info' };

function boot() {
    const root = document.querySelector('[data-globe]');
    const configElement = document.getElementById('globe-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (name) => root.querySelector(`[data-globe-${name}]`);
    const els = { stage: $('stage'), locations: $('locations'), sites: $('sites'), select: $('select'), notice: $('notice'), overlay: $('overlay') };

    let data = config.initial;
    let selected = null;
    let globe = null;

    const time = (iso) => (iso ? new Date(iso).toLocaleString(numberLocale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—');
    const locationName = (l) => (l.kind === 'main' ? (l.name ?? t('main')) : l.name) + (l.location && l.location !== l.name ? ` · ${l.location}` : '');

    // ---------- list (always available; the keyboard and no-WebGL view) ----------
    function renderLists() {
        const states = locationStates(data.locations, data.sites, selected);

        clear(els.locations);
        append(els.locations, data.locations.length === 0
            ? [h('p', { class: 'm-0 text-sm text-muted' }, t('no_locations'))]
            : data.locations.map((l) => {
                const s = states.get(l.key);

                return h('div', { class: 'ss-globe-row' },
                    h('div', { class: 'min-w-0' },
                        h('strong', { class: 'block break-words' }, locationName(l)),
                        h('span', { class: 'block text-xs text-muted' }, l.kind === 'main' ? t('kind_main') : t('kind_probe', { state: l.healthy ? t('probe_online') : t('probe_offline') })),
                        selected !== null && s?.at ? h('span', { class: 'block text-xs text-muted' }, t('measured', { time: time(s.at) })) : null,
                        selected === null ? h('span', { class: 'block text-xs text-muted' }, t('counts', { ok: s.ok, down: s.down, unknown: s.unknown })) : null,
                    ),
                    status(t(`states.${s.state}`), TONE[s.state] ?? 'muted'),
                );
            }));

        // Websites: not reachable somewhere first.
        const order = { down: 0, unknown: 1, ok: 2, maintenance: 3, paused: 4 };
        const sites = [...data.sites].sort((a, b) => (order[worst(a)] ?? 9) - (order[worst(b)] ?? 9) || a.name.localeCompare(b.name));

        clear(els.sites);
        append(els.sites, sites.length === 0 ? [h('p', { class: 'm-0 text-sm text-muted' }, t('no_sites'))] : sites.map((site) => h('button', {
            type: 'button', class: 'ss-globe-site', 'aria-pressed': site.id === selected ? 'true' : 'false', onclick: () => choose(site.id === selected ? null : site.id),
        },
        h('span', { class: 'min-w-0 break-words text-left font-semibold' }, site.name),
        h('span', { class: 'flex flex-wrap justify-end gap-1' }, data.locations.map((l) => {
            const state = site.reach?.[l.key]?.state ?? 'unknown';

            return h('span', { class: `ss-chip ss-chip-${state === 'ok' ? 'ok' : state === 'down' ? 'bad' : 'muted'}`, title: `${locationName(l)}: ${t(`states.${state}`)}` }, `${l.kind === 'main' ? t('short_main') : (l.location ?? l.name)}: ${t(`states.${state}`)}`);
        })))));

        // The select mirrors the list (for small screens and screen readers).
        const current = String(selected ?? '');
        clear(els.select);
        append(els.select, [h('option', { value: '' }, t('all_sites')), ...data.sites.map((s) => h('option', { value: String(s.id), selected: String(s.id) === current }, s.name))]);

        const notes = [];
        if (!data.meta.main_placed) notes.push(t('note_main'));
        if (data.meta.unplaced_probes > 0) notes.push(t(data.meta.unplaced_probes === 1 ? 'note_unplaced_one' : 'note_unplaced', { count: data.meta.unplaced_probes }));
        if (data.meta.truncated) notes.push(t('note_truncated'));
        notes.push(t('note_confirmations', { days: data.meta.window_days }));
        clear(els.notice);
        append(els.notice, notes.map((n) => h('p', { class: 'm-0' }, n)));
    }

    function choose(id) {
        selected = id;
        renderLists();
        globe?.update(data, locationStates(data.locations, data.sites, selected), selected);
    }

    els.select.addEventListener('change', () => choose(els.select.value === '' ? null : Number(els.select.value)));

    // ---------- globe ----------
    const webgl = (() => {
        try {
            const c = document.createElement('canvas');

            return Boolean(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
        } catch {
            return false;
        }
    })();

    if (webgl && data.locations.length > 0) {
        els.overlay.hidden = false;
        import('./globe/scene.js').then(({ createGlobe }) => {
            globe = createGlobe(els.stage, {
                reducedMotion,
                t,
                onSelectLocation: (key) => globe.face(key),
            });
            globe.update(data, locationStates(data.locations, data.sites, selected), selected);
            // Start with the main location (or the first one) facing the viewer.
            globe.face(data.locations.find((l) => l.kind === 'main')?.key ?? data.locations[0].key);
            els.overlay.hidden = true;
        }).catch(() => {
            els.overlay.hidden = true;
            els.stage.hidden = true;
        });
    } else {
        els.stage.hidden = true;
        if (!webgl) append(els.notice, [h('p', { class: 'm-0' }, t('no_webgl'))]);
    }

    // ---------- polling ----------
    async function refresh() {
        if (document.hidden) return;

        try {
            const response = await fetch(config.urls.data, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });

            if (response.ok) {
                data = (await response.json()).data;
                renderLists();
                globe?.update(data, locationStates(data.locations, data.sites, selected), selected);
            }
        } catch {
            // Keep showing the last data; the next poll tries again.
        }
    }

    setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
    renderLists();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
