// The NEXUS globe (three.js, loaded by globe.js). A dark ocean with a
// glowing rim, the continents as fine round dots that fade towards the
// edge, a soft atmosphere and a faint star field. Measuring locations are
// glowing pins with a beam and a quiet pulse; locations on (almost) the
// same spot share one pin. Arcs run from the main location to every probe
// with light travelling along. A location turns red and pulses hard when a
// website is not reachable from it; not measured stays grey. Never colour
// alone: every label says the state in words.

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import land from 'world-atlas/land-110m.json';
import { dispose, pointAt, themeColors } from '../nexus/kit3d.js';
import { clusters, landDots, rings, toVector } from './geo.js';

const R = 5;
const DISTANCE = 21;
const RANK = { down: 3, ok: 2, maintenance: 1, standby: 1, unknown: 0, paused: 0 };

let dotsCache = null;

/** A soft round glow (white; tinted by the material colour). */
function glowTexture() {
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = 64;
    const ctx = canvas.getContext('2d');
    const g = ctx.createRadialGradient(32, 32, 0, 32, 32, 32);
    g.addColorStop(0, 'rgba(255,255,255,1)');
    g.addColorStop(0.25, 'rgba(255,255,255,.55)');
    g.addColorStop(1, 'rgba(255,255,255,0)');
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, 64, 64);

    return new THREE.CanvasTexture(canvas);
}

/** A label card: a title and one row per location with a state dot. */
function cardSprite(title, rows, colors) {
    const scale = 2;
    const pad = 14 * scale;
    const titleFont = `600 ${17 * scale}px Inter, ui-sans-serif, system-ui, sans-serif`;
    const rowFont = `500 ${14 * scale}px Inter, ui-sans-serif, system-ui, sans-serif`;
    const lineH = 22 * scale;
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    ctx.font = titleFont;
    let width = ctx.measureText(title).width;
    ctx.font = rowFont;
    for (const row of rows) width = Math.max(width, ctx.measureText(row.text).width + 16 * scale);

    canvas.width = Math.ceil(width + pad * 2);
    canvas.height = Math.ceil(pad * 2 + 20 * scale + rows.length * lineH);

    ctx.fillStyle = colors.bg;
    ctx.globalAlpha = 0.78;
    ctx.beginPath();
    ctx.roundRect(1, 1, canvas.width - 2, canvas.height - 2, 12 * scale);
    ctx.fill();
    ctx.globalAlpha = 1;
    ctx.strokeStyle = colors.lineStrong;
    ctx.lineWidth = scale;
    ctx.stroke();

    ctx.textBaseline = 'middle';
    ctx.font = titleFont;
    ctx.fillStyle = colors.text;
    ctx.fillText(title, pad, pad + 10 * scale);

    ctx.font = rowFont;
    rows.forEach((row, i) => {
        const y = pad + 20 * scale + lineH * (i + 0.5) + 4 * scale;
        ctx.fillStyle = row.color;
        ctx.beginPath();
        ctx.arc(pad + 4 * scale, y, 4 * scale, 0, Math.PI * 2);
        ctx.fill();
        ctx.fillStyle = colors.muted;
        ctx.fillText(row.text, pad + 16 * scale, y + scale);
    });

    const texture = new THREE.CanvasTexture(canvas);
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = 4;
    const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, transparent: true, depthWrite: false, depthTest: false }));
    const size = 0.0058;
    sprite.scale.set(canvas.width * size, canvas.height * size, 1);
    sprite.center.set(0, 0); // anchored at its lower-left corner, beside the pin
    sprite.renderOrder = 20;

    return sprite;
}

export function createGlobe(container, { reducedMotion, t, onSelectLocation }) {
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.domElement.classList.add('ss-globe-canvas');
    container.append(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(36, 1, 0.1, 400);
    camera.position.set(0, 4, DISTANCE);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enablePan = false;
    controls.enableDamping = !reducedMotion;
    controls.dampingFactor = 0.06;
    controls.rotateSpeed = 0.5;
    controls.minDistance = 9;
    controls.maxDistance = 36;
    controls.autoRotate = !reducedMotion;
    controls.autoRotateSpeed = 0.35;
    let touched = false;
    controls.addEventListener('start', () => { controls.autoRotate = false; touched = true; });

    const earth = new THREE.Group();
    const markers = new THREE.Group();
    const sky = new THREE.Group();
    scene.add(sky, earth, markers);

    const glow = glowTexture();
    const timer = new THREE.Timer();
    let colors = themeColors();
    let dark = false;
    let pins = new Map(); // cluster key => pin
    let memberOf = new Map(); // location key => cluster key
    let travellers = [];
    let frame = null;
    let data = null;
    let focus = null;
    let dotMaterial = null;

    const color = (hex) => new THREE.Color(hex);
    const tone = (state) => ({ ok: colors.ok, down: colors.bad, maintenance: colors.accent, standby: colors.accent })[state] ?? colors.subtle;
    const vec = (lat, lon, r = R) => { const p = toVector(lat, lon, r); return new THREE.Vector3(p.x, p.y, p.z); };
    const isDark = () => color(colors.bg).getHSL({ h: 0, s: 0, l: 0 }).l < 0.4;

    function buildEarth() {
        dispose(earth);
        earth.clear();
        dispose(sky);
        sky.clear();
        dark = isDark();

        const accent = color(colors.accent);
        const ocean = color(colors.bg).lerp(accent, dark ? 0.1 : 0.06);

        // Ocean: lit from the upper left, with a rim that picks up the accent.
        earth.add(new THREE.Mesh(new THREE.SphereGeometry(R, 96, 64), new THREE.ShaderMaterial({
            uniforms: { ocean: { value: ocean }, rim: { value: accent }, light: { value: new THREE.Vector3(-0.6, 0.5, 0.65).normalize() }, rimStrength: { value: dark ? 0.75 : 0.35 } },
            vertexShader: `
                varying vec3 vNormal;
                varying vec3 vView;
                void main() {
                    vec4 mv = modelViewMatrix * vec4(position, 1.0);
                    vNormal = normalize(normalMatrix * normal);
                    vView = normalize(-mv.xyz);
                    gl_Position = projectionMatrix * mv;
                }`,
            fragmentShader: `
                uniform vec3 ocean;
                uniform vec3 rim;
                uniform vec3 light;
                uniform float rimStrength;
                varying vec3 vNormal;
                varying vec3 vView;
                void main() {
                    float diffuse = 0.75 + 0.35 * max(dot(vNormal, light), 0.0);
                    float fresnel = pow(1.0 - max(dot(vNormal, vView), 0.0), 3.0);
                    gl_FragColor = vec4(ocean * diffuse + rim * fresnel * rimStrength, 1.0);
                }`,
        })));

        // Atmosphere: a halo just outside the sphere.
        earth.add(new THREE.Mesh(new THREE.SphereGeometry(R * 1.1, 64, 48), new THREE.ShaderMaterial({
            uniforms: { tint: { value: accent }, strength: { value: dark ? 0.9 : 0.5 } },
            vertexShader: `
                varying vec3 vNormal;
                void main() {
                    vNormal = normalize(normalMatrix * normal);
                    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
                }`,
            fragmentShader: `
                uniform vec3 tint;
                uniform float strength;
                varying vec3 vNormal;
                void main() {
                    float i = pow(max(0.6 - dot(vNormal, vec3(0.0, 0.0, 1.0)), 0.0), 4.0) * strength;
                    gl_FragColor = vec4(tint, i);
                }`,
            side: THREE.BackSide, transparent: true, blending: THREE.AdditiveBlending, depthWrite: false,
        })));

        // Continents as round dots: brighter facing the viewer, fading at the edge, a slow shimmer.
        dotsCache ??= landDots(rings(land, 'land'), 1.6);
        const positions = new Float32Array(dotsCache.length * 3);
        const seeds = new Float32Array(dotsCache.length);
        dotsCache.forEach(([lat, lon], i) => {
            const p = toVector(lat, lon, R * 1.003);
            positions.set([p.x, p.y, p.z], i * 3);
            seeds[i] = Math.random();
        });
        const geometry = new THREE.BufferGeometry();
        geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
        geometry.setAttribute('seed', new THREE.BufferAttribute(seeds, 1));
        dotMaterial = new THREE.ShaderMaterial({
            uniforms: {
                tint: { value: color(dark ? colors.text : colors.subtle).lerp(accent, dark ? 0.35 : 0.25) },
                time: { value: 0 },
                size: { value: 1.8 * renderer.getPixelRatio() },
                shimmer: { value: reducedMotion ? 0 : 1 },
            },
            vertexShader: `
                attribute float seed;
                uniform float time;
                uniform float size;
                uniform float shimmer;
                varying float vAlpha;
                void main() {
                    vec4 mv = modelViewMatrix * vec4(position, 1.0);
                    vec3 n = normalize(normalMatrix * normalize(position));
                    float facing = max(dot(n, normalize(-mv.xyz)), 0.0);
                    vAlpha = smoothstep(0.0, 0.55, facing) * (0.7 + 0.3 * sin(time * 1.3 + seed * 6.283) * shimmer);
                    gl_PointSize = size * (21.0 / -mv.z);
                    gl_Position = projectionMatrix * mv;
                }`,
            fragmentShader: `
                uniform vec3 tint;
                varying float vAlpha;
                void main() {
                    float d = length(gl_PointCoord - 0.5);
                    if (d > 0.5) discard;
                    gl_FragColor = vec4(tint, vAlpha * smoothstep(0.5, 0.3, d));
                }`,
            transparent: true, depthWrite: false,
        });
        earth.add(new THREE.Points(geometry, dotMaterial));

        // A faint star field, only on dark themes.
        if (dark) {
            const count = 700;
            const stars = new Float32Array(count * 3);
            for (let i = 0; i < count; i++) {
                const v = new THREE.Vector3().randomDirection().multiplyScalar(90 + Math.random() * 60);
                stars.set([v.x, v.y, v.z], i * 3);
            }
            const sg = new THREE.BufferGeometry();
            sg.setAttribute('position', new THREE.BufferAttribute(stars, 3));
            sky.add(new THREE.Points(sg, new THREE.PointsMaterial({ color: color(colors.muted), size: 0.35, sizeAttenuation: true, transparent: true, opacity: 0.55, depthWrite: false })));
        }
    }

    function buildMarkers() {
        dispose(markers);
        markers.clear();
        pins = new Map();
        memberOf = new Map();
        travellers = [];

        if (!data) return;

        for (const group of clusters(data.locations)) {
            const holder = new THREE.Group();
            const base = vec(group.lat, group.lon);
            const normal = base.clone().normalize();
            holder.position.copy(base);
            holder.quaternion.setFromUnitVectors(new THREE.Vector3(0, 1, 0), normal);
            const main = group.members.some((m) => m.kind === 'main');
            const height = main ? 0.7 : 0.5;

            // A thin beam of light standing on the spot, a bright head and a soft glow.
            const beam = new THREE.Mesh(new THREE.CylinderGeometry(0.012, 0.04, height, 10, 1, true), new THREE.MeshBasicMaterial({ color: color(colors.subtle), transparent: true, opacity: 0.75, blending: THREE.AdditiveBlending, depthWrite: false }));
            beam.position.y = height / 2;
            const head = new THREE.Mesh(new THREE.SphereGeometry(main ? 0.09 : 0.075, 20, 12), new THREE.MeshBasicMaterial({ color: color(colors.subtle) }));
            head.position.y = height;
            head.userData.key = group.key;
            const halo = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: color(colors.subtle), transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
            halo.scale.setScalar(main ? 0.75 : 0.6);
            halo.position.y = height;
            const spot = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: color(colors.subtle), transparent: true, opacity: 0.6, blending: THREE.AdditiveBlending, depthWrite: false }));
            spot.scale.setScalar(0.45);
            spot.position.y = 0.02;
            const pulse = new THREE.Mesh(new THREE.RingGeometry(0.16, 0.2, 48), new THREE.MeshBasicMaterial({ color: color(colors.subtle), transparent: true, opacity: 0, side: THREE.DoubleSide, depthWrite: false, blending: THREE.AdditiveBlending }));
            pulse.rotation.x = -Math.PI / 2;
            pulse.position.y = 0.015;
            holder.add(beam, head, halo, spot, pulse);
            markers.add(holder);

            const top = base.clone().add(normal.clone().multiplyScalar(height));
            pins.set(group.key, { group, holder, beam, head, halo, spot, pulse, label: null, normal, top, state: 'unknown' });
            for (const m of group.members) memberOf.set(m.key, group.key);
        }

        // Arcs from the main location to every probe elsewhere, with light travelling along.
        const mainPin = pins.get(memberOf.get('main'));

        for (const pin of pins.values()) {
            if (!mainPin || pin === mainPin) continue;

            const a = mainPin.top;
            const b = pin.top;
            const lift = R + 0.5 + a.distanceTo(b) * 0.32;
            const control = a.clone().add(b).multiplyScalar(0.5).normalize().multiplyScalar(lift);
            const points = new THREE.QuadraticBezierCurve3(a, control, b).getPoints(96);
            markers.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(points), new THREE.LineBasicMaterial({ color: color(colors.accent), transparent: true, opacity: 0.35, blending: THREE.AdditiveBlending, depthWrite: false })));

            for (let k = 0; k < 4; k++) {
                const bead = new THREE.Sprite(new THREE.SpriteMaterial({ map: glow, color: color(colors.accent), transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
                bead.scale.setScalar(0.32);
                bead.position.copy(pointAt(points, k / 4));
                markers.add(bead);
                travellers.push({ mesh: bead, points, offset: k / 4 });
            }
        }

        applyStates();
    }

    const place = (l) => (l.kind === 'main' ? (l.name ?? t('main')) : (l.location ?? l.name ?? ''));
    const who = (l) => (l.kind === 'main' ? t('short_main') : l.name);

    function applyStates() {
        if (!data) return;

        for (const pin of pins.values()) {
            const rows = pin.group.members.map((m) => {
                const s = data.states.get(m.key);
                const state = s?.state ?? 'unknown';
                const extra = focus === null && s?.down ? ` (${s.down})` : '';

                return { state, color: tone(state), text: `${who(m)} · ${t('states.' + state)}${extra}` };
            });
            const state = rows.reduce((w, r) => ((RANK[r.state] ?? 0) > (RANK[w] ?? 0) ? r.state : w), 'unknown');
            const hex = tone(state);
            pin.state = state;

            for (const part of [pin.beam, pin.head, pin.halo, pin.spot, pin.pulse]) part.material.color.set(hex);

            if (pin.label) {
                markers.remove(pin.label);
                dispose(pin.label);
            }

            pin.label = cardSprite(place(pin.group.members[0]), rows, colors);
            markers.add(pin.label);
        }

        placeLabels();
    }

    // Labels sit beside their pin, facing the viewer; behind the globe they fade out.
    const right = new THREE.Vector3();
    const toCamera = new THREE.Vector3();

    function placeLabels() {
        camera.updateMatrixWorld();
        right.setFromMatrixColumn(camera.matrixWorld, 0);

        for (const pin of pins.values()) {
            if (!pin.label) continue;

            pin.label.position.copy(pin.top).addScaledVector(right, 0.22);
            toCamera.copy(camera.position).sub(pin.top).normalize();
            const facing = pin.normal.dot(toCamera);
            pin.label.material.opacity = THREE.MathUtils.smoothstep(facing, 0.05, 0.35);
            pin.label.visible = pin.label.material.opacity > 0.01;
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
        if (!touched) camera.position.setLength(DISTANCE * Math.max(1, 1.05 / camera.aspect));
    }

    function tick(timestamp) {
        frame = null;

        if (document.hidden) return;

        timer.update(timestamp);
        const time = timer.getElapsed();

        if (dotMaterial) dotMaterial.uniforms.time.value = time;

        if (!reducedMotion) {
            for (const tr of travellers) tr.mesh.position.copy(pointAt(tr.points, (time * 0.18 + tr.offset) % 1));

            for (const pin of pins.values()) {
                // A quiet pulse everywhere; a hard, fast one where a website is not reachable.
                const bad = pin.state === 'down';
                const p = (time * (bad ? 0.8 : 0.35) + pin.normal.x) % 1;
                pin.pulse.scale.setScalar(1 + p * (bad ? 3 : 2));
                pin.pulse.material.opacity = (bad ? 0.95 : 0.4) * (1 - p);
                pin.halo.material.opacity = bad ? 0.7 + 0.3 * Math.sin(time * 6) : 0.85;
            }
        } else {
            for (const pin of pins.values()) pin.pulse.material.opacity = pin.state === 'down' ? 0.9 : 0;
        }

        controls.update();
        placeLabels();
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

    const onTheme = () => { colors = themeColors(); buildEarth(); buildMarkers(); };
    new MutationObserver(onTheme).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onTheme);

    buildEarth();
    resize();
    schedule();

    return {
        pause() { running = false; },
        resume() { running = true; resize(); schedule(); },
        /** New data (locations, sites, per-location states). */
        update(next, states, selected) {
            const structure = next.locations.map((l) => `${l.key}@${l.lat},${l.lon}`).join('|');
            const changed = !data || data.structure !== structure;
            data = { ...next, states, structure };
            focus = selected;
            changed ? buildMarkers() : applyStates();
        },
        /** Turn the globe so a location faces the camera (a little from above). */
        face(keyName) {
            const pin = pins.get(memberOf.get(keyName) ?? keyName);

            if (!pin) return;

            controls.autoRotate = false;
            const length = camera.position.length();
            const target = pin.normal.clone().add(new THREE.Vector3(0, 0.35, 0)).normalize().multiplyScalar(length);

            if (reducedMotion) {
                camera.position.copy(target);

                return;
            }

            const from = camera.position.clone();
            const start = performance.now();
            const step = () => {
                const p = Math.min(1, (performance.now() - start) / 1100);
                const eased = 1 - (1 - p) ** 3;
                camera.position.copy(from.clone().lerp(target, eased).normalize().multiplyScalar(length));
                if (p < 1) requestAnimationFrame(step);
                else if (!touched) controls.autoRotate = true;
            };
            step();
        },
    };
}
