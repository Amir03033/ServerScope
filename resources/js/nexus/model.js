// NEXUS: pure functions on the topology returned by /nexus/graph.json.
// No DOM, no Cytoscape and no texts here (those come from lang/*/nexus.php),
// so this module can be tested with node --test.
//
// Edges point from the dependent to its dependency ("A depends on B"),
// exactly as DependencyGraph stores them. The blast radius of a node is
// therefore found by walking edges backwards (who depends on me).

export const TYPE_ORDER = ['server', 'service', 'domain', 'page_group', 'page'];

export const HEALTH_ORDER = ['critical', 'warning', 'unknown', 'maintenance', 'paused', 'ok'];

// Tones are the ServerScope status classes (ss-status-ok/-warn/-bad/-info/
// -muted), mapped exactly as the x-status component maps these states.
export const HEALTH_TONE = {
    ok: 'ok',
    warning: 'warn',
    critical: 'bad',
    unknown: 'muted',
    maintenance: 'info',
    paused: 'info',
};

// Same scale as App\Support\Severity, coloured as x-severity.
export const SEVERITY = {
    critical: { tone: 'bad', rank: 3 },
    high: { tone: 'warn', rank: 2 },
    warning: { tone: 'warn', rank: 1 },
    info: { tone: 'info', rank: 0 },
};

/** The tone of a raw status value, following the x-status component. */
export function statusTone(value) {
    switch (value) {
        case 'online': case 'healthy': case 'up': case 'running': case 'valid': case 'ok': case 'verified':
            return 'ok';
        case 'offline': case 'down': case 'critical': case 'failed': case 'stopped': case 'not_found':
        case 'invalid': case 'expired': case 'open': case 'error':
        case 'not_yet_valid': case 'hostname_mismatch': case 'untrusted': case 'self_signed':
        case 'handshake_failed': case 'connection_failed':
            return 'bad';
        case 'warning': case 'degraded': case 'expiring': case 'starting': case 'acknowledged':
            return 'warn';
        case 'investigating': case 'info': case 'maintenance': case 'paused':
            return 'info';
        default:
            return 'muted';
    }
}

/**
 * A translator over a nested dictionary (the "js" group of lang/{en,nl}/nexus.php):
 * t('panel.measured', { time }). Unknown keys return the key itself.
 */
export function translator(dictionary) {
    const lookup = (key) => key.split('.').reduce((value, part) => (value && typeof value === 'object' ? value[part] : undefined), dictionary);

    const t = (key, replace = {}) => {
        let text = lookup(key);

        if (typeof text !== 'string') return key;

        for (const [name, value] of Object.entries(replace)) {
            text = text.replaceAll(`:${name}`, String(value));
        }

        return text;
    };

    // key_one / key_other, as in the lang files.
    t.count = (key, count, replace = {}) => t(`${key}_${count === 1 ? 'one' : 'other'}`, { count, ...replace });

    return t;
}

export function healthRank(health) {
    const index = HEALTH_ORDER.indexOf(health);

    return index === -1 ? HEALTH_ORDER.length : index;
}

/** A string that changes whenever nodes or edges are added or removed. */
export function structureKey(topology) {
    const nodes = topology.nodes.map((n) => n.key).sort();
    const edges = topology.edges.map((e) => e.key).sort();

    return `${nodes.join('|')}#${edges.join('|')}`;
}

/** @returns {{ dependencies: Map<string, string[]>, dependents: Map<string, string[]> }} */
export function adjacency(edges) {
    const dependencies = new Map();
    const dependents = new Map();

    for (const edge of edges) {
        if (!dependencies.has(edge.source)) dependencies.set(edge.source, []);
        if (!dependents.has(edge.target)) dependents.set(edge.target, []);
        dependencies.get(edge.source).push(edge.target);
        dependents.get(edge.target).push(edge.source);
    }

    return { dependencies, dependents };
}

/**
 * Breadth-first walk from a node along one direction, up to maxDepth
 * levels (the same limit DependencyGraph uses). The start is not included.
 *
 * @returns {Map<string, number>} node key => depth
 */
export function walk(start, next, maxDepth) {
    const seen = new Map([[start, 0]]);
    const queue = [start];

    while (queue.length) {
        const key = queue.shift();
        const depth = seen.get(key);

        if (depth >= maxDepth) continue;

        for (const other of next.get(key) ?? []) {
            if (!seen.has(other)) {
                seen.set(other, depth + 1);
                queue.push(other);
            }
        }
    }

    seen.delete(start);

    return seen;
}

/** Everything that (transitively) depends on the node: what fails with it. */
export function impactOf(key, graph, maxDepth) {
    return walk(key, graph.dependents, maxDepth);
}

/** Everything the node (transitively) depends on. */
export function upstreamOf(key, graph, maxDepth) {
    return walk(key, graph.dependencies, maxDepth);
}

/**
 * Keys of the nodes that pass the filters. In the incident view only nodes
 * with active incidents and what depends on them are kept.
 *
 * @param {{ types: Set<string>, health: Set<string>, incidents: boolean, query: string }} filters
 * @param {(node: object) => string} labelOf the displayed name (page groups have no label of their own)
 */
export function visibleKeys(topology, filters, maxDepth, labelOf = (node) => node.label ?? '') {
    const query = filters.query.trim().toLocaleLowerCase();
    let allowed = null;

    if (filters.incidents) {
        const graph = adjacency(topology.edges);
        allowed = new Set();

        for (const node of topology.nodes) {
            if (node.incidents.active > 0) {
                allowed.add(node.key);

                for (const key of impactOf(node.key, graph, maxDepth).keys()) allowed.add(key);
            }
        }
    }

    const keys = new Set();

    for (const node of topology.nodes) {
        if (!filters.types.has(node.type) || !filters.health.has(node.health)) continue;
        if (allowed && !allowed.has(node.key)) continue;
        if (query && !labelOf(node).toLocaleLowerCase().includes(query)) continue;

        keys.add(node.key);
    }

    return keys;
}

/**
 * Deterministic layered layout. A node without dependencies sits in the top
 * layer; every other node sits one layer below its deepest dependency, so
 * arrows ("depends on") point upwards. Nodes without any connection go to
 * rows of their own at the bottom. Within a layer, nodes are ordered by the
 * average position of their neighbours to reduce crossings.
 *
 * @returns {Map<string, {x: number, y: number}>}
 */
export function layeredPositions(nodes, edges, options = {}) {
    const spacingX = options.spacingX ?? 140;
    const spacingY = options.spacingY ?? 105;
    const perRow = options.perRow ?? 12;
    const labelOf = options.labelOf ?? ((node) => node.label ?? '');

    const keys = new Set(nodes.map((n) => n.key));
    const graph = adjacency(edges.filter((e) => keys.has(e.source) && keys.has(e.target)));
    const byKey = new Map(nodes.map((n) => [n.key, n]));

    // Layer = length of the longest dependency chain below the node.
    const layer = new Map();
    const visiting = new Set();
    const layerOf = (key) => {
        if (layer.has(key)) return layer.get(key);
        if (visiting.has(key)) return 0; // stored cycle: break it here

        visiting.add(key);
        let value = 0;

        for (const dependency of graph.dependencies.get(key) ?? []) {
            value = Math.max(value, layerOf(dependency) + 1);
        }

        visiting.delete(key);
        layer.set(key, value);

        return value;
    };

    const connected = [];
    const isolated = [];

    for (const node of nodes) {
        const linked = graph.dependencies.has(node.key) || graph.dependents.has(node.key);
        (linked ? connected : isolated).push(node.key);
    }

    const compare = (a, b) => {
        const na = byKey.get(a);
        const nb = byKey.get(b);

        return TYPE_ORDER.indexOf(na.type) - TYPE_ORDER.indexOf(nb.type)
            || labelOf(na).localeCompare(labelOf(nb))
            || a.localeCompare(b);
    };

    const layers = [];

    for (const key of connected) {
        const index = layerOf(key);
        (layers[index] ??= []).push(key);
    }

    for (const row of layers) row?.sort(compare);

    // Barycentre sweeps: down using dependencies, up using dependents.
    const order = new Map();
    const remember = () => layers.forEach((row) => row?.forEach((key, i) => order.set(key, i / Math.max(1, row.length - 1))));
    const sortBy = (row, neighbours) => {
        const centre = new Map(row.map((key) => {
            const positions = (neighbours.get(key) ?? []).filter((k) => order.has(k)).map((k) => order.get(k));

            return [key, positions.length ? positions.reduce((a, b) => a + b, 0) / positions.length : order.get(key)];
        }));

        row.sort((a, b) => centre.get(a) - centre.get(b) || compare(a, b));
    };

    remember();

    for (let pass = 0; pass < 3; pass++) {
        for (let i = 1; i < layers.length; i++) if (layers[i]) { sortBy(layers[i], graph.dependencies); remember(); }
        for (let i = layers.length - 2; i >= 0; i--) if (layers[i]) { sortBy(layers[i], graph.dependents); remember(); }
    }

    const rows = [];

    for (const row of layers) {
        if (!row) continue;
        for (let i = 0; i < row.length; i += perRow) rows.push(row.slice(i, i + perRow));
    }

    isolated.sort(compare);

    for (let i = 0; i < isolated.length; i += perRow) rows.push(isolated.slice(i, i + perRow));

    const positions = new Map();

    rows.forEach((row, y) => {
        row.forEach((key, i) => {
            positions.set(key, { x: (i - (row.length - 1) / 2) * spacingX, y: y * spacingY });
        });
    });

    return positions;
}

/** Counts per health state and per type, for the filters and summary. */
export function summarize(topology) {
    const health = Object.fromEntries(Object.keys(HEALTH_TONE).map((h) => [h, 0]));
    const types = Object.fromEntries(TYPE_ORDER.map((t) => [t, 0]));
    let withIncidents = 0;

    for (const node of topology.nodes) {
        health[node.health] = (health[node.health] ?? 0) + 1;
        types[node.type] = (types[node.type] ?? 0) + 1;
        if (node.incidents.active > 0) withIncidents++;
    }

    return { health, types, withIncidents, problems: health.critical + health.warning };
}

/** Only same-origin http(s) links from the payload are used. */
export function safeUrl(url, origin) {
    if (typeof url !== 'string' || url === '') return null;

    try {
        const parsed = new URL(url, origin);

        return parsed.origin === origin && (parsed.protocol === 'https:' || parsed.protocol === 'http:') ? parsed.href : null;
    } catch {
        return null;
    }
}

/** "3 min ago" style relative time, with the time.* texts of the translator. */
export function relativeTime(iso, t, now = Date.now()) {
    const time = Date.parse(iso);

    if (Number.isNaN(time)) return null;

    const seconds = Math.max(0, Math.round((now - time) / 1000));

    if (seconds < 60) return t('time.just_now');
    if (seconds < 3600) return t('time.minutes', { count: Math.floor(seconds / 60) });
    if (seconds < 86400) return t('time.hours', { count: Math.floor(seconds / 3600) });

    const days = Math.floor(seconds / 86400);

    return days === 1 ? t('time.day') : t('time.days', { count: days });
}
