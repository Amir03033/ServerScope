// The opt-in 3D hero of a public status page (three.js, loaded on demand by
// status3d.js). A core in the page's overall state, the page's components
// on a ring around it and a beam to each: light flows along it while the
// component works, beads pile up at the component during an outage. Only
// the names and states the page lists below are used.

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { dispose, labelSprite, pointAt, themeColors } from '../nexus/kit3d.js';
import { STATE_TONE, beamFor, heroLayout } from './heroLayout.js';

export function createHero(container, data, { reducedMotion }) {
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    container.append(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 2, 0.1, 500);
    const controls = new OrbitControls(camera, renderer.domElement);
    // A page to read, not a game: no zoom or pan (scrolling stays scrolling).
    controls.enableZoom = false;
    controls.enablePan = false;
    controls.enableDamping = !reducedMotion;
    controls.minPolarAngle = Math.PI * 0.22;
    controls.maxPolarAngle = Math.PI * 0.45;
    controls.autoRotate = !reducedMotion;
    controls.autoRotateSpeed = 0.7;
    controls.addEventListener('start', () => { controls.autoRotate = false; });

    scene.add(new THREE.HemisphereLight(0xffffff, 0x404050, 1.5));
    const sun = new THREE.DirectionalLight(0xffffff, 1.2);
    sun.position.set(10, 20, 8);
    scene.add(sun);

    const world = new THREE.Group();
    scene.add(world);

    const timer = new THREE.Timer();
    let beads = [];
    let pulses = [];
    let core = null;
    let visible = true;
    let frame = null;

    const color = (hex) => new THREE.Color(hex);

    function build() {
        dispose(world);
        world.clear();
        beads = [];
        pulses = [];

        const colors = themeColors();
        const accent = data.accent || colors.accent;
        const tone = (state) => ({ ok: colors.ok, warn: colors.warn, bad: colors.bad, info: colors.accent })[STATE_TONE[state]] ?? colors.subtle;
        const overall = tone(data.overall);

        // The core: a glowing sphere in a slowly turning cage, in the page's overall state.
        core = new THREE.Group();
        const sphere = new THREE.Mesh(new THREE.SphereGeometry(1.1, 32, 16), new THREE.MeshStandardMaterial({ color: color(overall), emissive: color(overall), emissiveIntensity: 0.6, roughness: 0.3 }));
        const cage = new THREE.Mesh(new THREE.IcosahedronGeometry(1.9, 1), new THREE.MeshBasicMaterial({ color: color(accent), wireframe: true, transparent: true, opacity: 0.35 }));
        core.add(sphere, cage);
        core.position.y = 1.2;
        world.add(core);

        const floor = new THREE.Mesh(new THREE.RingGeometry(2.4, 2.48, 64), new THREE.MeshBasicMaterial({ color: color(accent), transparent: true, opacity: 0.35, side: THREE.DoubleSide }));
        floor.rotation.x = -Math.PI / 2;
        world.add(floor);

        const places = heroLayout(data.components.length);

        data.components.forEach((component, i) => {
            const p = places[i];
            const hex = tone(component.state);
            const beam = beamFor(component.state);
            const node = new THREE.Mesh(
                new THREE.BoxGeometry(p.size * 1.6, p.size * 2.2, p.size * 1.6),
                new THREE.MeshStandardMaterial({ color: color(hex), emissive: color(hex), emissiveIntensity: beam === 'none' ? 0.1 : 0.45, roughness: 0.4, transparent: true, opacity: beam === 'none' ? 0.55 : 1 }),
            );
            node.position.set(p.x, p.y + p.size * 1.1, p.z);
            world.add(node);

            // State in words on every label that is not "operational" (never colour alone).
            const text = component.state === 'operational' ? component.name : `${component.name} · ${data.states[component.state] ?? component.state}`;
            const label = labelSprite(text, { important: component.state !== 'operational', colors, scale: 0.0105 });
            label.position.set(p.x, p.y + p.size * 2.2 + 0.7, p.z);
            world.add(label);

            if (component.state === 'major_outage' || component.state === 'partial_outage') {
                const pulse = new THREE.Mesh(new THREE.RingGeometry(p.size * 1.3, p.size * 1.55, 40), new THREE.MeshBasicMaterial({ color: color(hex), transparent: true, side: THREE.DoubleSide, depthWrite: false }));
                pulse.rotation.x = -Math.PI / 2;
                pulse.position.set(p.x, p.y + 0.03, p.z);
                world.add(pulse);
                pulses.push({ mesh: pulse, offset: i * 0.17 });
            }

            // The beam from the core to the component, lifted in the middle.
            const start = new THREE.Vector3(0, 1.2, 0);
            const end = new THREE.Vector3(p.x, p.y + p.size * 1.1, p.z);
            const mid = start.clone().lerp(end, 0.5).add(new THREE.Vector3(0, 1.1, 0));
            const points = new THREE.QuadraticBezierCurve3(start, mid, end).getPoints(28);
            world.add(new THREE.Line(new THREE.BufferGeometry().setFromPoints(points), new THREE.LineBasicMaterial({ color: color(beam === 'jam' ? hex : accent), transparent: true, opacity: beam === 'none' ? 0.2 : 0.55 })));

            const count = beam === 'none' ? 0 : 3;

            for (let k = 0; k < count; k++) {
                const jam = beam === 'jam';
                const bead = new THREE.Mesh(new THREE.SphereGeometry(jam ? 0.13 : 0.1, 10, 6), new THREE.MeshBasicMaterial({ color: color(jam ? hex : accent), transparent: true, opacity: 0.95, depthWrite: false }));
                bead.add(new THREE.Mesh(new THREE.SphereGeometry(jam ? 0.3 : 0.24, 10, 6), new THREE.MeshBasicMaterial({ color: color(jam ? hex : accent), transparent: true, opacity: 0.2, blending: THREE.AdditiveBlending, depthWrite: false })));
                // A jam piles up just before the component; a flow spreads over the beam.
                const offset = jam ? 0.9 - k * 0.05 : k / count;
                bead.position.copy(pointAt(points, offset));
                world.add(bead);
                beads.push({ mesh: bead, points, offset, speed: jam ? 0 : beam === 'slow' ? 0.18 : 0.42 });
            }
        });

        const reach = Math.max(...places.map((p) => Math.hypot(p.x, p.z)), 4);
        camera.position.set(0, reach * 1.1 + 3, reach * 2.2 + 4);
        controls.target.set(0, 0.6, 0);
        controls.update();
    }

    function resize() {
        const w = container.clientWidth;
        const h = container.clientHeight;

        if (!w || !h) return;

        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
    }

    function tick(timestamp) {
        frame = null;

        if (!visible || document.hidden) return;

        timer.update(timestamp);
        const t = timer.getElapsed();

        if (!reducedMotion) {
            core.children[1].rotation.y = t * 0.25;
            core.children[1].rotation.x = t * 0.1;

            for (const bead of beads) {
                if (bead.speed > 0) bead.mesh.position.copy(pointAt(bead.points, (t * bead.speed + bead.offset) % 1));
            }

            for (const pulse of pulses) {
                const p = (t * 0.55 + pulse.offset) % 1;
                pulse.mesh.scale.setScalar(1 + p * 1.8);
                pulse.mesh.material.opacity = 0.8 * (1 - p);
            }
        }

        controls.update();
        renderer.render(scene, camera);
        schedule();
    }

    function schedule() {
        if (visible && !frame && !document.hidden) frame = requestAnimationFrame(tick);
    }

    build();
    resize();
    new ResizeObserver(resize).observe(container);
    // Only draw while the hero is on screen.
    new IntersectionObserver(([entry]) => { visible = entry.isIntersecting; schedule(); }).observe(container);
    document.addEventListener('visibilitychange', schedule);

    // Follow the theme switch, like the rest of the page.
    const rebuild = () => { build(); schedule(); };
    new MutationObserver(rebuild).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', rebuild);

    schedule();
}
