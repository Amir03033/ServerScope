// Wall screen: a full-screen show for a TV. The director (wall/director.js)
// tours an overview, the servers, the skyline, the websites, the heartbeat,
// jobs and deploys, the globe and the incidents, and cuts to an
// incident as soon as one opens: a large alert and the camera on the tower
// of the server it concerns. Data: the NEXUS topology, the wall data and the
// globe data, polled every 20, 30 and 60 seconds. Also used as the screensaver (?screensaver=1):
// any key or mouse movement then returns to the page you came from.

import { numberLocale } from './i18n';
import { locationStates } from './globe/geo.js';
import { append, clear, h } from './nexus/dom.js';
import { translator } from './nexus/model.js';
import { createMonitor } from './pulse/monitor.js';
import { serverVitals } from './pulse/vitals.js';
import { city } from './skyline/model.js';
import { activityBoard, incidentBoard, overviewTiles, serversBoard, sitesBoard, sizeMeters } from './wall/boards.js';
import { DIRECTOR, direct, incidentsFrom, initialState, shotFor } from './wall/director.js';

const GRAPH_MS = 20_000;
const GLOBE_MS = 60_000;
const WALL_MS = 30_000;
const SCENES = ['summary', 'servers', 'skyline', 'sites', 'pulse', 'activity', 'globe', 'incidents'];
const WEBGL_SCENES = ['skyline', 'globe'];

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
    let wallData = config.initial.wall;
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

    // Without WebGL the 3D scenes are left out; a board with nothing on it (no websites, no jobs or deploys) too.
    let scenes = SCENES;
    const pickScenes = () => {
        const next = SCENES.filter((s) => (webgl || !WEBGL_SCENES.includes(s))
            && (s !== 'sites' || (wallData?.sites ?? []).length > 0)
            && (s !== 'servers' || (wallData?.servers ?? []).length > 0)
            && (s !== 'activity' || (wallData?.jobs ?? []).length + (wallData?.deploys ?? []).length > 0));

        if (next.join() === scenes.join() && state.scenes === scenes) return;

        const current = state.scenes?.[state.index];
        scenes = next;
        state.scenes = scenes;
        state.index = Math.max(0, scenes.indexOf(current));
    };
    pickScenes();

    // ---------- scenes ----------
    // One build per scene, also when the director asks again while it loads.
    const building = new Map();

    function ensure(scene) {
        // Not built (e.g. the globe had no locations yet): try again next time.
        if (!building.has(scene)) building.set(scene, build(scene).catch(() => {}).finally(() => { if (!(scene === 'skyline' ? skyline : scene === 'globe' ? globe : true)) building.delete(scene); }));

        return building.get(scene);
    }

    async function build(scene) {
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
        clear($('tiles'));
        append($('tiles'), overviewTiles({ vitals, graph, incidents, data: wallData }, t, number));
    }

    const severityLabel = (s) => (t(`severity.${s}`) === `severity.${s}` ? t('severity.other') : t(`severity.${s}`));

    function renderBoards() {
        const fill = (name, children) => {
            const el = $(name);
            clear(el);
            append(el, children);
            sizeMeters(el);
        };

        fill('servers', serversBoard(wallData, t, number));
        fill('sites', sitesBoard(wallData, t, number));
        fill('activity', activityBoard(wallData, t));
        fill('incident-board', incidentBoard(wallData, t, severityLabel));
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

        // Beside a focused tower the alert is a panel on the left (the city moves
        // right); over any other scene a band along the bottom.
        const open = (state.mode === 'incident' && Boolean(state.alert)) || state.mode === 'resolved';
        const side = open && shown?.startsWith('skyline|server:') && window.innerWidth >= 768;
        alert.dataset.layout = side ? 'side' : 'bottom';
        skyline?.setShift(side ? 0.17 : 0);

        if (state.mode === 'incident' && state.alert) {
            const severity = state.alert.severity;
            alert.hidden = false;
            alert.dataset.tone = ['critical', 'high'].includes(severity) ? 'bad' : 'warn';
            $('alert-kicker').textContent = t(`severity.${severity}`) === `severity.${severity}` ? t('severity.other') : t(`severity.${severity}`);
            // Non-breaking hyphens: "db-01" never splits over two lines.
            $('alert-title').textContent = state.alert.title.replaceAll('-', '\u2011');
            const since = state.alert.openedAt ? new Date(state.alert.openedAt).toLocaleTimeString(numberLocale(), { hour: '2-digit', minute: '2-digit' }) : '';
            $('alert-meta').textContent = [state.alert.label, since && t('since', { time: since })].filter(Boolean).join(' · ');
        } else if (state.mode === 'resolved') {
            alert.hidden = false;
            alert.dataset.tone = 'ok';
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
        const total = state.mode === 'tour' ? DIRECTOR.cycleMs : DIRECTOR.incidentShotMs;
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
        pickScenes();
        renderPulse();
        renderSummary();
        renderBoards();
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
            wallData = (await load(config.urls.wall)) ?? wallData;
            apply();
        } catch {
            // Keep showing the last state.
        }
    }, WALL_MS);

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

    // Screensaver: back to where you were, but only on a real person. Browsers
    // also send "mouse moves" by themselves (when a scene swaps under a still
    // cursor, when the display wakes, when the window regains focus); those have
    // no movement, or come right after waking. So: a key (not a lone modifier),
    // a click, or a mouse that really travels 60 px within a second, and never
    // in the first seconds after loading or after the screen comes back.
    if (screensaver) {
        const back = params.get('return') ?? '';
        const target = back.startsWith('/') && !back.startsWith('//') ? back : config.urls.exit;
        const MODIFIERS = ['Shift', 'Control', 'Alt', 'Meta', 'CapsLock', 'Fn', 'AudioVolumeUp', 'AudioVolumeDown', 'AudioVolumeMute', 'MediaPlayPause'];
        let quietUntil = performance.now() + 3000;
        let travel = [];
        const settle = () => { quietUntil = performance.now() + 2500; travel = []; };
        const ready = (e) => e.isTrusted && performance.now() > quietUntil;
        const leave = () => { location.href = target; };

        window.addEventListener('keydown', (e) => { if (ready(e) && !MODIFIERS.includes(e.key)) leave(); });
        window.addEventListener('pointerdown', (e) => { if (ready(e)) leave(); });
        window.addEventListener('pointermove', (e) => {
            if (!ready(e) || (e.movementX === 0 && e.movementY === 0)) return;
            const now = performance.now();
            travel = travel.filter((p) => now - p.at < 1000);
            travel.push({ at: now, d: Math.hypot(e.movementX, e.movementY) });
            if (travel.reduce((sum, p) => sum + p.d, 0) > 60) leave();
        });
        document.addEventListener('visibilitychange', settle);
        window.addEventListener('focus', settle);
        document.addEventListener('fullscreenchange', settle);
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
