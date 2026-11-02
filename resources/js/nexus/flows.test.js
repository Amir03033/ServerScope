// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { FLOW, budget, flowFor, loadOf, responseOf } from './flows.js';

const node = (key, type, extra = {}) => ({ key, type, health: 'ok', incidents: { active: 0 }, details: {}, ...extra });
const server = (key, cpu, extra = {}) => node(key, 'server', { details: { metrics: cpu === null ? null : { cpu_percent: cpu } }, ...extra });
const site = (key, ms, extra = {}) => node(key, 'domain', { details: { response_ms: ms }, ...extra });
const map = (...nodes) => new Map(nodes.map((n) => [n.key, n]));
const link = (source, target) => ({ key: `${source}->${target}`, source, target });

test('density follows the CPU of the serving server, also through a service', () => {
    const nodes = map(server('server:1', 90), server('server:2', 5), node('service:1', 'service', { parent: 'server:1' }), site('domain:1', 300));

    assert.equal(flowFor(link('domain:1', 'server:1'), nodes).density, FLOW.maxDensity);
    assert.equal(flowFor(link('domain:1', 'server:2'), nodes).density, FLOW.minDensity);
    assert.equal(loadOf(nodes.get('service:1'), nodes), 90);
    // Load not measured: the middle, never "idle server".
    assert.equal(flowFor(link('domain:1', 'server:3'), map(site('domain:1', 300), server('server:3', null))).density, 2);
});

test('speed follows the response time of the website on the link', () => {
    const fast = flowFor(link('domain:1', 'server:1'), map(site('domain:1', 100), server('server:1', 20)));
    const slow = flowFor(link('domain:1', 'server:1'), map(site('domain:1', 1500), server('server:1', 20)));
    const unknown = flowFor(link('domain:1', 'server:1'), map(site('domain:1', null), server('server:1', 20)));

    assert.ok(fast.speed > unknown.speed && unknown.speed > slow.speed);
    assert.equal(unknown.speed, FLOW.baseSpeed);
    assert.ok(fast.speed <= FLOW.maxSpeed && slow.speed >= FLOW.minSpeed);
    assert.equal(responseOf(site('a', null), site('b', 250)), 250);
});

test('a degraded end halves the flow', () => {
    const ok = flowFor(link('domain:1', 'server:1'), map(site('domain:1', 300), server('server:1', 20)));
    const warn = flowFor(link('domain:1', 'server:1'), map(site('domain:1', 300, { health: 'warning' }), server('server:1', 20)));

    assert.equal(warn.speed, ok.speed / 2);
});

test('a failing end or an incident on the serving side stops the flow', () => {
    for (const nodes of [
        map(site('domain:1', 300), server('server:1', 20, { health: 'critical' })),
        map(site('domain:1', 300, { health: 'critical' }), server('server:1', 20)),
        map(site('domain:1', 300), server('server:1', 20, { incidents: { active: 1 } })),
    ]) {
        const flow = flowFor(link('domain:1', 'server:1'), nodes);
        assert.equal(flow.state, 'stopped');
        assert.equal(flow.speed, 0);
    }
});

test('unmeasured, paused or maintenance ends are idle, not healthy', () => {
    for (const health of ['unknown', 'paused', 'maintenance']) {
        assert.equal(flowFor(link('domain:1', 'server:1'), map(site('domain:1', 300, { health }), server('server:1', 20))).state, 'idle');
    }

    assert.equal(flowFor(link('domain:9', 'server:1'), map(server('server:1', 20))).state, 'idle', 'missing end');
});

test('the bead budget keeps every flowing link visible', () => {
    const links = Array.from({ length: 500 }, (_, i) => ({ key: `l${i}`, flow: { state: 'flowing', density: 4 } }));
    links.push({ key: 'idle', flow: { state: 'idle', density: 0 } });
    const beads = budget(links, 800);

    assert.ok([...beads.values()].reduce((a, b) => a + b, 0) <= 800 + 500);
    assert.equal(beads.get('l0'), 1);
    assert.equal(beads.get('idle'), 0);
    assert.equal(budget(links.slice(0, 3), 800).get('l1'), 4);
});
