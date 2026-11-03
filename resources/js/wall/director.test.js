// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { DIRECTOR, direct, incidentsFrom, initialState, shotFor } from './director.js';

const SCENES = ['skyline', 'globe', 'pulse', 'summary'];
const incident = (id, severity = 'critical', tower = 'server:1') => ({ id, title: `i${id}`, severity, openedAt: `2026-11-03T0${id}:00:00Z`, tower });

test('the tour moves on every cycle', () => {
    let s = initialState(0, SCENES);
    assert.equal(shotFor(s, []).scene, 'skyline');
    s = direct(s, [], 1000);
    assert.equal(s.index, 0);
    s = direct(s, [], DIRECTOR.cycleMs + 1);
    assert.equal(shotFor(s, []).scene, 'globe');
});

test('a new incident cuts in at once and focuses its tower, then the heartbeat', () => {
    let s = direct(initialState(0, SCENES), [], 5000);
    s = direct(s, [incident(1)], 6000);

    assert.equal(s.mode, 'incident');
    assert.equal(s.alert.id, 1);
    assert.deepEqual(shotFor(s, [incident(1)]), { scene: 'skyline', focus: 'server:1' });
    s = direct(s, [incident(1)], 6000 + DIRECTOR.incidentShotMs);
    assert.equal(shotFor(s, [incident(1)]).scene, 'pulse');
    s = direct(s, [incident(1)], 6000 + DIRECTOR.incidentShotMs * 2);
    assert.equal(shotFor(s, [incident(1)]).scene, 'skyline');

    // A second, new incident becomes the alert.
    s = direct(s, [incident(2), incident(1)], 40_000);
    assert.equal(s.alert.id, 2);
});

test('an incident without a server keeps touring the scenes', () => {
    let s = direct(initialState(0, SCENES), [incident(1, 'high', null)], 0);
    const scenes = [];

    for (let k = 0; k < 4; k++) {
        scenes.push(shotFor(s, [incident(1, 'high', null)]).scene);
        s = direct(s, [incident(1, 'high', null)], (k + 1) * DIRECTOR.incidentShotMs);
    }

    assert.deepEqual(scenes, SCENES);
});

test('a long incident never freezes the screen: after the cut the tour continues', () => {
    let s = direct(initialState(0, SCENES), [incident(1)], 0);
    s = direct(s, [incident(1)], DIRECTOR.cutMs + 1);

    assert.equal(s.mode, 'tour');
    assert.equal(s.alert, null);
    s = direct(s, [incident(1)], DIRECTOR.cutMs + DIRECTOR.cycleMs + 2);
    assert.equal(s.mode, 'tour', 'the same incident does not cut in again');
    assert.notEqual(shotFor(s, [incident(1)]).focus, 'server:1');
});

test('when the last incident is gone: all clear, then the tour again', () => {
    let s = direct(initialState(0, SCENES), [incident(1)], 1000);
    s = direct(s, [], 2000);
    assert.equal(s.mode, 'resolved');
    assert.equal(shotFor(s, []).scene, 'summary');
    s = direct(s, [], 2000 + DIRECTOR.resolvedMs);
    assert.equal(s.mode, 'tour');
    assert.ok(s.seen.includes(1));
});

test('incidents come from the topology, once each, worst first, with their tower', () => {
    const graph = {
        nodes: [
            { key: 'server:1', type: 'server', label: 'db', incidents: { active: 1, primary: { id: 7, title: 'db down', severity: 'critical', opened_at: '2026-11-03T10:00:00Z' } } },
            { key: 'service:3', type: 'service', parent: 'server:2', label: 'nginx', incidents: { active: 1, primary: { id: 8, title: 'nginx', severity: 'medium', opened_at: '2026-11-03T11:00:00Z' } } },
            { key: 'domain:5', type: 'domain', label: 'shop', incidents: { active: 1, primary: { id: 9, title: 'shop', severity: 'high', opened_at: '2026-11-03T12:00:00Z' } } },
            { key: 'domain:6', type: 'domain', label: 'old', incidents: { active: 0, primary: null } },
        ],
    };
    const vitals = [{ key: 'server:2', sites: [{ key: 'domain:5' }] }];

    assert.deepEqual(incidentsFrom(graph, vitals).map((i) => [i.id, i.tower]), [[7, 'server:1'], [9, 'server:2'], [8, 'server:2']]);
});
