// The server skyline: every server a tower in a city at night. Pure
// functions (tested with Node); the three.js scene is in scene.js.
//
// - height: the server's CPU load (busier, taller); not measured: low;
// - windows: its services, each a block of windows; lit when running,
//   amber when starting/warning, red when failing, dark when paused or
//   unknown. A server without services has its windows lit as a whole;
// - lights: on (all well), flicker (a failure on the server), out (the
//   server does not report), dim (paused, maintenance or not measured);
// - beacons on the roof: the websites that depend on the server.

export const CITY = {
    spacing: 5.2,
    minHeight: 2.2,
    maxHeight: 11,
    unmeasuredHeight: 3,
    columns: 4,
    floorHeight: 0.62,
    maxBeacons: 6,
};

const LIGHTS = { steady: 'on', irregular: 'on', chaos: 'flicker', flat: 'out', unknown: 'dim', rest: 'dim' };
const WINDOW = { ok: 'lit', warning: 'warn', critical: 'bad', unknown: 'dark', paused: 'dark', maintenance: 'dark' };

/** Tower height from CPU (%); null when not measured. */
export function heightFor(cpu) {
    if (cpu === null || cpu === undefined) return CITY.unmeasuredHeight;

    return Math.round((CITY.minHeight + (Math.min(100, Math.max(0, cpu)) / 100) * (CITY.maxHeight - CITY.minHeight)) * 100) / 100;
}

/** Window states for a tower of `floors` floors: one block per service, bottom up. */
export function windowsFor(services, floors, lights) {
    const total = floors * CITY.columns;

    if (lights === 'out') return Array(total).fill('dark');

    if (services.length === 0) return Array(total).fill(lights === 'dim' ? 'dark' : 'lit');

    const out = [];
    const per = Math.max(1, Math.floor(total / services.length));

    services.forEach((service, i) => {
        const count = i === services.length - 1 ? total - out.length : per;
        const state = lights === 'dim' ? 'dark' : WINDOW[service.health] ?? 'dark';

        for (let k = 0; k < count && out.length < total; k++) out.push(state);
    });

    return out;
}

/** A tower per server (from serverVitals), laid out on a square grid around the centre. */
export function city(vitals) {
    const columns = Math.max(1, Math.ceil(Math.sqrt(vitals.length)));
    const rows = Math.ceil(vitals.length / columns);

    return vitals.map((v, i) => {
        const col = i % columns;
        const row = Math.floor(i / columns);
        const lights = LIGHTS[v.rhythm] ?? 'dim';
        const height = heightFor(v.cpu);
        const floors = Math.max(2, Math.round(height / CITY.floorHeight));

        return {
            key: v.key,
            name: v.name,
            url: v.url,
            rhythm: v.rhythm,
            cpu: v.cpu,
            x: (col - (columns - 1) / 2) * CITY.spacing,
            z: (row - (rows - 1) / 2) * CITY.spacing,
            height,
            floors,
            lights,
            windows: windowsFor(v.services, floors, lights),
            beacons: v.sites.slice(0, CITY.maxBeacons).map((s) => ({ key: s.key, name: s.name, state: s.health === 'critical' ? 'bad' : s.health === 'warning' ? 'warn' : s.health === 'ok' ? 'ok' : 'off' })),
            hiddenBeacons: Math.max(0, v.sites.length - CITY.maxBeacons),
        };
    });
}
