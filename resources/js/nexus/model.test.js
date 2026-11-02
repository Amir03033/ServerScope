// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    adjacency, impactOf, layeredPositions, relativeTime, safeUrl, statusTone, structureKey, summarize, translator, upstreamOf, visibleKeys,
} from './model.js';

const node = (key, type, health = 'ok', label = key, active = 0) => ({
    key, type, health, label, incidents: { active, max_severity: active ? 'critical' : null, primary: null },
});
const edge = (source, target, origin = 'confirmed') => ({ key: `${source}->${target}`, source, target, origin });

// db <- mysql <- web <- shop <- pages ; blog unconnected
const topology = {
    nodes: [
        node('server:1', 'server', 'ok', 'db-01'),
        node('service:1', 'service', 'critical', 'mysql', 1),
        node('server:2', 'server', 'ok', 'web-01'),
        node('domain:1', 'domain', 'warning', 'shop.example.com'),
        { ...node('page_group:1', 'page_group', 'ok', null), details: { pages: { total: 3 } } },
        node('domain:2', 'domain', 'paused', 'blog.example.com'),
    ],
    edges: [
        edge('service:1', 'server:1', 'structural'),
        edge('server:2', 'service:1'),
        edge('domain:1', 'server:2'),
        edge('page_group:1', 'domain:1', 'structural'),
    ],
    meta: { max_depth: 6 },
};
const all = () => ({ types: new Set(['server', 'service', 'domain', 'page_group', 'page']), health: new Set(['ok', 'warning', 'critical', 'unknown', 'maintenance', 'paused']), incidents: false, query: '' });

test('impact walks against the edge direction, upstream with it', () => {
    const graph = adjacency(topology.edges);

    assert.deepEqual([...impactOf('server:1', graph, 6).keys()], ['service:1', 'server:2', 'domain:1', 'page_group:1']);
    assert.deepEqual([...upstreamOf('page_group:1', graph, 6).keys()], ['domain:1', 'server:2', 'service:1', 'server:1']);
    assert.equal(impactOf('page_group:1', graph, 6).size, 0);
});

test('the walk stops at the maximum depth and survives cycles', () => {
    const chain = Array.from({ length: 9 }, (_, i) => `server:${i}`);
    const edges = chain.slice(1).map((key, i) => edge(key, chain[i]));

    assert.equal(impactOf('server:0', adjacency(edges), 6).size, 6);

    const cycle = adjacency([edge('a', 'b'), edge('b', 'c'), edge('c', 'a')]);
    assert.equal(impactOf('a', cycle, 6).size, 2);
});

test('dependencies sit above their dependents; unconnected nodes go last', () => {
    const positions = layeredPositions(topology.nodes, topology.edges);
    const y = (key) => positions.get(key).y;

    assert.ok(y('server:1') < y('service:1'));
    assert.ok(y('service:1') < y('server:2'));
    assert.ok(y('server:2') < y('domain:1'));
    assert.ok(y('domain:1') < y('page_group:1'));
    assert.ok(y('domain:2') > y('page_group:1'), 'unconnected node below the graph');
    assert.equal(positions.size, topology.nodes.length);
});

test('layout is deterministic and terminates on stored cycles', () => {
    const a = layeredPositions(topology.nodes, topology.edges);
    const b = layeredPositions([...topology.nodes].reverse(), [...topology.edges].reverse());

    assert.deepEqual([...a.entries()].sort(), [...b.entries()].sort());

    const cyclic = layeredPositions([node('a', 'server'), node('b', 'server')], [edge('a', 'b'), edge('b', 'a')]);
    assert.equal(cyclic.size, 2);
});

test('filters by type, health, name and incidents', () => {
    const labelOf = (n) => (n.type === 'page_group' ? `${n.details.pages.total} pages` : n.label);
    const only = (patch) => [...visibleKeys(topology, { ...all(), ...patch }, 6, labelOf)].sort();

    assert.equal(visibleKeys(topology, all(), 6, labelOf).size, 6);
    assert.deepEqual(only({ types: new Set(['domain']) }), ['domain:1', 'domain:2']);
    assert.deepEqual(only({ health: new Set(['critical', 'warning']) }), ['domain:1', 'service:1']);
    assert.deepEqual(only({ query: 'SHOP' }), ['domain:1']);
    assert.deepEqual(only({ query: 'pages' }), ['page_group:1'], 'page groups are found by their displayed name');
    // Incident view: the failing node and everything that depends on it.
    assert.deepEqual(only({ incidents: true }), ['domain:1', 'page_group:1', 'server:2', 'service:1']);
});

test('structure key ignores order and data, not membership', () => {
    const reordered = { ...topology, nodes: [...topology.nodes].reverse().map((n) => ({ ...n, health: 'critical' })) };

    assert.equal(structureKey(reordered), structureKey(topology));
    assert.notEqual(structureKey({ ...topology, edges: topology.edges.slice(1) }), structureKey(topology));
});

test('summary counts', () => {
    const totals = summarize(topology);

    assert.equal(totals.problems, 2);
    assert.equal(totals.withIncidents, 1);
    assert.equal(totals.types.server, 2);
});

test('only same-origin http(s) links are used', () => {
    const origin = 'https://monitor.example.com';

    assert.equal(safeUrl('https://monitor.example.com/servers/1', origin), 'https://monitor.example.com/servers/1');
    assert.equal(safeUrl('/servers/1', origin), 'https://monitor.example.com/servers/1');
    assert.equal(safeUrl('javascript:alert(1)', origin), null);
    assert.equal(safeUrl('https://evil.example.net/x', origin), null);
    assert.equal(safeUrl(null, origin), null);
});

test('status tones follow the x-status component', () => {
    assert.equal(statusTone('online'), 'ok');
    assert.equal(statusTone('failed'), 'bad');
    assert.equal(statusTone('degraded'), 'warn');
    assert.equal(statusTone('maintenance'), 'info');
    assert.equal(statusTone('paused'), 'info');
    assert.equal(statusTone('something-new'), 'muted');
});

test('translator: nested keys, placeholders, one/other and unknown keys', () => {
    const t = translator({ time: { minutes: ':count min ago' }, pages_one: ':count page', pages_other: ':count pages' });

    assert.equal(t('time.minutes', { count: 5 }), '5 min ago');
    assert.equal(t.count('pages', 1), '1 page');
    assert.equal(t.count('pages', 3), '3 pages');
    assert.equal(t('missing.key'), 'missing.key');
    assert.equal(t('time'), 'time', 'a group is not a text');
});

test('relative time uses the translated texts', () => {
    const now = Date.parse('2026-11-02T12:00:00Z');
    const t = translator({ time: { just_now: 'just now', minutes: ':count min ago', hours: ':count h ago', day: '1 day ago', days: ':count days ago' } });

    assert.equal(relativeTime('2026-11-02T11:59:30Z', t, now), 'just now');
    assert.equal(relativeTime('2026-11-02T11:48:00Z', t, now), '12 min ago');
    assert.equal(relativeTime('2026-11-01T12:00:00Z', t, now), '1 day ago');
    assert.equal(relativeTime('not a date', t, now), null);
});
