// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { clusters, distanceKm, inside, landDots, locationStates, reachOf, rings, toVector, worst } from './geo.js';

const close = (a, b) => Math.abs(a - b) < 1e-9;

test('coordinates map onto the sphere', () => {
    const north = toVector(90, 0, 2);
    assert.ok(close(north.y, 2) && close(north.x, 0));

    const p = toVector(52.37, 4.9, 1);
    assert.ok(close(Math.hypot(p.x, p.y, p.z), 1));
    assert.ok(p.y > 0, 'Amsterdam is in the north');
});

// A unit square island from (0,0) to (10,10), quantized with scale 1.
const topology = {
    type: 'Topology',
    transform: { scale: [1, 1], translate: [0, 0] },
    arcs: [[[0, 0], [10, 0], [0, 10], [-10, 0], [0, -10]]],
    objects: { land: { type: 'GeometryCollection', geometries: [{ type: 'Polygon', arcs: [[0]] }] } },
};

test('TopoJSON arcs are decoded into rings', () => {
    const r = rings(topology, 'land');

    assert.equal(r.length, 1);
    assert.deepEqual(r[0][0], [0, 0]);
    assert.deepEqual(r[0][2], [10, 10]);
});

test('points inside land are dots, the sea is not', () => {
    const r = rings(topology, 'land');

    assert.ok(inside(5, 5, r));
    assert.ok(!inside(15, 5, r));

    const dots = landDots(r, 2);
    assert.ok(dots.length > 0);
    assert.ok(dots.every(([lat, lon]) => lat >= 0 && lat <= 10 && lon >= 0 && lon <= 10));
});

const locations = [{ key: 'main' }, { key: 'probe:1' }];
const sites = [
    { id: 1, reach: { main: { state: 'ok' }, 'probe:1': { state: 'ok', at: 't1' } } },
    { id: 2, reach: { main: { state: 'down' }, 'probe:1': { state: 'ok' } } },
    { id: 3, reach: { main: { state: 'unknown' } } },
];

test('without a selection a location is down when any website is not reachable from it', () => {
    const s = locationStates(locations, sites);

    assert.deepEqual(s.get('main'), { state: 'down', ok: 1, down: 1, unknown: 1, standby: 0 });
    assert.deepEqual(s.get('probe:1'), { state: 'ok', ok: 2, down: 0, unknown: 1, standby: 0 });
});

test('with a website selected each location shows that website only; no data is unknown', () => {
    const s = locationStates(locations, sites, 3);

    assert.equal(s.get('main').state, 'unknown');
    assert.equal(s.get('probe:1').state, 'unknown', 'no confirmation: not "reachable"');
    assert.equal(locationStates(locations, sites, 1).get('probe:1').at, 't1');
});

test('the worst state of a website decides its place in the list', () => {
    assert.equal(worst(sites[1]), 'down');
    assert.equal(worst(sites[0]), 'ok');
    assert.equal(worst({ reach: {} }), 'paused');
});

test('rings across the 180° meridian are unwrapped and still found', () => {
    // An island from 170°E to 170°W (through 180°), latitude 0..10.
    const across = {
        type: 'Topology',
        transform: { scale: [1, 1], translate: [0, 0] },
        arcs: [[[170, 0], [20, 0], [0, 10], [-20, 0], [0, -10]]],
        objects: { land: { type: 'GeometryCollection', geometries: [{ type: 'Polygon', arcs: [[0]] }] } },
    };
    // Store the east end as -170 to imitate a wrapped ring: 170 -> 190 is written as 170 -> -170.
    across.arcs = [[[170, 0], [-340, 0], [0, 10], [340, 0], [0, -10]]];
    const r = rings(across, 'land');

    assert.ok(inside(175, 5, r));
    assert.ok(inside(-175, 5, r), 'west of the meridian too');
    assert.ok(!inside(0, 5, r), 'not the rest of the world');
});

test('locations on the same spot share one pin, led by the main location', () => {
    const groups = clusters([
        { key: 'probe:1', kind: 'probe', lat: 50.1155, lon: 8.6842 },
        { key: 'main', kind: 'main', lat: 50.11, lon: 8.68 },
        { key: 'probe:2', kind: 'probe', lat: 40.71, lon: -74.01 },
    ]);

    assert.deepEqual(groups.map((g) => [g.key, g.members.map((m) => m.key)]), [['main', ['main', 'probe:1']], ['probe:2', ['probe:2']]]);
    assert.ok(Math.abs(distanceKm([52.37, 4.9], [50.11, 8.68]) - 365) < 10, 'Amsterdam–Frankfurt');
});

test('a working probe with nothing to confirm is on stand-by; an offline one is not measured', () => {
    const online = { key: 'probe:1', kind: 'probe', healthy: true };
    const offline = { ...online, healthy: false };
    const quiet = [{ id: 1, reach: { main: { state: 'ok' } } }, { id: 2, reach: { main: { state: 'ok' } } }];

    assert.equal(reachOf(quiet[0], online).state, 'standby');
    assert.equal(reachOf(quiet[0], offline).state, 'unknown');
    assert.equal(reachOf(quiet[0], { key: 'main', kind: 'main', healthy: true }).state, 'ok');
    assert.deepEqual(locationStates([online], quiet).get('probe:1'), { state: 'standby', ok: 0, down: 0, unknown: 0, standby: 2 });
    assert.equal(locationStates([offline], quiet).get('probe:1').state, 'unknown');
    // A real confirmation always wins over stand-by.
    assert.equal(locationStates([online], [{ id: 1, reach: { 'probe:1': { state: 'down' } } }]).get('probe:1').state, 'down');
});
