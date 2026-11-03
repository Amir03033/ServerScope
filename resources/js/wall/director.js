// The wall screen's director. Pure functions (tested with Node): what to
// show now, given the active incidents and the time.
//
// - tour: calmly through the scenes (skyline, globe, heartbeat, summary),
//   one every cycle;
// - incident: as soon as an incident appears that was not seen before,
//   cut to it: a large alert, and the camera on the tower of the server
//   it concerns; while incidents are active, alternate between their
//   towers and the heartbeat;
// - resolved: when the last incident is gone, a short "all clear" before
//   the tour continues.

export const DIRECTOR = {
    cycleMs: 25_000,
    incidentShotMs: 12_000,
    resolvedMs: 8_000,
};

const SEVERITY = { critical: 3, warning: 2, info: 1 };

/**
 * Active incidents from the topology, once each, worst and newest first,
 * with the server tower to show (the server itself, the server a service
 * runs on, or the server a website depends on).
 */
export function incidentsFrom(graph, vitals) {
    const nodes = new Map((graph?.nodes ?? []).map((n) => [n.key, n]));
    const towerOf = (node) => {
        if (node.type === 'server') return node.key;
        if (node.type === 'service') return node.parent ?? null;

        return vitals.find((v) => v.sites.some((s) => s.key === node.key))?.key ?? null;
    };
    const out = new Map();

    for (const node of nodes.values()) {
        const primary = node.incidents?.primary;

        if (!primary || (node.incidents.active ?? 0) === 0 || out.has(primary.id)) continue;

        out.set(primary.id, {
            id: primary.id,
            title: primary.title,
            severity: primary.severity,
            openedAt: primary.opened_at ?? null,
            url: primary.url ?? null,
            node: node.key,
            label: node.label,
            tower: towerOf(node),
        });
    }

    return [...out.values()].sort((a, b) => (SEVERITY[b.severity] ?? 0) - (SEVERITY[a.severity] ?? 0) || String(b.openedAt).localeCompare(String(a.openedAt)));
}

export function initialState(now, scenes) {
    return { mode: 'tour', scenes, index: 0, since: now, seen: [], shot: 0, alert: null };
}

/**
 * The next state. A shot is { scene, focus } where focus is a tower key
 * (skyline) or null.
 *
 * @returns {{ mode: string, scenes: string[], index: number, since: number, seen: Array, shot: number, alert: ?object }}
 */
export function direct(state, incidents, now, { cycleMs = DIRECTOR.cycleMs } = {}) {
    const ids = incidents.map((i) => i.id);
    const fresh = incidents.filter((i) => !state.seen.includes(i.id));

    // A new incident: cut to it at once.
    if (fresh.length > 0) {
        return { ...state, mode: 'incident', since: now, seen: [...new Set([...state.seen, ...ids])], shot: 0, alert: fresh[0] };
    }

    if (state.mode === 'incident') {
        if (incidents.length === 0) return { ...state, mode: 'resolved', since: now, alert: null };

        // Next shot: each incident's tower in turn, with the heartbeat in between.
        if (now - state.since >= DIRECTOR.incidentShotMs) {
            const shots = incidents.length * 2;

            return { ...state, since: now, shot: (state.shot + 1) % shots, alert: incidents.find((i) => i.id === state.alert?.id) ?? incidents[0] };
        }

        return state;
    }

    if (state.mode === 'resolved') {
        if (now - state.since >= DIRECTOR.resolvedMs) return { ...state, mode: 'tour', since: now, index: 0 };

        return state;
    }

    // Tour. Incidents still active (seen before, e.g. after a reload): stay with them.
    if (incidents.length > 0) return { ...state, mode: 'incident', since: now, shot: 0, alert: incidents[0], seen: [...new Set([...state.seen, ...ids])] };

    if (now - state.since >= cycleMs) return { ...state, since: now, index: (state.index + 1) % state.scenes.length };

    return state;
}

/** What to show for a state. */
export function shotFor(state, incidents) {
    if (state.mode === 'incident' && incidents.length > 0) {
        const incident = incidents[Math.floor(state.shot / 2) % incidents.length];

        return state.shot % 2 === 0 && incident.tower ? { scene: 'skyline', focus: incident.tower } : { scene: 'pulse', focus: incident.tower };
    }

    if (state.mode === 'resolved') return { scene: 'summary', focus: null };

    return { scene: state.scenes[state.index], focus: null };
}
