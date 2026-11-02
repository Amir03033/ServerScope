// Traffic flows for the 3D NEXUS view. Pure functions (tested with Node).
//
// ServerScope does not measure traffic between resources; it measures load
// at the resources themselves. A flow is therefore a picture of that load
// along each link, from the dependency (that serves) to the dependent (that
// uses it):
//
// - density: the CPU load of the serving server (or of the server a serving
//   service runs on), 1–4 beads; unknown load gives the middle value;
// - speed: the last measured response time of the website on the link
//   (faster site, faster flow); a degraded end halves it;
// - stopped: either end is failing (critical, or an active incident on the
//   serving side): the beads pile up at the serving end, like a jam;
// - idle: either end is not measured, paused or in maintenance: no beads.

export const FLOW = {
    minDensity: 1,
    maxDensity: 4,
    baseSpeed: 0.45, // links per second at a 300 ms response
    referenceMs: 300,
    minSpeed: 0.12,
    maxSpeed: 1.1,
    maxBeads: 800,
};

const QUIET = new Set(['unknown', 'paused', 'maintenance']);

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

/** CPU load (%) behind a node: its own as a server, its server's as a service; null when not measured. */
export function loadOf(node, nodes) {
    if (!node) return null;

    const server = node.type === 'service' ? nodes.get(node.parent) : node;
    const cpu = server?.type === 'server' ? server.details?.metrics?.cpu_percent : null;

    return typeof cpu === 'number' && Number.isFinite(cpu) ? clamp(cpu, 0, 100) : null;
}

/** Last response time (ms) of the website on the link, preferring the dependent; null when not measured. */
export function responseOf(source, target) {
    for (const node of [source, target]) {
        const ms = node?.type === 'domain' ? node.details?.response_ms : null;

        if (typeof ms === 'number' && Number.isFinite(ms) && ms > 0) return ms;
    }

    return null;
}

/**
 * @param {{ source: string, target: string }} edge dependent (source) -> dependency (target)
 * @param {Map<string, object>} nodes topology nodes by key
 * @returns {{ state: 'flowing'|'stopped'|'idle', density: number, speed: number, load: ?number, responseMs: ?number }}
 */
export function flowFor(edge, nodes) {
    const source = nodes.get(edge.source);
    const target = nodes.get(edge.target);
    const load = loadOf(target, nodes);
    const responseMs = responseOf(source, target);
    const base = { load, responseMs };

    if (!source || !target) return { state: 'idle', density: 0, speed: 0, ...base };

    if (source.health === 'critical' || target.health === 'critical' || target.incidents?.active > 0) {
        return { state: 'stopped', density: FLOW.maxDensity, speed: 0, ...base };
    }

    if (QUIET.has(source.health) || QUIET.has(target.health)) {
        return { state: 'idle', density: 0, speed: 0, ...base };
    }

    const density = load === null ? 2 : clamp(Math.round(FLOW.minDensity + (load / 100) * (FLOW.maxDensity - FLOW.minDensity)), FLOW.minDensity, FLOW.maxDensity);
    let speed = responseMs === null ? FLOW.baseSpeed : FLOW.baseSpeed * (FLOW.referenceMs / responseMs);

    if (source.health === 'warning' || target.health === 'warning') speed /= 2;

    return { state: 'flowing', density, speed: clamp(speed, FLOW.minSpeed, FLOW.maxSpeed), ...base };
}

/**
 * Bead counts for every link within the overall budget: when the map is
 * large, every flowing link keeps at least one bead and the rest is scaled.
 *
 * @param {Array<{ key: string, flow: object }>} links
 * @returns {Map<string, number>} link key => beads
 */
export function budget(links, max = FLOW.maxBeads) {
    const wanted = links.map(({ key, flow }) => [key, flow.state === 'idle' ? 0 : flow.density]);
    const total = wanted.reduce((sum, [, n]) => sum + n, 0);

    if (total <= max) return new Map(wanted);

    const scale = max / total;

    return new Map(wanted.map(([key, n]) => [key, n === 0 ? 0 : Math.max(1, Math.floor(n * scale))]));
}
