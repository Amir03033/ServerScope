// Status page 3D hero (opt-in per page). The page works without it: with
// no WebGL, or if anything fails, the hero simply stays hidden.

function webglAvailable() {
    try {
        const probe = document.createElement('canvas');

        return Boolean(window.WebGLRenderingContext && (probe.getContext('webgl2') || probe.getContext('webgl')));
    } catch {
        return false;
    }
}

function boot() {
    const container = document.querySelector('[data-status-hero]');
    const source = document.getElementById('status-hero-data');

    if (!container || !source || !webglAvailable()) return;

    let data;

    try {
        data = JSON.parse(source.textContent);
    } catch {
        return;
    }

    if (!Array.isArray(data.components) || data.components.length === 0) return;

    container.hidden = false;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    import('./status/hero3d.js')
        .then(({ createHero }) => createHero(container, data, { reducedMotion }))
        .catch(() => { container.hidden = true; });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
