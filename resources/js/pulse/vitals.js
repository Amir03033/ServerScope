// Vital signs per server, from the NEXUS topology (graph.json). Pure
// functions (tested with Node); used by the heartbeat lines on the
// dashboard and by the server skyline.
//
// - rate: the server's CPU load sets the heart rate (busier, faster);
// - width: the slowest website on the server widens the beat (slower
//   responses, a broader, lower spike);
// - rhythm:
//     steady    everything in order;
//     irregular a warning: the server, a service or a website on it, CPU
//               at 90 % or more, or a website answering in 2 s or more;
//     chaos     a failure: an active incident on the server, or a service
//               or website on it failing ("the line goes haywire");
//     flat      the server does not report (offline): no signal;
//     unknown   not measured yet;
//     rest      paused or in maintenance.

export const VITALS = {
    minBpm: 50,
    maxBpm: 140,
    slowCpu: 90,
    slowMs: 2000,
    referenceMs: 400,
};

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const number = (value) => (typeof value === 'number' && Number.isFinite(value) ? value : null);

/** Servers with their services and the websites that depend on them (directly or through a service). */
export function serverVitals(graph) {
    const nodes = new Map((graph?.nodes ?? []).map((n) => [n.key, n]));
    const out = [];

    for (const server of nodes.values()) {
        if (server.type !== 'server') continue;

        const services = [...nodes.values()].filter((n) => n.type === 'service' && n.parent === server.key);
        const serviceKeys = new Set(services.map((s) => s.key));
        const siteKeys = new Set();

        for (const edge of graph.edges ?? []) {
            const source = nodes.get(edge.source);

            if (source?.type === 'domain' && (edge.target === server.key || serviceKeys.has(edge.target))) siteKeys.add(source.key);
        }

        const sites = [...siteKeys].map((k) => nodes.get(k));
        const cpu = number(server.details?.metrics?.cpu_percent);
        const times = sites.map((s) => number(s.details?.response_ms)).filter((ms) => ms !== null && ms > 0);
        const responseMs = times.length ? Math.max(...times) : null;
        const all = [...services, ...sites];
        const reasons = [];

        let rhythm = 'steady';

        if (server.health === 'paused' || server.health === 'maintenance') {
            rhythm = 'rest';
        } else if (server.health === 'critical') {
            rhythm = 'flat';
            reasons.push('offline');
        } else if (server.health === 'unknown') {
            rhythm = 'unknown';
        } else {
            if ((server.incidents?.active ?? 0) > 0) reasons.push('incident');
            if (services.some((s) => s.health === 'critical')) reasons.push('service_down');
            if (sites.some((s) => s.health === 'critical')) reasons.push('site_down');

            if (reasons.length) {
                rhythm = 'chaos';
            } else {
                if (server.health === 'warning' || all.some((n) => n.health === 'warning')) reasons.push('warning');
                if (cpu !== null && cpu >= VITALS.slowCpu) reasons.push('cpu_high');
                if (responseMs !== null && responseMs >= VITALS.slowMs) reasons.push('slow');
                if (reasons.length) rhythm = 'irregular';
            }
        }

        out.push({
            key: server.key,
            id: server.id,
            name: server.label ?? server.key,
            url: server.url ?? null,
            health: server.health,
            cpu,
            memory: number(server.details?.metrics?.memory_percent),
            responseMs,
            services: services.map((s) => ({ key: s.key, name: s.label, health: s.health })),
            sites: sites.map((s) => ({ key: s.key, name: s.label, health: s.health, responseMs: number(s.details?.response_ms) })),
            incidents: server.incidents?.active ?? 0,
            rhythm,
            reasons,
            bpm: rateFor(cpu),
            width: widthFor(responseMs),
        });
    }

    return out.sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true }));
}

/** Heart rate from CPU load; unknown load gives a resting rate. */
export function rateFor(cpu) {
    if (cpu === null) return 60;

    return Math.round(VITALS.minBpm + (clamp(cpu, 0, 100) / 100) * (VITALS.maxBpm - VITALS.minBpm));
}

/** Beat width from the slowest response time: 1 at the reference, broader when slower. */
export function widthFor(ms) {
    if (ms === null) return 1;

    return Math.round(clamp(ms / VITALS.referenceMs, 0.8, 2.6) * 100) / 100;
}

/**
 * One heartbeat, as a value at phase p (0..1 of the beat): P wave, QRS
 * complex and T wave as gaussian bumps. width > 1 broadens and lowers the
 * spike. Returns about -0.3..1.
 */
export function beat(p, width = 1) {
    const g = (centre, spread, height) => height * Math.exp(-(((p - centre) / spread) ** 2));
    const w = Math.max(0.5, width);

    return g(0.12, 0.03, 0.12)
        + g(0.205, 0.008 * w, -0.12)
        + g(0.225, 0.011 * w, 1 / Math.sqrt(w))
        + g(0.25, 0.01 * w, -0.28)
        + g(0.45, 0.05, 0.26);
}
