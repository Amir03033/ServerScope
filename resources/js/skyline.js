// NEXUS skyline page: the team's servers as a city at night (three.js,
// loaded on demand), next to a list that says the same in words (and
// works without WebGL). Data: the NEXUS topology, polled every 30 s.

import { append, clear, h, status } from './nexus/dom.js';
import { translator } from './nexus/model.js';
import { serverVitals } from './pulse/vitals.js';
import { city } from './skyline/model.js';

const POLL_MS = 30_000;
const TONE = { on: 'ok', flicker: 'bad', out: 'bad', dim: 'muted' };

function boot() {
    const root = document.querySelector('[data-skyline]');
    const configElement = document.getElementById('skyline-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (name) => root.querySelector(`[data-skyline-${name}]`);
    const els = { stage: $('stage'), list: $('list'), overlay: $('overlay'), empty: $('empty') };
    let scene = null;
    let towers = [];
    let selected = null;

    const percent = (v) => (v === null ? '—' : `${Math.round(v)}%`);

    function renderList() {
        clear(els.list);
        els.empty.hidden = towers.length > 0;

        append(els.list, towers.map((tower) => {
            const down = tower.beacons.filter((b) => b.state === 'bad').length;

            return h('li', { class: 'ss-globe-row', 'data-selected': tower.key === selected ? 'true' : null },
                h('div', { class: 'min-w-0' },
                    h('button', { type: 'button', class: 'ss-skyline-name', onclick: () => choose(tower.key) }, tower.name),
                    h('span', { class: 'block text-xs text-muted' }, t('row', { cpu: percent(tower.cpu), sites: tower.beacons.length + tower.hiddenBeacons, down })),
                    tower.url ? h('a', { class: 'ss-hit text-xs', href: tower.url }, t('open')) : null,
                ),
                status(t(`lights.${tower.lights}`), TONE[tower.lights] ?? 'muted'),
            );
        }));
    }

    function choose(key) {
        selected = key;
        scene?.focus(key);
        renderList();
    }

    function apply(graph) {
        towers = city(serverVitals(graph));
        renderList();
        scene?.update(towers);
    }

    const webgl = (() => {
        try {
            const c = document.createElement('canvas');

            return Boolean(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
        } catch {
            return false;
        }
    })();

    if (webgl) {
        els.overlay.hidden = false;
        import('./skyline/scene.js').then(({ createSkyline }) => {
            scene = createSkyline(els.stage, { reducedMotion, t, onSelect: choose });
            scene.update(towers);
            els.overlay.hidden = true;
        }).catch(() => {
            els.overlay.hidden = true;
            els.stage.hidden = true;
        });
    } else {
        els.stage.hidden = true;
        append(els.list.parentElement, [h('p', { class: 'm-0 text-sm text-muted' }, t('no_webgl'))]);
    }

    async function refresh() {
        if (document.hidden) return;

        try {
            const response = await fetch(config.urls.graph, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (response.ok) apply((await response.json()).data);
        } catch {
            // Keep the last picture; the next poll tries again.
        }
    }

    apply(config.initial);
    setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
