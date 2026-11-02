// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { LAYOUT, arc, bounds, layout3d } from './layout3d.js';

const node = (key, type, parent = null) => ({ key, type, parent });
const edge = (source, target) => ({ key: `${source}->${target}`, source, target });

const nodes = [
    node('server:1', 'server'),
    node('server:2', 'server'),
    node('service:1', 'service', 'server:1'),
    node('service:2', 'service', 'server:1'),
    node('domain:1', 'domain'),
    node('domain:2', 'domain'),
    node('domain:3', 'domain'),
    node('page_group:1', 'page_group', 'domain:1'),
    node('page:9', 'page', 'domain:1'),
];
const edges = [
    edge('service:1', 'server:1'),
    edge('service:2', 'server:1'),
    edge('domain:1', 'server:1'),
    edge('domain:2', 'service:2'), // through a service: still orbits server 1
    edge('page_group:1', 'domain:1'),
    edge('page:9', 'domain:1'),
];

test('every visible node gets a finite position', () => {
    const p = layout3d(nodes, edges);

    assert.equal(p.size, nodes.length);

    for (const [key, pos] of p) {
        for (const axis of ['x', 'y', 'z']) assert.ok(Number.isFinite(pos[axis]), `${key}.${axis}`);
    }
});

test('towers grow with their services, which ring them level by level', () => {
    const p = layout3d(nodes, edges);
    const tower = p.get('server:1');

    assert.equal(tower.kind, 'tower');
    assert.equal(tower.height, LAYOUT.towerBase + 2 * LAYOUT.serviceStep);
    assert.equal(p.get('server:2').height, LAYOUT.towerBase);

    const [a, b] = [p.get('service:1'), p.get('service:2')];
    assert.deepEqual([a.x, a.z, a.kind], [tower.x, tower.z, 'ring']);
    assert.ok(b.y > a.y);
});

test('websites orbit their server, also through a service; others form an outer ring', () => {
    const p = layout3d(nodes, edges);
    const tower = p.get('server:1');
    const distance = (key) => Math.hypot(p.get(key).x - tower.x, p.get(key).z - tower.z);

    assert.equal(p.get('domain:1').home, 'server:1');
    assert.equal(p.get('domain:2').home, 'server:1');
    assert.ok(Math.abs(distance('domain:1') - distance('domain:2')) < 0.01, 'same orbit');
    assert.equal(p.get('domain:3').home, undefined);
    assert.ok(Math.hypot(p.get('domain:3').x, p.get('domain:3').z) > Math.hypot(p.get('domain:1').x, p.get('domain:1').z));
});

test('pages float above their website', () => {
    const p = layout3d(nodes, edges);

    for (const key of ['page_group:1', 'page:9']) {
        assert.equal(p.get(key).kind, 'satellite');
        assert.equal(p.get(key).home, 'domain:1');
        assert.ok(p.get(key).y > p.get('domain:1').y);
    }
});

test('the layout is deterministic and independent of input order', () => {
    const a = layout3d(nodes, edges);
    const b = layout3d([...nodes].reverse(), [...edges].reverse());

    assert.deepEqual([...a.entries()].sort(), [...b.entries()].sort());
});

test('a service whose server is filtered out is not dropped', () => {
    const p = layout3d(nodes.filter((n) => n.key !== 'server:1'), edges);

    assert.ok(p.has('service:1'));
    assert.ok(p.has('domain:1'), 'its website goes to the outer ring');
});

test('a single server stands in the centre; empty input gives an empty layout', () => {
    const p = layout3d([node('server:7', 'server')], []);

    assert.deepEqual([p.get('server:7').x, p.get('server:7').z], [0, 0]);
    assert.equal(layout3d([], []).size, 0);
});

test('arcs start and end at their nodes and rise in between; bounds cover everything', () => {
    const points = arc({ x: 0, y: 0, z: 0 }, { x: 10, y: 1, z: 0 });

    assert.deepEqual(points[0], [0, 0, 0]);
    assert.deepEqual(points.at(-1), [10, 1, 0]);
    assert.ok(points[12][1] > 1);

    const b = bounds(layout3d(nodes, edges));
    assert.ok(b.radius >= 6 && b.top >= 4);
});
