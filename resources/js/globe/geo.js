// Geography for the NEXUS globe. Pure functions (tested with Node).

/** Latitude/longitude (degrees) to a point on a sphere of radius r (y up, lon 0 facing +z). */
export function toVector(lat, lon, r = 1) {
    const phi = (90 - lat) * (Math.PI / 180);
    const theta = (lon + 180) * (Math.PI / 180);

    return { x: -r * Math.sin(phi) * Math.cos(theta), y: r * Math.cos(phi), z: r * Math.sin(phi) * Math.sin(theta) };
}

/** The rings ([lon, lat] points) of a TopoJSON object (quantized, delta-encoded arcs). */
export function rings(topology, name) {
    const [sx, sy] = topology.transform?.scale ?? [1, 1];
    const [tx, ty] = topology.transform?.translate ?? [0, 0];
    const arcs = topology.arcs.map((arc) => {
        let x = 0;
        let y = 0;

        return arc.map(([dx, dy]) => {
            x += dx;
            y += dy;

            return [x * sx + tx, y * sy + ty];
        });
    });
    const arc = (i) => (i >= 0 ? arcs[i] : [...arcs[~i]].reverse());
    // Rings that cross the 180° meridian are made continuous (longitudes may
    // then run past ±180); inside() also tries the point shifted by 360°.
    const unwrap = (points) => {
        let shift = 0;

        return points.map(([x, y], i) => {
            if (i > 0) {
                const previous = points[i - 1][0];
                if (x - previous > 180) shift -= 360;
                if (x - previous < -180) shift += 360;
            }

            return [x + shift, y];
        });
    };
    const ring = (indexes) => unwrap(indexes.flatMap((i, n) => (n === 0 ? arc(i) : arc(i).slice(1))));
    const out = [];
    const walk = (geometry) => {
        if (geometry.type === 'GeometryCollection') geometry.geometries.forEach(walk);
        if (geometry.type === 'Polygon') geometry.arcs.forEach((r) => out.push(ring(r)));
        if (geometry.type === 'MultiPolygon') geometry.arcs.forEach((p) => p.forEach((r) => out.push(ring(r))));
    };

    walk(topology.objects[name]);

    return out;
}

/** Bounding box of a ring, cached on it, so most rings are skipped at once. */
function box(ring) {
    if (!ring.box) {
        let [minX, minY, maxX, maxY] = [Infinity, Infinity, -Infinity, -Infinity];

        for (const [x, y] of ring) {
            minX = Math.min(minX, x); maxX = Math.max(maxX, x);
            minY = Math.min(minY, y); maxY = Math.max(maxY, y);
        }

        ring.box = [minX, minY, maxX, maxY];
    }

    return ring.box;
}

/** Even-odd test of a point against all rings (holes count once more). */
export function inside(lon, lat, allRings) {
    let hit = false;

    for (const ring of allRings) {
        const [minX, minY, maxX, maxY] = box(ring);

        if (lat < minY || lat > maxY) continue;

        // The same point one turn east or west, for rings past ±180°.
        const x = lon >= minX && lon <= maxX ? lon : lon + 360 >= minX && lon + 360 <= maxX ? lon + 360 : lon - 360 >= minX && lon - 360 <= maxX ? lon - 360 : null;

        if (x === null) continue;

        let inRing = false;

        for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
            const [xi, yi] = ring[i];
            const [xj, yj] = ring[j];

            if ((yi > lat) !== (yj > lat) && x < ((xj - xi) * (lat - yi)) / (yj - yi) + xi) inRing = !inRing;
        }

        if (inRing) hit = !hit;
    }

    return hit;
}

/**
 * Dots on land, roughly evenly spread over the sphere (fewer per degree of
 * longitude towards the poles). @returns {Array<[number, number]>} [lat, lon]
 */
export function landDots(allRings, step = 2) {
    const dots = [];

    for (let lat = -84; lat <= 84; lat += step) {
        const perimeterStep = step / Math.max(Math.cos((lat * Math.PI) / 180), 0.2);

        for (let lon = -180; lon < 180; lon += perimeterStep) {
            if (inside(lon, lat, allRings)) dots.push([lat, Math.round(lon * 100) / 100]);
        }
    }

    return dots;
}

const RANK = { down: 3, unknown: 1, paused: 0, maintenance: 0, ok: 2 };

/**
 * Per location: how many websites were reachable, not reachable or not
 * known. With a website selected, that website's state only.
 *
 * @returns {Map<string, { state: string, ok: number, down: number, unknown: number, at?: string }>}
 */
export function locationStates(locations, sites, siteId = null) {
    const out = new Map();

    for (const location of locations) {
        if (siteId !== null) {
            const reach = sites.find((s) => s.id === siteId)?.reach?.[location.key];
            out.set(location.key, { state: reach?.state ?? 'unknown', ok: 0, down: 0, unknown: 0, at: reach?.at });

            continue;
        }

        const counts = { ok: 0, down: 0, unknown: 0 };

        for (const site of sites) {
            const state = site.reach?.[location.key]?.state;
            counts[state === 'ok' ? 'ok' : state === 'down' ? 'down' : 'unknown']++;
        }

        out.set(location.key, { state: counts.down > 0 ? 'down' : counts.ok > 0 ? 'ok' : 'unknown', ...counts });
    }

    return out;
}

/** The worst state of a website over all locations (for sorting the list). */
export function worst(site) {
    return Object.values(site.reach ?? {}).reduce((w, r) => ((RANK[r.state] ?? 1) > (RANK[w] ?? 1) ? r.state : w), 'paused');
}
