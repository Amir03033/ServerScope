// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import { EDIT, activeAt, autoEdit, frameOf, lengthOf, peak, positionAt, summary, vitalsAt } from './edit.js';

// 24 frames of an hour each.
const data = (incidents = [], cpu = null) => ({
    from: 0,
    to: 24 * 3600,
    size: 3600,
    frames: Array.from({ length: 24 }, (_, i) => i * 3600),
    servers: [
        { key: 'server:1', name: 'db', cpu: cpu ?? Array.from({ length: 24 }, (_, i) => (i === 20 ? 95 : 20)) },
        { key: 'server:2', name: 'web', cpu: Array(24).fill(null) },
    ],
    sites: [{ key: 'domain:1', name: 'shop', tower: 'server:1', down: Array.from({ length: 24 }, (_, i) => (i === 5 ? 1 : 0)) }],
    incidents,
    meta: { uptime: 99.5 },
});
const incident = (id, open, resolve, tower = 'server:1') => ({ id, title: `inc ${id}`, severity: 'critical', opened_at: open, resolved_at: resolve, tower });

test('the city at a frame: CPU, websites, haywire during an incident, flat without data', () => {
    const d = data([incident(1, 10 * 3600 + 60, 12 * 3600)]);

    assert.equal(vitalsAt(d, 3)[0].rhythm, 'steady');
    assert.equal(vitalsAt(d, 5)[0].rhythm, 'chaos', 'website down');
    assert.equal(vitalsAt(d, 10)[0].rhythm, 'chaos', 'incident active');
    assert.equal(vitalsAt(d, 12)[0].rhythm, 'steady', 'resolved at the start of frame 12');
    assert.equal(vitalsAt(d, 3)[1].rhythm, 'flat');
    const gap = data([], Array.from({ length: 24 }, (_, i) => (i === 7 || i === 23 ? null : 30)));
    assert.equal(vitalsAt(gap, 7)[0].cpu, 30, 'one missing frame keeps the previous value');
    assert.equal(vitalsAt(gap, 23)[0].rhythm, 'steady', 'the frame still running is not "lights out"');
    assert.equal(vitalsAt(d, 3)[0].sites[0].health, 'ok');
    assert.equal(activeAt(d, 11).length, 1);
    assert.equal(frameOf(d, 99 * 3600), 23);
});

test('quiet periods race by, the busiest moment gets a pause', () => {
    const shots = autoEdit(data());

    assert.equal(shots[0].type, 'title');
    assert.equal(shots.at(-1).type, 'end');
    const peakShot = shots.find((s) => s.caption?.kind === 'peak');
    assert.equal(peakShot.from, 20);
    assert.equal(peakShot.focus, 'server:1');
    // Every frame is played exactly once.
    const played = shots.filter((s) => s.type === 'play').flatMap((s) => Array.from({ length: s.to - s.from + 1 }, (_, k) => s.from + k));
    assert.deepEqual(played, Array.from({ length: 24 }, (_, i) => i));
    assert.equal(peak(data()).cpu, 95);
});

test('incidents are slowed down and focused; long ones keep their start and end', () => {
    const shots = autoEdit(data([incident(1, 2 * 3600, 3 * 3600), incident(2, 6 * 3600, 18 * 3600, 'server:2')]));
    const slow = shots.filter((s) => s.caption?.kind === 'incident');

    assert.equal(slow[0].focus, 'server:1');
    assert.deepEqual([slow[0].from, slow[0].to], [1, 4]);
    // The long incident: its start and end slowly, the middle at speed.
    const long = slow.filter((s) => s.caption.incident.id === 2);
    assert.equal(long.length, 2);
    assert.ok(long[0].to - long[0].from + 1 <= EDIT.incidentEdge);
    assert.ok(lengthOf(shots) < 60_000, 'a short film');
});

test('the position in a cut: title first, then frames in order', () => {
    const shots = autoEdit(data());

    assert.equal(positionAt(shots, 0).shot.type, 'title');
    const first = positionAt(shots, EDIT.titleMs + 1);
    assert.equal(first.shot.type, 'play');
    assert.ok(first.frame >= 0 && first.frame < 1);
    assert.equal(positionAt(shots, lengthOf(shots) + 999).shot.type, 'end');
});

test('the closing card sums up the period', () => {
    const s = summary(data([incident(1, 3600, 7200), incident(2, 20 * 3600, null)]));

    assert.equal(s.incidents, 2);
    assert.equal(s.longest, 4 * 3600, 'an open incident counts until the end of the period');
    assert.equal(s.uptime, 99.5);
});
