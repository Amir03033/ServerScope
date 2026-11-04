// The wall screen's director. Pure functions (tested with Node): what to
// show now, given the active incidents and the time.
//
// - tour: through the scenes (overview, servers, skyline, websites, heartbeat,
//   jobs and deploys, globe, incidents),
//   one every cycle;
// - incident: as soon as an incident appears that was not seen before,
//   cut to it for a minute: a large alert, the camera on the tower of the
//   server it concerns, then the other scenes in turn (an incident without
//   a server, e.g. on a page, tours all scenes). Then the
//   tour continues; active incidents stay in the status and the ticker,
//   so a long incident never freezes the screen;
// - resolved: when the last incident is gone, a short "all clear" before
//   the tour continues.

export const DIRECTOR = {
    cycleMs: 12_000,
    incidentShotMs: 10_000,
    cutMs: 60_000,
    resolvedMs: 8_000,
};

const SEVERITY = { critical: 4, high: 3, warning: 2, medium: 2, low: 1, info: 1 };

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

        // A page belongs to its website, a website to the server it depends on.
        const site = node.type === 'page' || node.type === 'page_group' ? node.parent : node.key;

        return vitals.find((v) => v.sites.some((s) => s.key === site))?.key ?? null;
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
    const seen = [...new Set([...state.seen, ...ids])];

    // A new incident: cut to it at once.
    if (fresh.length > 0) {
        return { ...state, mode: 'incident', since: now, cutStart: now, seen, shot: 0, alert: fresh[0] };
    }

    if (state.mode === 'incident') {
        if (incidents.length === 0) return { ...state, mode: 'resolved', since: now, alert: null };

        // The cut is over: back to the tour (the incidents stay in the status and ticker).
        if (now - state.cutStart >= DIRECTOR.cutMs) return { ...state, mode: 'tour', since: now, alert: null };

        if (now - state.since >= DIRECTOR.incidentShotMs) {
            return { ...state, since: now, shot: state.shot + 1, alert: incidents.find((i) => i.id === state.alert?.id) ?? incidents[0] };
        }

        return state;
    }

    if (state.mode === 'resolved') {
        if (now - state.since >= DIRECTOR.resolvedMs) return { ...state, mode: 'tour', since: now, index: 0 };

        return state;
    }

    if (now - state.since >= cycleMs) return { ...state, since: now, index: (state.index + 1) % state.scenes.length };

    return state;
}

/** What to show for a state. */
export function shotFor(state, incidents) {
    if (state.mode === 'incident' && incidents.length > 0) {
        // Each incident's tower first, then every other scene: nothing is skipped.
        const towers = [...new Set(incidents.map((i) => i.tower).filter(Boolean))];
        const shots = [
            ...towers.map((tower) => ({ scene: 'skyline', focus: tower })),
            ...state.scenes.filter((scene) => !(towers.length && scene === 'skyline')).map((scene) => ({ scene, focus: null })),
        ];

        return shots[state.shot % shots.length];
    }

    if (state.mode === 'resolved') return { scene: 'summary', focus: null };

    return { scene: state.scenes[state.index], focus: null };
}
