// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { STATE_TONE, beamFor, heroLayout } from './heroLayout.js';

test('every public state has a tone and a beam', () => {
    for (const state of ['operational', 'degraded', 'partial_outage', 'major_outage', 'maintenance', 'unknown']) {
        assert.ok(STATE_TONE[state]);
        assert.ok(['flow', 'slow', 'jam', 'none'].includes(beamFor(state)));
    }

    assert.equal(beamFor('major_outage'), 'jam');
    assert.equal(beamFor('unknown'), 'none', 'not measured is not "working"');
    assert.equal(beamFor('operational'), 'flow');
});

test('components sit on a ring around the core, the first at the front', () => {
    const p = heroLayout(6);

    assert.equal(p.length, 6);
    assert.ok(Math.abs(p[0].x) < 0.001 && p[0].z > 0);

    const r = p.map((c) => Math.hypot(c.x, c.z));
    assert.ok(Math.max(...r) - Math.min(...r) < 0.001);
});

test('many components get a second ring and smaller shapes', () => {
    const p = heroLayout(30);

    assert.equal(p.length, 30);
    assert.ok(p[29].y > p[0].y);
    assert.ok(p[0].size < heroLayout(4)[0].size);
    assert.deepEqual(heroLayout(0), []);
});
