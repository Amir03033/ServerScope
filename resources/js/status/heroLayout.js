// Layout and states of the status page 3D hero. Pure (tested with Node).

/** Public component states (StatusPagePresenter) to design tones. */
export const STATE_TONE = {
    operational: 'ok',
    degraded: 'warn',
    partial_outage: 'warn',
    major_outage: 'bad',
    maintenance: 'info',
    unknown: 'muted',
};

/** What runs along a component's beam: a flow, a jam (outage) or nothing (not measured). */
export function beamFor(state) {
    if (state === 'major_outage' || state === 'partial_outage') return 'jam';
    if (state === 'unknown' || state === 'maintenance') return 'none';

    return state === 'degraded' ? 'slow' : 'flow';
}

/**
 * Components on a ring around the core, starting at the front; a second,
 * higher ring when there are many. @returns {Array<{ x: number, y: number, z: number, angle: number, size: number }>}
 */
export function heroLayout(count) {
    const perRing = 14;
    const rings = Math.ceil(count / perRing);
    const out = [];

    for (let i = 0; i < count; i++) {
        const ring = Math.floor(i / perRing);
        const inRing = Math.min(perRing, count - ring * perRing);
        const angle = Math.PI / 2 + ((i % perRing) / inRing) * Math.PI * 2;
        const radius = 4.2 + Math.min(inRing, perRing) * 0.18 + ring * 1.6;

        out.push({
            x: Math.round(Math.cos(angle) * radius * 1000) / 1000,
            y: ring * 1.4,
            z: Math.round(Math.sin(angle) * radius * 1000) / 1000,
            angle,
            size: count > 20 ? 0.45 : count > 8 ? 0.6 : 0.8,
        });
    }

    return rings > 2 ? out.map((p) => ({ ...p, size: 0.4 })) : out;
}
