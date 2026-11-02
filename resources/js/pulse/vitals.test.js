// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { VITALS, beat, rateFor, serverVitals, widthFor } from './vitals.js';

const server = (id, health = 'ok', cpu = 20, extra = {}) => ({ key: `server:${id}`, type: 'server', id, label: `srv-${id}`, health, incidents: { active: 0 }, details: { metrics: cpu === null ? null : { cpu_percent: cpu } }, ...extra });
const service = (id, parent, health = 'ok') => ({ key: `service:${id}`, type: 'service', id, label: `svc-${id}`, parent, health });
const site = (id, health = 'ok', ms = 300) => ({ key: `domain:${id}`, type: 'domain', id, label: `site-${id}`, health, details: { response_ms: ms } });
const edge = (source, target) => ({ source, target });
const graph = (nodes, edges = []) => ({ nodes, edges });
const one = (g) => serverVitals(g)[0];

test('a healthy server beats steadily, faster with more CPU', () => {
    const v = one(graph([server(1, 'ok', 80)]));

    assert.equal(v.rhythm, 'steady');
    assert.ok(v.bpm > rateFor(10));
    assert.equal(rateFor(0), VITALS.minBpm);
    assert.equal(rateFor(100), VITALS.maxBpm);
    assert.equal(rateFor(null), 60);
});

test('websites count directly and through a service; the slowest widens the beat', () => {
    const v = one(graph(
        [server(1), service(1, 'server:1'), site(1, 'ok', 200), site(2, 'ok', 900), site(3, 'ok', 5000)],
        [edge('service:1', 'server:1'), edge('domain:1', 'server:1'), edge('domain:2', 'service:1')],
    ));

    assert.deepEqual(v.sites.map((s) => s.key), ['domain:1', 'domain:2'], 'domain:3 is not on this server');
    assert.equal(v.responseMs, 900);
    assert.ok(v.width > 1);
    assert.equal(widthFor(null), 1);
    assert.equal(widthFor(100000), 2.6);
});

test('a failure on the server makes the line go haywire', () => {
    assert.equal(one(graph([server(1, 'ok', 20, { incidents: { active: 1 } })])).rhythm, 'chaos');
    assert.deepEqual(one(graph([server(1), service(1, 'server:1', 'critical')], [edge('service:1', 'server:1')])).reasons, ['service_down']);
    assert.equal(one(graph([server(1), site(1, 'critical')], [edge('domain:1', 'server:1')])).rhythm, 'chaos');
});

test('warnings, high CPU and slow websites make it irregular', () => {
    assert.equal(one(graph([server(1, 'warning')])).rhythm, 'irregular');
    assert.deepEqual(one(graph([server(1, 'ok', 95)])).reasons, ['cpu_high']);
    assert.deepEqual(one(graph([server(1), site(1, 'ok', 2500)], [edge('domain:1', 'server:1')])).reasons, ['slow']);
});

test('offline is flat, not measured is unknown, paused and maintenance rest', () => {
    assert.equal(one(graph([server(1, 'critical', null)])).rhythm, 'flat');
    assert.equal(one(graph([server(1, 'unknown', null)])).rhythm, 'unknown');
    assert.equal(one(graph([server(1, 'maintenance')])).rhythm, 'rest');
    assert.equal(one(graph([server(1, 'paused')])).rhythm, 'rest');
});

test('the beat peaks at the R wave, and a broader beat is lower', () => {
    let peak = 0;
    let at = 0;

    for (let p = 0; p < 1; p += 0.001) {
        if (beat(p) > peak) [peak, at] = [beat(p), p];
    }

    assert.ok(Math.abs(at - 0.225) < 0.01);
    assert.ok(beat(0.225, 2) < beat(0.225, 1));
    assert.ok(Math.abs(beat(0.8)) < 0.02, 'flat between beats');
});
