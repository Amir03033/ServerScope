// The NEXUS globe (three.js, loaded by globe.js). A dark sphere with the
// continents as dots, a soft atmosphere, the measuring locations as pins
// with labels, and arcs from the main location to every probe with light
// travelling along them. A location turns red and pulses when a website is
// not reachable from it (or, with a website selected, when that website
// is not); not measured stays grey. Never colour alone: labels say it.

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import land from 'world-atlas/land-110m.json';
import { dispose, labelSprite, pointAt, themeColors } from '../nexus/kit3d.js';
import { landDots, rings, toVector } from './geo.js';

const R = 5;

let dotsCache = null;

export function createGlobe(container, { reducedMotion, t, onSelectLocation }) {
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.domElement.classList.add('ss-globe-canvas');
    container.append(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(40, 1, 0.1, 200);
    camera.position.set(0, 3, 16);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enablePan = false;
    controls.enableDamping = !reducedMotion;
    controls.minDistance = 8.5;
    controls.maxDistance = 26;
    controls.autoRotate = !reducedMotion;
    controls.autoRotateSpeed = 0.45;
    let touched = false;
    controls.addEventListener('start', () => { controls.autoRotate = false; touched = true; });

    scene.add(new THREE.AmbientLight(0xffffff, 1.4));
    const key = new THREE.DirectionalLight(0xffffff, 1.2);
    key.position.set(-10, 8, 12);
    scene.add(key);

    const earth = new THREE.Group();
    const markers = new THREE.Group();
    scene.add(earth, markers);

    const timer = new THREE.Timer();
    let colors = themeColors();
    let pins = new Map(); // key => { group, head, pulse, label, location }
    let travellers = [];
    let frame = null;
    let data = null;
    let focus = null;

    const color = (hex) => new THREE.Color(hex);
    const tone = (state) => ({ ok: colors.ok, down: colors.bad, unknown: colors.subtle, paused: colors.subtle, maintenance: colors.accent })[state] ?? colors.subtle;
    const vec = (lat, lon, r = R) => { const p = toVector(lat, lon, r); return new THREE.Vector3(p.x, p.y, p.z); };

    function buildEarth() {
        dispose(earth);
        earth.clear();

        const sphere = new THREE.Mesh(new THREE.SphereGeometry(R, 64, 48), new THREE.MeshStandardMaterial({ color: color(colors.surface), roughness: 0.9, metalness: 0.05, transparent: true, opacity: 0.96 }));
        earth.add(sphere);

        // Atmosphere: a slightly larger back-facing sphere in the accent.
        const glow = new THREE.Mesh(new THREE.SphereGeometry(R * 1.05, 48, 32), new THREE.MeshBasicMaterial({ color: color(colors.accent), transparent: true, opacity: 0.06, side: THREE.BackSide, blending: THREE.AdditiveBlending, depthWrite: false }));
        earth.add(glow);

        // Latitude and longitude lines.
        const grid = new THREE.LineBasicMaterial({ color: color(colors.line), transparent: true, opacity: 0.6 });

        for (let lat = -60; lat <= 60; lat += 30) {
            const points = [];
            for (let lon = -180; lon <= 180; lon += 6) points.push(vec(lat, lon, R * 1.001));
            earth.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(points), grid));
        }

        for (let lon = -180; lon < 180; lon += 30) {
            const points = [];
            for (let lat = -84; lat <= 84; lat += 6) points.push(vec(lat, lon, R * 1.001));
            earth.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(points), grid));
        }

        // Continents as dots (computed once per page).
        dotsCache ??= landDots(rings(land, 'land'), 2.2);
        const positions = new Float32Array(dotsCache.length * 3);
        dotsCache.forEach(([lat, lon], i) => {
            const p = toVector(lat, lon, R * 1.004);
            positions.set([p.x, p.y, p.z], i * 3);
        });
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        earth.add(new THREE.Points(geometry, new THREE.PointsMaterial({ color: color(colors.muted), size: 0.075, sizeAttenuation: true, transparent: true, opacity: 0.85 })));
    }

    function buildMarkers() {
        dispose(markers);
        markers.clear();
        pins = new Map();
        travellers = [];

        if (!data) return;

        for (const location of data.locations) {
            const group = new THREE.Group();
            const base = vec(location.lat, location.lon);
            const normal = base.clone().normalize();
            group.position.copy(base);
            group.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), normal);

            const height = location.kind === 'main' ? 0.9 : 0.65;
            const stem = new THREE.Mesh(new THREE.CylinderGeometry(0.025, 0.025, height, 8), new THREE.MeshBasicMaterial({ color: color(colors.muted) }));
            stem.position.y = height / 2;
            const head = new THREE.Mesh(new THREE.SphereGeometry(location.kind === 'main' ? 0.2 : 0.15, 20, 12), new THREE.MeshBasicMaterial({ color: color(colors.subtle) }));
            head.position.y = height;
            head.userData.key = location.key;
            const pulse = new THREE.Mesh(new THREE.RingGeometry(0.22, 0.3, 40), new THREE.MeshBasicMaterial({ color: color(colors.bad), transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false }));
            pulse.rotation.x = -Math.PI / 2;
            pulse.position.y = 0.02;
            group.add(stem, head, pulse);
            markers.add(group);

            const label = labelSprite(location.name ?? location.location ?? '', { important: true, colors, scale: 0.0075 });
            label.position.copy(base.clone().add(normal.clone().multiplyScalar(height + 0.55)));
            markers.add(label);

            pins.set(location.key, { group, head, pulse, label, location, top: base.clone().add(normal.clone().multiplyScalar(height)) });
        }

        // Arcs from the main location to every probe, with light travelling along.
        const main = pins.get('main');

        for (const [keyName, pin] of pins) {
            if (!main || keyName === 'main') continue;

            const a = main.top;
            const b = pin.top;
            const mid = a.clone().add(b).multiplyScalar(0.5);
            const lift = R + 0.6 + a.distanceTo(b) * 0.35;
            const control = mid.clone().normalize().multiplyScalar(lift);
            const points = new THREE.QuadraticBezierCurve3(a, control, b).getPoints(64);
            markers.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(points), new THREE.LineBasicMaterial({ color: color(colors.accent), transparent: true, opacity: 0.55 })));

            for (let k = 0; k < 3; k++) {
                const bead = new THREE.Mesh(new THREE.SphereGeometry(0.06, 10, 6), new THREE.MeshBasicMaterial({ color: color(colors.accent), transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
                bead.position.copy(pointAt(points, k / 3));
                markers.add(bead);
                travellers.push({ mesh: bead, points, offset: k / 3 });
            }
        }

        applyStates();
    }

    function applyStates() {
        if (!data) return;

        for (const [key, pin] of pins) {
            const s = data.states.get(key);
            const state = s?.state ?? 'unknown';
            const hex = tone(state);
            pin.head.material.color.set(hex);
            pin.state = state;
            pin.pulse.visible = state === 'down';

            // The label names the location and its state in words.
            const name = pin.location.name ?? pin.location.location ?? '';
            const text = focus === null && state !== 'unknown'
                ? `${name} · ${t('states.' + state)}${s && s.down ? ` (${s.down})` : ''}`
                : `${name} · ${t('states.' + state)}`;
            const fresh = labelSprite(text, { important: true, colors, scale: 0.0075 });
            fresh.position.copy(pin.label.position);
            markers.remove(pin.label);
            dispose(pin.label);
            markers.add(fresh);
            pin.label = fresh;
        }
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
        const hit = raycaster.intersectObjects([...pins.values()].map((p) => p.head), false)[0];

        if (hit) onSelectLocation?.(hit.object.userData.key);
    });

    // ---------- loop ----------
    function resize() {
        const w = container.clientWidth;
        const h = container.clientHeight;

        if (!w || !h) return;

        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();

        // Keep the whole globe in view on narrow (portrait) screens until someone zooms.
        if (!touched) camera.position.setLength(16 * Math.max(1, 1.1 / camera.aspect));
    }

    function tick(timestamp) {
        frame = null;

        if (document.hidden) return;

        timer.update(timestamp);
        const time = timer.getElapsed();

        if (!reducedMotion) {
            for (const tr of travellers) tr.mesh.position.copy(pointAt(tr.points, (time * 0.22 + tr.offset) % 1));

            for (const pin of pins.values()) {
                if (pin.state !== 'down') continue;

                const p = (time * 0.7) % 1;
                pin.pulse.scale.setScalar(1 + p * 3);
                pin.pulse.material.opacity = 0.9 * (1 - p);
            }
        } else {
            for (const pin of pins.values()) pin.pulse.material.opacity = pin.state === 'down' ? 0.9 : 0;
        }

        controls.update();
        renderer.render(scene, camera);
        schedule();
    }

    function schedule() {
        if (!frame && !document.hidden) frame = requestAnimationFrame(tick);
    }

    new ResizeObserver(resize).observe(container);
    document.addEventListener('visibilitychange', schedule);

    const onTheme = () => { colors = themeColors(); buildEarth(); buildMarkers(); };
    new MutationObserver(onTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onTheme);

    buildEarth();
    resize();
    schedule();

    return {
        /** New data (locations, sites, per-location states). */
        update(next, states, selected) {
            const structure = next.locations.map((l) => `${l.key}@${l.lat},${l.lon}`).join('|');
            const changed = !data || data.structure !== structure;
            data = { ...next, states, structure };
            focus = selected;
            changed ? buildMarkers() : applyStates();
        },
        /** Turn the globe so a location faces the camera. */
        face(keyName) {
            const pin = pins.get(keyName);

            if (!pin) return;

            controls.autoRotate = false;
            const target = pin.top.clone().normalize().multiplyScalar(camera.position.length());
            reducedMotion ? camera.position.copy(target) : (() => {
                const from = camera.position.clone();
                const start = performance.now();
                const step = () => {
                    const p = Math.min(1, (performance.now() - start) / 800);
                    camera.position.copy(from.clone().lerp(target, p).normalize().multiplyScalar(from.length()));
                    if (p < 1) requestAnimationFrame(step);
                };
                step();
            })();
        },
    };
}
