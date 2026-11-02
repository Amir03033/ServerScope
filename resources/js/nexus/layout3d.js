// Positions for the 3D NEXUS view. Pure functions (no three.js), so the
// layout is tested with Node's test runner like model.js.
//
// - Servers stand as towers on a sunflower spiral around the centre; a
//   tower is taller the more services run on it.
// - Services are rings around their server's tower, one level each.
// - Websites orbit the server they depend on (directly, or through a
//   service on it); websites without one form an outer ring.
// - Pages and page groups float as satellites just above their website.
// - Anything else (a resource whose parent is filtered out) joins the
//   outer ring, so nothing is ever dropped.

const GOLDEN_ANGLE = Math.PI * (3 - Math.sqrt(5));

export const LAYOUT = {
    serverSpacing: 8,
    towerBase: 2,
    serviceStep: 1.1,
    orbitRadius: 3.2,
    orbitPerSite: 0.35,
    orbitHeight: 1.4,
    satelliteRise: 1.9,
    satelliteRadius: 1.1,
    outerGap: 6,
};

const round = (value) => Math.round(value * 1000) / 1000;

/**
 * @param {Array<object>} nodes visible topology nodes ({ key, type, parent })
 * @param {Array<object>} edges topology edges ({ source, target }) between visible nodes
 * @returns {Map<string, { x: number, y: number, z: number, kind: string, height?: number, home?: string }>}
 */
export function layout3d(nodes, edges) {
    const byKey = new Map(nodes.map((n) => [n.key, n]));
    const positions = new Map();
    const sorted = (list) => [...list].sort((a, b) => a.key.localeCompare(b.key, 'en', { numeric: true }));

    // Servers and their services.
    const servers = sorted(nodes.filter((n) => n.type === 'server'));
    const servicesOf = new Map();

    for (const service of sorted(nodes.filter((n) => n.type === 'service'))) {
        if (service.parent && byKey.has(service.parent)) {
            if (!servicesOf.has(service.parent)) servicesOf.set(service.parent, []);
            servicesOf.get(service.parent).push(service);
        }
    }

    let reach = 0;

    servers.forEach((server, i) => {
        const radius = servers.length === 1 ? 0 : LAYOUT.serverSpacing * Math.sqrt(i + 0.5);
        const angle = i * GOLDEN_ANGLE;
        const services = servicesOf.get(server.key) ?? [];
        const height = LAYOUT.towerBase + services.length * LAYOUT.serviceStep;
        const x = round(Math.cos(angle) * radius);
        const z = round(Math.sin(angle) * radius);

        positions.set(server.key, { x, y: 0, z, kind: 'tower', height: round(height) });
        reach = Math.max(reach, radius);

        services.forEach((service, level) => {
            positions.set(service.key, { x, y: round(LAYOUT.towerBase * 0.6 + level * LAYOUT.serviceStep), z, kind: 'ring', home: server.key });
        });
    });

    // The server a website depends on: directly, or through one of its services.
    const homeOf = (key) => {
        for (const edge of edges) {
            if (edge.source !== key) continue;

            const target = byKey.get(edge.target);

            if (target?.type === 'server') return target.key;
            if (target?.type === 'service' && target.parent && positions.has(target.parent)) return target.parent;
        }

        return null;
    };

    const domains = sorted(nodes.filter((n) => n.type === 'domain'));
    const orbits = new Map();
    const outer = [];

    for (const domain of domains) {
        const home = homeOf(domain.key);
        home && positions.has(home) ? (orbits.has(home) ? orbits.get(home).push(domain) : orbits.set(home, [domain])) : outer.push(domain);
    }

    for (const [home, sites] of orbits) {
        const centre = positions.get(home);
        const radius = LAYOUT.orbitRadius + sites.length * LAYOUT.orbitPerSite;
        const offset = Math.atan2(centre.z, centre.x) || 0;

        sites.forEach((site, i) => {
            const angle = offset + (i / sites.length) * Math.PI * 2;
            positions.set(site.key, {
                x: round(centre.x + Math.cos(angle) * radius), y: LAYOUT.orbitHeight, z: round(centre.z + Math.sin(angle) * radius), kind: 'orb', home,
            });
            reach = Math.max(reach, Math.hypot(centre.x, centre.z) + radius);
        });
    }

    // Satellites: pages and page groups above their website.
    const satellitesOf = new Map();
    const rest = [];

    for (const node of sorted(nodes.filter((n) => n.type === 'page' || n.type === 'page_group'))) {
        const parent = node.parent && byKey.has(node.parent) ? node.parent : null;
        (parent ? (satellitesOf.has(parent) ? satellitesOf.get(parent) : satellitesOf.set(parent, []).get(parent)) : rest).push(node);
    }

    // Services whose server is not shown, and websites without a server, go to the outer ring.
    for (const service of nodes.filter((n) => n.type === 'service' && !positions.has(n.key))) rest.push(service);

    const ring = sorted([...outer, ...rest]);
    const outerRadius = reach + LAYOUT.outerGap;

    ring.forEach((node, i) => {
        const angle = (i / Math.max(ring.length, 1)) * Math.PI * 2;
        positions.set(node.key, {
            x: round(Math.cos(angle) * outerRadius), y: LAYOUT.orbitHeight, z: round(Math.sin(angle) * outerRadius), kind: node.type === 'service' ? 'ring' : 'orb',
        });
    });

    // Satellites last: their website may sit on the outer ring.
    for (const [parent, satellites] of satellitesOf) {
        const base = positions.get(parent);

        if (!base) continue;

        satellites.forEach((node, i) => {
            const angle = (i / satellites.length) * Math.PI * 2;
            const spread = satellites.length === 1 ? 0 : LAYOUT.satelliteRadius;
            positions.set(node.key, {
                x: round(base.x + Math.cos(angle) * spread), y: round(base.y + LAYOUT.satelliteRise), z: round(base.z + Math.sin(angle) * spread), kind: 'satellite', home: parent,
            });
        });
    }

    return positions;
}

/**
 * Points of an arc from a to b, lifted in the middle so links do not run
 * through towers. @returns {Array<[number, number, number]>}
 */
export function arc(a, b, segments = 24) {
    const distance = Math.hypot(b.x - a.x, b.y - a.y, b.z - a.z);
    const lift = Math.min(6, 0.8 + distance * 0.22);
    const control = { x: (a.x + b.x) / 2, y: Math.max(a.y, b.y) + lift, z: (a.z + b.z) / 2 };
    const points = [];

    for (let i = 0; i <= segments; i++) {
        const t = i / segments;
        const u = 1 - t;
        points.push([
            u * u * a.x + 2 * u * t * control.x + t * t * b.x,
            u * u * a.y + 2 * u * t * control.y + t * t * b.y,
            u * u * a.z + 2 * u * t * control.z + t * t * b.z,
        ]);
    }

    return points;
}

/** Where to aim the camera so every position is in view. */
export function bounds(positions) {
    let radius = 0;
    let top = 0;

    for (const p of positions.values()) {
        radius = Math.max(radius, Math.hypot(p.x, p.z));
        top = Math.max(top, p.y + (p.height ?? 0));
    }

    return { radius: Math.max(radius, 6), top: Math.max(top, 4) };
}
