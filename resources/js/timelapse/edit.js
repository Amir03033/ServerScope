// Time travel: the city at any moment of the period, and the automatic
// edit of the film. Pure functions (tested with Node).
//
// The edit ("director's cut"):
//   - an opening title (team, period, uptime);
//   - quiet stretches race by;
//   - around every incident the film slows down, the camera goes to the
//     tower it concerns and a caption names it, from just before it opened
//     until just after it was resolved (long incidents: the start and the
//     end, with the middle at speed);
//   - the busiest moment (highest CPU) gets a short pause and a caption;
//   - a closing card with the summary.

export const EDIT = {
    titleMs: 3200,
    endMs: 4500,
    quietFrameMs: 70,
    incidentFrameMs: 650,
    peakMs: 1800,
    incidentEdge: 3, // frames shown slowly at the start and end of a long incident
};

/** The incidents active during frame i (the frame covers [t, t + size)). */
export function activeAt(data, i) {
    const start = data.frames[i];
    const end = start + data.size;

    return data.incidents.filter((inc) => inc.opened_at < end && (inc.resolved_at === null || inc.resolved_at > start));
}

/**
 * The servers at frame i, in the shape city() expects (as serverVitals()
 * gives for the live view): CPU of that frame, its websites up or down,
 * and haywire when an incident on the tower was active.
 */
export function vitalsAt(data, i) {
    const active = activeAt(data, i);

    return data.servers.map((server) => {
        // One missing frame (an agent hiccup, or the frame still running) keeps the
        // previous value; two or more in a row: no data, lights out.
        const cpu = server.cpu[i] ?? (i > 0 ? server.cpu[i - 1] ?? null : null);
        const sites = data.sites.filter((s) => s.tower === server.key).map((s) => ({
            key: s.key,
            name: s.name,
            health: s.down[i] === 1 ? 'critical' : s.down[i] === 0 ? 'ok' : 'unknown',
        }));
        const incident = active.some((inc) => inc.tower === server.key);
        const rhythm = incident ? 'chaos' : cpu === null ? 'flat' : sites.some((s) => s.health === 'critical') ? 'chaos' : 'steady';

        return { key: server.key, name: server.name, url: null, cpu, rhythm, services: [], sites };
    });
}

/** Frame index of a moment (clamped to the period). */
export function frameOf(data, timestamp) {
    return Math.max(0, Math.min(data.frames.length - 1, Math.floor((timestamp - data.from) / data.size)));
}

/** The frame with the highest CPU of any server, or null. */
export function peak(data) {
    let best = null;

    data.servers.forEach((server) => server.cpu.forEach((cpu, i) => {
        if (cpu !== null && (best === null || cpu > best.cpu)) best = { frame: i, cpu, server: server.name, tower: server.key };
    }));

    return best;
}

/**
 * The director's cut: a list of shots. A shot is a title card
 * ({ type: 'title' | 'end' }) or a stretch of film
 * ({ type: 'play', from, to, ms, focus, caption }) that plays frames
 * from..to (inclusive) in ms milliseconds.
 */
export function autoEdit(data) {
    const n = data.frames.length;
    const marks = Array(n).fill(null); // frame => incident to show slowly

    for (const inc of [...data.incidents].sort((a, b) => a.opened_at - b.opened_at)) {
        const first = Math.max(0, frameOf(data, inc.opened_at) - 1);
        const last = Math.min(n - 1, inc.resolved_at === null ? n - 1 : frameOf(data, inc.resolved_at) + 1);

        for (let i = first; i <= last; i++) {
            const edge = i - first < EDIT.incidentEdge || last - i < EDIT.incidentEdge;
            if (edge && marks[i] === null) marks[i] = inc;
        }
    }

    const top = peak(data);
    const shots = [{ type: 'title', ms: EDIT.titleMs }];
    let i = 0;

    while (i < n) {
        const inc = marks[i];
        let j = i;

        while (j + 1 < n && marks[j + 1] === inc) j++;

        if (inc) {
            shots.push({ type: 'play', from: i, to: j, ms: (j - i + 1) * EDIT.incidentFrameMs, focus: inc.tower, caption: { kind: 'incident', incident: inc } });
        } else if (top && top.frame >= i && top.frame <= j) {
            // A quiet stretch with the busiest moment in it: race to it, pause, race on.
            if (top.frame > i) shots.push({ type: 'play', from: i, to: top.frame - 1, ms: (top.frame - i) * EDIT.quietFrameMs, focus: null, caption: null });
            shots.push({ type: 'play', from: top.frame, to: top.frame, ms: EDIT.peakMs, focus: top.tower, caption: { kind: 'peak', peak: top } });
            if (j > top.frame) shots.push({ type: 'play', from: top.frame + 1, to: j, ms: (j - top.frame) * EDIT.quietFrameMs, focus: null, caption: null });
        } else {
            shots.push({ type: 'play', from: i, to: j, ms: (j - i + 1) * EDIT.quietFrameMs, focus: null, caption: null });
        }

        i = j + 1;
    }

    shots.push({ type: 'end', ms: EDIT.endMs });

    return shots;
}

/** Total length of a cut in ms. */
export function lengthOf(shots) {
    return shots.reduce((sum, s) => sum + s.ms, 0);
}

/** Where a cut is at time t (ms): the shot, its index and the (fractional) frame. */
export function positionAt(shots, t) {
    let start = 0;

    for (let k = 0; k < shots.length; k++) {
        const shot = shots[k];

        if (t < start + shot.ms || k === shots.length - 1) {
            const p = Math.min(1, Math.max(0, (t - start) / shot.ms));
            const frame = shot.type === 'play' ? shot.from + p * (shot.to - shot.from + 1) : shot.type === 'title' ? 0 : null;

            return { shot, index: k, progress: p, frame: frame === null ? null : Math.min(frame, shot.to ?? frame) };
        }

        start += shot.ms;
    }

    return null;
}

/** Summary for the closing card. */
export function summary(data) {
    const durations = data.incidents.map((inc) => Math.min(inc.resolved_at ?? data.to, data.to) - Math.max(inc.opened_at, data.from));

    return {
        incidents: data.incidents.length,
        critical: data.incidents.filter((inc) => inc.severity === 'critical').length,
        longest: durations.length ? Math.max(...durations) : 0,
        uptime: data.meta.uptime,
        peak: peak(data),
    };
}
