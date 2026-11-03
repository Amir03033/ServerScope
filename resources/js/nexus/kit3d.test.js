// Run with: npm run test:js (Node's built-in test runner, no dependencies).
import assert from 'node:assert/strict';
import { test } from 'node:test';
import * as THREE from 'three';
import { pointAt } from './kit3d.js';

const line = [new THREE.Vector3(0, 0, 0), new THREE.Vector3(1, 0, 0), new THREE.Vector3(2, 0, 0)];

test('pointAt walks along the polyline', () => {
    assert.equal(pointAt(line, 0).x, 0);
    assert.equal(pointAt(line, 0.5).x, 1);
    assert.equal(pointAt(line, 0.75).x, 1.5);
    assert.equal(pointAt(line, 1).x, 2);
});

test('pointAt never reads outside the polyline', () => {
    // A tiny negative elapsed time on the first frame gives (-0.001 % 1) < 0.
    assert.equal(pointAt(line, -0.001 % 1).x, 0);
    assert.equal(pointAt(line, 1.4).x, 2);
    assert.equal(pointAt(line, Number.NaN).x, 0);
    assert.equal(pointAt([new THREE.Vector3(3, 0, 0)], 0.5).x, 3);
});
