// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { CITY, city, heightFor, windowsFor } from './model.js';

const vitals = (key, extra = {}) => ({ key, name: key, url: null, rhythm: 'steady', cpu: 40, services: [], sites: [], ...extra });

test('busier servers stand taller; not measured stands low', () => {
    assert.equal(heightFor(0), CITY.minHeight);
    assert.equal(heightFor(100), CITY.maxHeight);
    assert.ok(heightFor(80) > heightFor(20));
    assert.equal(heightFor(null), CITY.unmeasuredHeight);
});

test('windows are blocks of services, coloured by their state', () => {
    const w = windowsFor([{ health: 'ok' }, { health: 'critical' }], 3, 'on');

    assert.equal(w.length, 3 * CITY.columns);
    assert.deepEqual([...new Set(w)], ['lit', 'bad']);
    assert.equal(w.filter((s) => s === 'bad').length, 6);
    assert.ok(windowsFor([], 2, 'on').every((s) => s === 'lit'), 'no services: the whole tower is lit');
});

test('an offline server goes dark, a paused one is dim', () => {
    const [tower] = city([vitals('server:1', { rhythm: 'flat', services: [{ health: 'ok' }] })]);

    assert.equal(tower.lights, 'out');
    assert.ok(tower.windows.every((s) => s === 'dark'));
    assert.equal(city([vitals('server:1', { rhythm: 'rest' })])[0].lights, 'dim');
    assert.equal(city([vitals('server:1', { rhythm: 'chaos' })])[0].lights, 'flicker');
});

test('towers stand on a grid around the centre; at most six beacons', () => {
    const towers = city(Array.from({ length: 5 }, (_, i) => vitals(`server:${i}`, { sites: Array.from({ length: 8 }, (__, k) => ({ key: `domain:${k}`, name: 'x', health: k === 0 ? 'critical' : 'ok' })) })));

    assert.equal(towers.length, 5);
    assert.equal(new Set(towers.map((t) => `${t.x},${t.z}`)).size, 5);
    assert.ok(Math.abs(towers.reduce((s, t) => s + t.x, 0)) < CITY.spacing * 2);
    assert.equal(towers[0].beacons.length, CITY.maxBeacons);
    assert.equal(towers[0].hiddenBeacons, 2);
    assert.equal(towers[0].beacons[0].state, 'bad');
});
