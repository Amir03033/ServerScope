// The live logo and tab icon: every page polls the team's pulse. The logo
// runs a pulse along its line in the team's rhythm (average CPU) and turns
// amber or red; the tab icon does the same and carries the number of
// active incidents, so a background tab still shows when something fails.
// The page title gets that number too, e.g. "(2) Dashboard — ServerScope".

const POLL_MS = 30_000;
const COLORS = { ok: '#3ee07a', warning: '#f5a524', critical: '#ff4d4f', unknown: '#6b8cff' };

function icon(state, count, bright) {
    const color = COLORS[state] ?? COLORS.unknown;
    const badge = count > 0
        ? `<circle cx="25" cy="7" r="7" fill="#ff4d4f" stroke="#0f172a" stroke-width="1.5"/><text x="25" y="10.4" font-family="Arial,sans-serif" font-size="${count > 9 ? 7.5 : 9.5}" font-weight="700" fill="#fff" text-anchor="middle">${count > 9 ? '9+' : count}</text>`
        : '';
    const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><rect width="32" height="32" rx="8" fill="#0f172a"/><path d="M4 16h5l3.5-9 6 18 3.5-9h6" fill="none" stroke="${color}" stroke-width="${bright ? 3.4 : 2.6}" stroke-linecap="round" stroke-linejoin="round" opacity="${bright ? 1 : 0.8}"/>${badge}</svg>`;

    return `data:image/svg+xml,${encodeURIComponent(svg)}`;
}

function boot() {
    const url = document.querySelector('meta[name="ss-live"]')?.content;
    let link = document.querySelector('link[data-live-icon]');

    if (!url || !link) return;

    const root = document.documentElement;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const baseTitle = document.title.replace(/^\(\d+\+?\)\s/, '');
    let pulse = null;
    let timer = null;

    // The incidents count in the menu follows along (with a small pop when it changes).
    const incidentsLink = [...document.querySelectorAll('.ss-sidebar a.ss-nav-item')].find((a) => new URL(a.href).pathname.replace(/\/$/, '').endsWith('/incidents'));

    function updateNavCount(count) {
        // The phone tab bar shows the same number.
        const tab = [...document.querySelectorAll('.ss-tabbar .ss-tab')].find((a) => new URL(a.href, location.href).pathname.replace(/\/$/, '').endsWith('/incidents'));

        if (tab) {
            let tabBadge = tab.querySelector('.ss-tab-badge');

            if (count === 0) {
                tabBadge?.remove();
            } else {
                if (!tabBadge) {
                    tabBadge = document.createElement('span');
                    tabBadge.className = 'ss-tab-badge';
                    tab.querySelector('.ss-tab-icon')?.append(tabBadge);
                }
                tabBadge.textContent = count > 9 ? '9+' : String(count);
            }
        }

        if (!incidentsLink) return;

        let badge = incidentsLink.querySelector('.nav-count');

        if (count === 0) {
            badge?.remove();

            return;
        }

        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'nav-count';
            incidentsLink.append(badge);
        }

        if (badge.textContent.trim() !== String(count)) {
            badge.textContent = String(count);
            badge.classList.remove('is-bump');
            void badge.offsetWidth;
            badge.classList.add('is-bump');
        }
    }

    function render(bright = false) {
        if (pulse) {
            // A new <link> instead of a new href: Safari only redraws the tab icon for a fresh element.
            const next = link.cloneNode();
            next.href = icon(pulse.state, pulse.incidents, bright);
            link.replaceWith(next);
            link = next;
        }
    }

    // In a visible tab the icon beats (a brief bright frame per beat); hidden tabs keep a still icon.
    function beat() {
        clearTimeout(timer);

        if (!pulse || reducedMotion || document.hidden) return;

        render(true);
        setTimeout(() => render(false), 160);
        timer = setTimeout(beat, (60 / pulse.bpm) * 1000);
    }

    function apply(next) {
        pulse = next;
        root.dataset.live = next.state;
        root.style.setProperty('--ss-beat', `${(60 / next.bpm).toFixed(2)}s`);
        document.title = next.incidents > 0 ? `(${next.incidents > 9 ? '9+' : next.incidents}) ${baseTitle}` : baseTitle;
        updateNavCount(next.incidents);
        render();
        beat();
    }

    async function refresh() {
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store' });

            if (response.ok) apply((await response.json()).data);
        } catch {
            // Keep the last state; the next poll tries again.
        }
    }

    // Screensaver (opt-in on the wall screen page, stored in this browser):
    // after the chosen idle minutes the wall screen takes over; any key or
    // mouse movement there returns here. Never while a form has unsaved input.
    const wall = document.querySelector('meta[name="ss-wall"]')?.content;
    let minutes = 0;
    try { minutes = Number(localStorage.getItem('ss-screensaver-minutes') || 0); } catch { minutes = 0; }

    if (wall && minutes > 0 && !location.pathname.startsWith(new URL(wall).pathname)) {
        let dirty = false;
        let idle = null;
        const arm = () => {
            clearTimeout(idle);
            idle = setTimeout(() => {
                if (!dirty) location.href = `${wall}?screensaver=1&return=${encodeURIComponent(location.pathname + location.search)}`;
            }, minutes * 60_000);
        };

        document.addEventListener('input', () => { dirty = true; }, true);
        ['pointermove', 'pointerdown', 'keydown', 'scroll', 'touchstart'].forEach((type) => window.addEventListener(type, arm, { passive: true }));
        arm();
    }

    // Also while hidden (the browser slows this down): that is when the tab icon matters.
    setInterval(refresh, POLL_MS);
    document.addEventListener('visibilitychange', () => (document.hidden ? render() : beat()));
    refresh();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
