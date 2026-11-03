// Wall screen: a full-screen show for a TV. The director (wall/director.js)
// tours the skyline, the globe, the heartbeat and a summary, and cuts to an
// incident as soon as one opens: a large alert and the camera on the tower
// of the server it concerns. Data: the NEXUS topology and the globe data,
// polled every 20 and 60 seconds. Also used as the screensaver (?screensaver=1):
// any key or mouse movement then returns to the page you came from.

import { numberLocale } from './i18n';
import { locationStates } from './globe/geo.js';
import { append, clear, h } from './nexus/dom.js';
import { translator } from './nexus/model.js';
import { createMonitor } from './pulse/monitor.js';
import { serverVitals } from './pulse/vitals.js';
import { city } from './skyline/model.js';
import { direct, incidentsFrom, initialState, shotFor } from './wall/director.js';

const GRAPH_MS = 20_000;
const GLOBE_MS = 60_000;
const SCENES = ['skyline', 'globe', 'pulse', 'summary'];

function boot() {
    const root = document.querySelector('[data-wall]');
    const configElement = document.getElementById('wall-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const tSkyline = translator(config.skyline);
    const tGlobe = translator(config.globeTexts);
    const tPulse = translator(config.pulse);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const params = new URLSearchParams(location.search);
    const screensaver = params.get('screensaver') === '1';
    const number = new Intl.NumberFormat(numberLocale(), { maximumFractionDigits: 0 });
    const $ = (name) => root.querySelector(`[data-wall-${name}]`);
    const layers = Object.fromEntries(SCENES.map((s) => [s, root.querySelector(`[data-wall-layer="${s}"]`)]));

    let graph = config.initial.graph;
    let globeData = config.initial.globe;
    let vitals = serverVitals(graph);
    let incidents = incidentsFrom(graph, vitals);
    let state = initialState(performance.now(), SCENES);
    let shown = null;
    let skyline = null;
    let globe = null;
    const monitors = new Map();

    const webgl = (() => {
        try {
            const c = document.createElement('canvas');

            return Boolean(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
        } catch {
            return false;
        }
    })();

    // Without WebGL the 3D scenes are left out of the tour.
    const scenes = webgl ? SCENES : ['pulse', 'summary'];
    state.scenes = scenes;

    // ---------- scenes ----------
    async function ensure(scene) {
        if (scene === 'skyline' && !skyline && webgl) {
            const { createSkyline } = await import('./skyline/scene.js');
            skyline = createSkyline($('skyline'), { reducedMotion, t: tSkyline });
            skyline.update(city(vitals));
        }

        if (scene === 'globe' && !globe && webgl && globeData.locations.length > 0) {
            const { createGlobe } = await import('./globe/scene.js');
            globe = createGlobe($('globe'), { reducedMotion, t: tGlobe });
            globe.update(globeData, locationStates(globeData.locations, globeData.sites, null), null);
        }
    }

    function renderPulse() {
        const list = $('pulse');
        const keys = vitals.map((v) => v.key).join('|');

        if (list.dataset.keys !== keys) {
            clear(list);
            monitors.clear();
            list.dataset.keys = keys;

            for (const v of vitals.slice(0, 10)) {
                const canvas = h('canvas', { class: 'ss-pulse-canvas', 'aria-hidden': 'true' });
                const row = h('li', { class: 'ss-pulse-row', 'data-rhythm': v.rhythm },
                    h('div', { class: 'min-w-0' }, h('div', { class: 'truncate text-lg font-semibold' }, v.name), h('span', { class: 'ss-pulse-state' }), h('span', { class: 'ss-pulse-reason' })),
                    canvas,
                    h('div', { class: 'ss-wall-numbers' }, h('span', {}), h('span', {})),
                );
                append(list, [row]);
                monitors.set(v.key, { row, monitor: createMonitor(canvas, { reducedMotion }) });
            }
        }

        for (const v of vitals) {
            const m = monitors.get(v.key);

            if (!m) continue;

            m.monitor.set(v);
            m.row.dataset.rhythm = v.rhythm;
            m.row.querySelector('.ss-pulse-state').textContent = tPulse(`rhythm.${v.rhythm}`);
            m.row.querySelector('.ss-pulse-reason').textContent = v.reasons.map((r) => tPulse(`reason.${r}`)).join(' · ');
            const [cpu, ms] = m.row.querySelectorAll('.ss-wall-numbers span');
            cpu.textContent = `CPU ${v.cpu === null ? '—' : `${number.format(v.cpu)}%`}`;
            ms.textContent = v.responseMs === null ? '' : `${number.format(v.responseMs)} ms`;
        }
    }

    function renderSummary() {
        const servers = vitals.length;
        const online = vitals.filter((v) => !['flat', 'unknown'].includes(v.rhythm)).length;
        const sites = (graph.nodes ?? []).filter((n) => n.type === 'domain');
        const reachable = sites.filter((n) => n.health === 'ok').length;
        const times = sites.map((n) => n.details?.response_ms).filter((ms) => typeof ms === 'number');
        const average = times.length ? times.reduce((a, b) => a + b, 0) / times.length : null;
        const tile = (value, label, tone) => h('div', { class: `ss-wall-tile is-${tone}` }, h('div', { class: 'ss-wall-tile-value' }, value), h('div', { class: 'ss-wall-tile-label' }, label));

        clear($('tiles'));
        append($('tiles'), [
            tile(`${online}/${servers}`, t('tiles.servers'), online === servers ? 'ok' : 'bad'),
            tile(`${reachable}/${sites.length}`, t('tiles.sites'), reachable === sites.length ? 'ok' : 'bad'),
            tile(String(incidents.length), t('tiles.incidents'), incidents.length ? 'bad' : 'ok'),
            tile(average === null ? '—' : `${number.format(average)} ms`, t('tiles.response'), 'info'),
        ]);
    }

    function renderChrome() {
        $('status-text').textContent = incidents.length ? t(incidents.length === 1 ? 'status.incident' : 'status.incidents', { count: incidents.length }) : t('status.ok');
        $('status').dataset.tone = incidents.length ? 'bad' : 'ok';

        clear($('incidents'));
        append($('incidents'), incidents.slice(0, 3).map((i) => h('span', { class: 'ss-wall-chip' }, `${i.label ?? ''} · ${i.title}`)));
    }

    function show(shot) {
        const key = `${shot.scene}|${shot.focus ?? ''}`;

        if (shown === key) return;

        shown = key;

        for (const [scene, layer] of Object.entries(layers)) {
            const active = scene === shot.scene;
            layer.hidden = !active;
            layer.classList.toggle('is-active', active);
        }

        skyline?.[shot.scene === 'skyline' ? 'resume' : 'pause']();
        globe?.[shot.scene === 'globe' ? 'resume' : 'pause']();

        if (shot.scene === 'skyline' && skyline) shot.focus ? skyline.focus(shot.focus) : skyline.overview();
        if (shot.scene === 'globe' && globe) globe.face(globeData.locations.find((l) => l.kind === 'main')?.key ?? globeData.locations[0]?.key);

        // Scene tabs: the one on screen is lit, with its progress underneath.
        clear($('tabs'));
        append($('tabs'), scenes.map((s) => h('li', { class: s === shot.scene ? 'is-active' : null }, h('span', {}, t(`scenes.${s}`)), h('i', {}))));
    }

    function renderAlert() {
        const alert = $('alert');

        const open = (state.mode === 'incident' && Boolean(state.alert)) || state.mode === 'resolved';
        skyline?.setShift(open && window.innerWidth >= 768 ? 0.17 : 0);

        if (state.mode === 'incident' && state.alert) {
            alert.hidden = false;
            alert.dataset.severity = state.alert.severity;
            $('alert-kicker').textContent = t(`severity.${state.alert.severity}`, {}) || state.alert.severity;
            // Non-breaking hyphens: "db-01" never splits over two lines.
            $('alert-title').textContent = state.alert.title.replaceAll('-', '\u2011');
            const since = state.alert.openedAt ? new Date(state.alert.openedAt).toLocaleTimeString(numberLocale(), { hour: '2-digit', minute: '2-digit' }) : '';
            $('alert-meta').textContent = [state.alert.label, since && t('since', { time: since })].filter(Boolean).join(' · ');
        } else if (state.mode === 'resolved') {
            alert.hidden = false;
            alert.dataset.severity = 'resolved';
            $('alert-kicker').textContent = t('resolved_kicker');
            $('alert-title').textContent = t('resolved');
            $('alert-meta').textContent = '';
        } else {
            alert.hidden = true;
        }
    }

    // ---------- direction ----------
    let cycleStart = performance.now();

    async function step() {
        const before = `${state.mode}|${state.index}|${state.shot}|${state.alert?.id}`;
        state = direct(state, incidents, performance.now());
        const after = `${state.mode}|${state.index}|${state.shot}|${state.alert?.id}`;

        if (before !== after) cycleStart = performance.now();

        const shot = shotFor(state, incidents);
        await ensure(shot.scene);
        show(shot);
        renderAlert();
    }

    // One frame loop: the progress bar and, when the heartbeat is on screen, its lines.
    let last = null;

    function frame(now) {
        const total = state.mode === 'tour' ? 25_000 : 12_000;
        const bar = root.querySelector('.ss-wall-tabs .is-active i');
        if (bar) bar.style.transform = `scaleX(${Math.min(1, (now - cycleStart) / total)})`;
        const dt = last === null ? 0 : Math.min(2, (now - last) / 1000);
        last = now;

        if (!reducedMotion && !layers.pulse.hidden) monitors.forEach((m) => m.monitor.tick(dt));

        requestAnimationFrame(frame);
    }

    // ---------- data ----------
    function apply() {
        vitals = serverVitals(graph);
        incidents = incidentsFrom(graph, vitals);
        skyline?.update(city(vitals));
        renderPulse();
        renderSummary();
        renderChrome();
        step();
    }

    async function load(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });

        return response.ok ? (await response.json()).data : null;
    }

    setInterval(async () => {
        try {
            graph = (await load(config.urls.graph)) ?? graph;
            apply();
        } catch {
            // Keep showing the last state.
        }
    }, GRAPH_MS);

    setInterval(async () => {
        try {
            globeData = (await load(config.urls.globe)) ?? globeData;
            globe?.update(globeData, locationStates(globeData.locations, globeData.sites, null), null);
        } catch {
            // Keep showing the last state.
        }
    }, GLOBE_MS);

    setInterval(step, 1000);

    // ---------- chrome: clock, controls that hide, full screen, screen awake ----------
    const clock = $('clock');
    const tickClock = () => {
        const now = new Date();
        clock.textContent = now.toLocaleTimeString(numberLocale(), { hour: '2-digit', minute: '2-digit' });
        $('date').textContent = now.toLocaleDateString(numberLocale(), { weekday: 'long', day: 'numeric', month: 'long' });
    };
    tickClock();
    setInterval(tickClock, 10_000);

    let idle = null;
    const wake = () => {
        root.classList.remove('is-idle');
        clearTimeout(idle);
        idle = setTimeout(() => root.classList.add('is-idle'), 3000);
    };
    window.addEventListener('pointermove', wake);
    wake();

    // Screensaver setting: kept in this browser only (live.js reads it on every page).
    const saver = $('saver');
    try { saver.value = localStorage.getItem('ss-screensaver-minutes') || '0'; } catch { saver.value = '0'; }
    saver.addEventListener('change', () => {
        try { localStorage.setItem('ss-screensaver-minutes', saver.value); } catch { /* private mode: no screensaver */ }
    });

    $('fullscreen').addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : root.requestFullscreen?.()));

    let lock = null;
    const keepAwake = async () => {
        try {
            if ('wakeLock' in navigator && !document.hidden) lock = await navigator.wakeLock.request('screen');
        } catch {
            lock = null;
        }
    };
    keepAwake();
    document.addEventListener('visibilitychange', () => { if (!document.hidden && !lock) keepAwake(); });

    // Screensaver: back to where you were on the first key or real mouse movement.
    if (screensaver) {
        const back = params.get('return') ?? '';
        const target = back.startsWith('/') && !back.startsWith('//') ? back : config.urls.exit;
        let start = null;
        const leave = () => { location.href = target; };

        window.addEventListener('keydown', leave, { once: true });
        window.addEventListener('pointerdown', leave, { once: true });
        window.addEventListener('pointermove', (e) => {
            start ??= { x: e.clientX, y: e.clientY };
            if (Math.hypot(e.clientX - start.x, e.clientY - start.y) > 40) leave();
        });
        $('exit').href = target;
    }

    apply();
    requestAnimationFrame(frame);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
