// Dashboard heartbeat: one live ECG line per server. The rate follows
// the server's CPU load, the width of the beat its slowest website, and
// the line goes haywire when something on the server fails. Data: the
// team's NEXUS topology, polled every 30 seconds. Every row also says the
// rhythm and the numbers in words; the lines themselves are decoration.

import { numberLocale } from './i18n';
import { translator } from './nexus/model.js';
import { createMonitor } from './pulse/monitor.js';
import { serverVitals } from './pulse/vitals.js';

const POLL_MS = 30_000;

function boot() {
    const root = document.querySelector('[data-pulse]');
    const configElement = document.getElementById('pulse-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const number = new Intl.NumberFormat(numberLocale(), { maximumFractionDigits: 0 });
    const rows = new Map();
    const visible = new Set();

    const observer = 'IntersectionObserver' in window
        ? new IntersectionObserver((entries) => {
            for (const entry of entries) {
                const key = entry.target.dataset.pulseRow;
                entry.isIntersecting ? visible.add(key) : visible.delete(key);
            }
        })
        : null;

    for (const row of root.querySelectorAll('[data-pulse-row]')) {
        const canvas = row.querySelector('[data-pulse-canvas]');
        const key = row.dataset.pulseRow;
        rows.set(key, {
            row,
            monitor: createMonitor(canvas, { reducedMotion }),
            state: row.querySelector('[data-pulse-state]'),
            reason: row.querySelector('[data-pulse-reason]'),
            cpu: row.querySelector('[data-pulse-cpu]'),
            ms: row.querySelector('[data-pulse-ms]'),
            bpm: row.querySelector('[data-pulse-bpm]'),
        });
        observer ? observer.observe(row) : visible.add(key);
    }

    function apply(graph) {
        for (const v of serverVitals(graph)) {
            const row = rows.get(v.key);

            if (!row) continue;

            row.monitor.set(v);
            row.row.dataset.rhythm = v.rhythm;
            row.state.textContent = t(`rhythm.${v.rhythm}`);
            row.reason.textContent = v.reasons.map((r) => t(`reason.${r}`)).join(' · ');
            row.cpu.textContent = v.cpu === null ? '—' : `${number.format(v.cpu)}%`;
            row.ms.textContent = v.responseMs === null ? '—' : `${number.format(v.responseMs)} ms`;
            row.bpm.textContent = ['steady', 'irregular'].includes(v.rhythm) ? t('bpm', { bpm: v.bpm }) : '—';
        }
    }

    async function refresh() {
        if (document.hidden) return;

        try {
            const response = await fetch(config.urls.graph, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (response.ok) apply((await response.json()).data);
        } catch {
            // Keep the last rhythm; the next poll tries again.
        }
    }

    // One animation loop for all lines; only visible rows are drawn.
    let last = null;
    const loop = (now) => {
        const dt = last === null ? 0 : Math.min(2, (now - last) / 1000);
        last = now;

        for (const key of visible) rows.get(key)?.monitor.tick(dt);

        requestAnimationFrame(loop);
    };

    if (!reducedMotion) requestAnimationFrame(loop);

    window.addEventListener('resize', () => rows.forEach((r) => r.monitor.resize()));
    const onTheme = () => rows.forEach((r) => r.monitor.theme());
    new MutationObserver(onTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme', 'data-variant'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onTheme);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });

    setInterval(refresh, POLL_MS);
    refresh();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
