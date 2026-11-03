// The server skyline (three.js, loaded by skyline.js): a city at night.
// Towers rise with CPU load, their windows are the services, beacons on
// the roofs the websites. When something on a server fails its lights
// flicker; when the server stops reporting they go out and a red light
// blinks on the roof. The scene keeps its night palette in both themes
// (a picture of a city at night); labels say the state in words.

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { dispose, labelSprite } from '../nexus/kit3d.js';
import { CITY } from './model.js';

const NIGHT = {
    bg: '#0a0f1e', surface: '#111827', line: '#1f2a44', lineStrong: '#334155', text: '#e5e7eb', muted: '#94a3b8', subtle: '#64748b',
};
const WINDOW_COLOR = { lit: '#ffd58a', warn: '#ffb020', bad: '#ff4d4f', dark: null };
const BEACON_COLOR = { ok: '#3ee07a', warn: '#ffb020', bad: '#ff4d4f', off: '#475569' };

/** A facade: dark glass with a grid of windows in their colours (also used as glow map). */
function facade(tower) {
    const cell = 16;
    const canvas = document.createElement('canvas');
    canvas.width = CITY.columns * cell;
    canvas.height = tower.floors * cell;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#05070d';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    tower.windows.forEach((state, i) => {
        const col = i % CITY.columns;
        const floor = Math.floor(i / CITY.columns);
        const y = canvas.height - (floor + 1) * cell; // bottom up
        const color = WINDOW_COLOR[state];
        ctx.fillStyle = color ?? '#0c1222';
        ctx.globalAlpha = color ? 0.75 + ((i * 37) % 25) / 100 : 1;
        ctx.fillRect(col * cell + 4, y + 4, cell - 8, cell - 8);
    });

    ctx.globalAlpha = 1;
    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.magFilter = THREE.NearestFilter;

    return texture;
}

/** Window states as runs ("lit×12,bad×4"): the same pattern at any number of floors compares equal. */
function windowRuns(windows) {
    const runs = [];

    for (const state of windows) {
        const last = runs[runs.length - 1];
        last && last[0] === state ? last[1]++ : runs.push([state, 1]);
    }

    const total = windows.length || 1;

    return runs.map(([state, n]) => `${state}:${Math.round((n / total) * 20)}`).join(',');
}

function glowTexture() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 64;
    const ctx = canvas.getContext('2d');
    const g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.3, 'rgba(255,255,255,.4)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, 64, 64);

    return new THREE.CanvasTexture(canvas);
}

export function createSkyline(container, { reducedMotion, t, onSelect, record = false }) {
    // record: keep the drawn frame so a recorder can copy it (time-travel export).
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: record });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.domElement.classList.add('ss-globe-canvas');
    container.append(renderer.domElement);

    const scene = new THREE.Scene();
    scene.fog = new THREE.Fog(0x0a0f1e, 30, 90);
    const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 400);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enablePan = false;
    controls.enableDamping = !reducedMotion;
    controls.minDistance = 8;
    controls.maxDistance = 90;
    controls.maxPolarAngle = Math.PI * 0.47;
    controls.autoRotate = !reducedMotion;
    controls.autoRotateSpeed = 0.3;
    let touched = false;
    controls.addEventListener('start', () => { controls.autoRotate = false; touched = true; });

    scene.add(new THREE.HemisphereLight(0x8aa4ff, 0x05070d, 0.9));
    const moon = new THREE.DirectionalLight(0xc8d6ff, 0.8);
    moon.position.set(-20, 30, 10);
    scene.add(moon);

    const glow = glowTexture();
    const world = new THREE.Group();
    const towersGroup = new THREE.Group();
    scene.add(world, towersGroup);

    // Ground with glowing streets, stars and a moon (built once).
    const accent = new THREE.Color(getComputedStyle(document.documentElement).getPropertyValue('--ss-accent').trim() || '#4f7cff');
    world.add(new THREE.Mesh(new THREE.CircleGeometry(160, 64).rotateX(-Math.PI / 2), new THREE.MeshStandardMaterial({ color: 0x070a12, roughness: 0.6, metalness: 0.4 })));
    const stars = new Float32Array(900 * 3);
    for (let i = 0; i < 900; i++) {
        const v = new THREE.Vector3().randomDirection();
        v.y = Math.abs(v.y) * 0.9 + 0.08;
        v.normalize().multiplyScalar(140);
        stars.set([v.x, v.y, v.z], i * 3);
    }
    const starGeometry = new THREE.BufferGeometry();
    starGeometry.setAttribute('position', new THREE.BufferAttribute(stars, 3));
    world.add(new THREE.Points(starGeometry, new THREE.PointsMaterial({ color: 0xaab8e0, size: 0.5, transparent: true, opacity: 0.7, fog: false, depthWrite: false })));
    const moonGlow = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: 0xdfe8ff, transparent: true, fog: false, depthWrite: false }));
    moonGlow.scale.setScalar(14);
    moonGlow.position.set(-60, 55, -80);
    world.add(moonGlow);
    let streets = null;

    const timer = new THREE.Timer();
    let towers = new Map(); // key => { group, body, sides, beacons, label, data, current, target }
    let frame = null;
    let reach = 10;

    function buildStreets() {
        if (streets) { world.remove(streets); dispose(streets); }

        const size = reach * 2 + CITY.spacing * 2;
        streets = new THREE.GridHelper(size, Math.round(size / CITY.spacing) * 2, accent, accent);
        streets.position.y = 0.01;
        for (const m of [streets.material].flat()) { m.transparent = true; m.opacity = 0.16; m.depthWrite = false; }
        world.add(streets);
    }

    function towerMesh(data) {
        const group = new THREE.Group();
        group.position.set(data.x, 0, data.z);

        const map = facade(data);
        const lit = data.lights !== 'out';
        const side = new THREE.MeshStandardMaterial({ color: 0xffffff, map, emissive: 0xffffff, emissiveMap: map, emissiveIntensity: lit ? (data.lights === 'dim' ? 0.25 : 0.75) : 0, roughness: 0.4, metalness: 0.6 });
        const roof = new THREE.MeshStandardMaterial({ color: 0x0b1020, roughness: 0.8, metalness: 0.3 });
        const body = new THREE.Mesh(new THREE.BoxGeometry(2.4, 1, 2.4), [side, side, roof, roof, side, side]);
        body.position.y = 0.5;
        body.userData.key = data.key;
        group.add(body);

        // An outline in the accent (red when the server fails or is out).
        const edges = new THREE.LineSegments(new THREE.EdgesGeometry(new THREE.BoxGeometry(2.42, 1, 2.42)), new THREE.LineBasicMaterial({ color: data.lights === 'out' || data.lights === 'flicker' ? 0xff4d4f : accent, transparent: true, opacity: 0.35 }));
        edges.position.y = 0.5;
        group.add(edges);

        // A pool of light on the street.
        const pool = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: data.lights === 'out' ? 0xff4d4f : 0xffc56b, transparent: true, opacity: data.lights === 'out' ? 0.35 : 0.22, depthWrite: false }));
        pool.scale.set(6, 6, 1);
        pool.position.y = 0.05;
        group.add(pool);

        // Roof: website beacons on a small mast each; a red warning light when the server is out.
        const top = new THREE.Group();
        const beacons = [];
        const lights = data.lights === 'out' ? [{ state: 'bad', alarm: true }] : data.beacons;
        lights.forEach((b, i) => {
            const angle = (i / Math.max(1, lights.length)) * Math.PI * 2;
            const r = lights.length === 1 ? 0 : 0.7;
            const mast = new THREE.Mesh(new THREE.CylinderGeometry(0.03, 0.03, 0.8, 6), new THREE.MeshBasicMaterial({ color: 0x334155 }));
            mast.position.set(Math.cos(angle) * r, 0.4, Math.sin(angle) * r);
            const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: BEACON_COLOR[b.state], transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
            halo.scale.setScalar(b.alarm ? 1.6 : 0.8);
            halo.position.set(mast.position.x, 0.85, mast.position.z);
            top.add(mast, halo);
            beacons.push({ halo, state: b.state, offset: i * 0.37 });
        });
        group.add(top);

        const label = labelSprite(`${data.name} · ${t(`lights.${data.lights}`)}`, { important: true, colors: NIGHT, scale: 0.011 });
        group.add(label);

        return { group, body, edges, side, top, beacons, label, pool };
    }

    function place(tower, height) {
        tower.body.scale.y = height;
        tower.body.position.y = height / 2;
        tower.edges.scale.y = height;
        tower.edges.position.y = height / 2;
        tower.top.position.y = height;
        tower.label.position.y = height + 1.6;
    }

    function update(list) {
        const seen = new Set();

        for (const data of list) {
            seen.add(data.key);
            const old = towers.get(data.key);
            const current = old ? old.current : (reducedMotion ? data.height : 0.2);
            // What the tower looks like apart from its exact height (the facade is rebuilt
            // only when its floors change a lot, so a growing tower does not flicker).
            const look = JSON.stringify([data.name, data.lights, windowRuns(data.windows), Math.round(data.floors / 4), data.beacons, data.x, data.z]);

            // Only the height changed: let the tower grow towards it, nothing to rebuild.
            if (old && old.look === look) {
                old.target = data.height;
                old.data = data;
                continue;
            }

            if (old) {
                towersGroup.remove(old.group);
                dispose(old.group);
            }

            const tower = { ...towerMesh(data), data, current, target: data.height, look };
            place(tower, current);
            towersGroup.add(tower.group);
            towers.set(data.key, tower);
        }

        for (const [key, tower] of towers) {
            if (seen.has(key)) continue;
            towersGroup.remove(tower.group);
            dispose(tower.group);
            towers.delete(key);
        }

        const nextReach = Math.max(8, ...list.map((d) => Math.hypot(d.x, d.z) + 3));
        if (Math.abs(nextReach - reach) > 0.5 || !streets) {
            reach = nextReach;
            buildStreets();
            fit();
        }
    }

    function fit() {
        if (touched) return;

        const distance = Math.max(24, reach * 2.6) * Math.max(1, 1.2 / camera.aspect);
        camera.position.set(distance * 0.75, distance * 0.55, distance * 0.75);
        controls.target.set(0, 3.5, 0);
        controls.update();
    }

    // ---------- interaction ----------
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();
    let down = null;
    const canvas = renderer.domElement;
    canvas.addEventListener('pointerdown', (e) => { down = { x: e.clientX, y: e.clientY }; });
    canvas.addEventListener('pointerup', (e) => {
        if (!down || Math.hypot(e.clientX - down.x, e.clientY - down.y) > 5) return;

        down = null;
        const rect = canvas.getBoundingClientRect();
        pointer.set(((e.clientX - rect.left) / rect.width) * 2 - 1, -((e.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        const hit = raycaster.intersectObjects([...towers.values()].map((tw) => tw.body), false)[0];

        if (hit) onSelect?.(hit.object.userData.key);
    });

    // ---------- loop ----------
    function resize() {
        const w = container.clientWidth;
        const h = container.clientHeight;

        if (!w || !h) return;

        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        fit();
    }

    function tick(timestamp) {
        frame = null;

        if (document.hidden) return;

        timer.update(timestamp);
        const time = timer.getElapsed();
        const dt = Math.min(0.1, timer.getDelta());

        for (const tower of towers.values()) {
            // Grow (or shrink) towards the measured load.
            if (Math.abs(tower.current - tower.target) > 0.01) {
                tower.current += (tower.target - tower.current) * (reducedMotion ? 1 : Math.min(1, dt * 2.5));
                place(tower, tower.current);
            }

            if (reducedMotion) continue;

            if (tower.data.lights === 'flicker') {
                tower.side.emissiveIntensity = Math.random() < 0.18 ? 0.08 : 0.55 + Math.random() * 0.4;
            }

            for (const b of tower.beacons) {
                const speed = b.state === 'bad' ? 3 : 0.8;
                b.halo.material.opacity = b.state === 'off' ? 0.35 : 0.45 + 0.55 * (0.5 + 0.5 * Math.sin((time + b.offset) * Math.PI * 2 * speed));
            }
        }

        controls.update();
        renderer.render(scene, camera);
        schedule();
    }

    // Paused scenes (for example a hidden one on the wall screen) draw nothing.
    let running = true;

    function schedule() {
        if (running && !frame && !document.hidden) frame = requestAnimationFrame(tick);
    }

    new ResizeObserver(resize).observe(container);
    document.addEventListener('visibilitychange', schedule);
    resize();
    schedule();

    return {
        update,
        canvas: renderer.domElement,
        pause() { running = false; },
        /** Back to the whole city, slowly turning. */
        overview() {
            touched = false;
            controls.autoRotate = !reducedMotion;
            fit();
        },
        resume() { running = true; resize(); schedule(); },
        /** Turn the view towards a tower. */
        focus(key) {
            const tower = towers.get(key);

            if (!tower) return;

            controls.autoRotate = false;
            touched = true;
            const target = new THREE.Vector3(tower.data.x, tower.current / 2, tower.data.z);
            const offset = camera.position.clone().sub(controls.target).setLength(Math.max(22, tower.current * 2.6));
            controls.target.copy(target);
            camera.position.copy(target.clone().add(offset));
            controls.update();
        },
    };
}
