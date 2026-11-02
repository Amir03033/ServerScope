// The 3D NEXUS view (three.js). Loaded on demand by nexus.js the first time
// someone opens the 3D view, so the map page itself stays as light as before.
//
// It draws the same topology as the 2D map with the same filters, selection
// and impact rules; it only adds depth: servers as towers, services as rings
// around them, websites orbiting their server, pages as satellites, and
// active incidents as waves that run along the links to everything that
// depends on the failing resource. Health is never shown by colour alone:
// shapes, halos and labels ("· Critical") carry it too, and the side panel
// and list view stay available.

import * as THREE from 'three';
import { OrbitControls } from 'three/addons/controls/OrbitControls.js';
import { arc, bounds, layout3d } from './layout3d.js';
import { HEALTH_TONE, SEVERITY } from './model.js';

const MAX_LABELS = 250;
const WAVE_SPEED = 0.32; // links per second
const DIM = 0.12;

export function createScene3d(container, { onSelect, onHover, reducedMotion, t }) {
    const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true, powerPreference: 'high-performance' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 2));
    renderer.domElement.classList.add('ss-nexus-3d-canvas');
    container.append(renderer.domElement);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 2000);
    const controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = !reducedMotion;
    controls.dampingFactor = 0.08;
    controls.maxPolarAngle = Math.PI * 0.49;
    controls.minDistance = 4;
    controls.maxDistance = 600;
    controls.autoRotate = !reducedMotion;
    controls.autoRotateSpeed = 0.55;
    // Any interaction stops the slow tour for good.
    controls.addEventListener('start', () => { controls.autoRotate = false; });

    scene.add(new THREE.HemisphereLight(0xffffff, 0x404050, 1.6));
    const sun = new THREE.DirectionalLight(0xffffff, 1.4);
    sun.position.set(30, 60, 20);
    scene.add(sun);

    const world = new THREE.Group();
    scene.add(world);

    const clock = new THREE.Clock();
    const raycaster = new THREE.Raycaster();
    const pointer = new THREE.Vector2();

    let colors = {};
    let objects = new Map(); // key => { group, pick: Mesh[], materials: Material[], label?, node, position }
    let links = []; // { edge, line, material, points: Vector3[], baseColor }
    let waves = []; // { mesh, points, offset }
    let pulses = []; // { mesh, offset }
    let structure = null;
    let active = false;
    let frame = null;
    let hovered = null;
    let tween = null;

    const color = (value) => new THREE.Color(value);
    const tone = (name) => ({ ok: colors.ok, warn: colors.warn, bad: colors.bad, info: colors.accent })[name] ?? colors.subtle;
    const healthColor = (node) => tone(HEALTH_TONE[node.health]);
    const severityColor = (node) => tone((SEVERITY[node.incidents.max_severity] ?? SEVERITY.info).tone);

    // ---------- building ----------
    function material(hex, { emissive = 0.25, opacity = 1 } = {}) {
        return new THREE.MeshStandardMaterial({
            color: color(hex), emissive: color(hex), emissiveIntensity: emissive, roughness: 0.45, metalness: 0.15,
            transparent: true, opacity,
        });
    }

    function labelSprite(text, important) {
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const size = 44;
        ctx.font = `${important ? 600 : 500} ${size}px Inter, ui-sans-serif, system-ui, sans-serif`;
        const width = Math.ceil(ctx.measureText(text).width) + 36;
        canvas.width = width;
        canvas.height = size + 28;
        ctx.font = `${important ? 600 : 500} ${size}px Inter, ui-sans-serif, system-ui, sans-serif`;
        ctx.fillStyle = colors.bg;
        ctx.globalAlpha = 0.82;
        ctx.beginPath();
        ctx.roundRect(0, 0, canvas.width, canvas.height, 14);
        ctx.fill();
        ctx.globalAlpha = 1;
        ctx.fillStyle = important ? colors.text : colors.muted;
        ctx.textBaseline = 'middle';
        ctx.fillText(text, 18, canvas.height / 2 + 2);

        const texture = new THREE.CanvasTexture(canvas);
        texture.colorSpace = THREE.SRGBColorSpace;
        const sprite = new THREE.Sprite(new THREE.SpriteMaterial({ map: texture, transparent: true, depthWrite: false }));
        const scale = 0.017;
        sprite.scale.set(canvas.width * scale, canvas.height * scale, 1);
        sprite.renderOrder = 10;

        return sprite;
    }

    function halo(radius, hex, double) {
        const group = new THREE.Group();
        const ringMaterial = new THREE.MeshBasicMaterial({ color: color(hex), transparent: true, opacity: 0.85, side: THREE.DoubleSide, depthWrite: false });

        for (let i = 0; i < (double ? 2 : 1); i++) {
            const ring = new THREE.Mesh(new THREE.RingGeometry(radius + i * 0.35, radius + i * 0.35 + 0.12, 48), ringMaterial);
            ring.rotation.x = -Math.PI / 2;
            group.add(ring);
        }

        return { group, material: ringMaterial };
    }

    function buildNode(node, p, withLabel) {
        const group = new THREE.Group();
        group.position.set(p.x, p.y, p.z);
        const hex = healthColor(node);
        const quiet = ['unknown', 'paused', 'maintenance'].includes(node.health);
        const materials = [];
        const pick = [];
        let top = 1;

        if (p.kind === 'tower') {
            // The surface colour with a hint of the health colour, so towers stand out in both themes.
            const tint = '#' + color(colors.surface).lerp(color(hex), 0.22).getHexString();
            const body = new THREE.Mesh(new THREE.BoxGeometry(1.7, p.height, 1.7), material(tint, { emissive: 0.12, opacity: quiet ? 0.55 : 0.95 }));
            body.position.y = p.height / 2;
            const edges = new THREE.LineSegments(new THREE.EdgesGeometry(body.geometry), new THREE.LineBasicMaterial({ color: color(hex), transparent: true }));
            edges.position.copy(body.position);
            const cap = new THREE.Mesh(new THREE.BoxGeometry(1.75, 0.18, 1.75), material(hex, { emissive: 0.9 }));
            cap.position.y = p.height + 0.09;
            group.add(body, edges, cap);
            materials.push(body.material, edges.material, cap.material);
            pick.push(body, cap);
            top = p.height + 0.6;
        } else if (p.kind === 'ring') {
            const ring = new THREE.Mesh(new THREE.TorusGeometry(1.35, 0.14, 12, 48), material(hex, { emissive: 0.7, opacity: quiet ? 0.5 : 1 }));
            ring.rotation.x = Math.PI / 2;
            group.add(ring);
            materials.push(ring.material);
            pick.push(ring);
            top = 0.5;
        } else {
            const radius = p.kind === 'satellite' ? 0.36 : 0.78;
            const orb = new THREE.Mesh(new THREE.SphereGeometry(radius, 32, 16), material(hex, { emissive: 0.45, opacity: quiet ? 0.5 : 1 }));
            group.add(orb);
            materials.push(orb.material);
            pick.push(orb);
            top = radius + 0.55;

            if (quiet) {
                // Not measured, paused or in maintenance: a wire shell, not a solid colour.
                const shell = new THREE.Mesh(new THREE.SphereGeometry(radius * 1.25, 12, 8), new THREE.MeshBasicMaterial({ color: color(hex), wireframe: true, transparent: true, opacity: 0.5 }));
                group.add(shell);
                materials.push(shell.material);
            }
        }

        // Warning: one halo; critical: two.
        if (node.health === 'warning' || node.health === 'critical') {
            const h = halo(p.kind === 'tower' ? 1.6 : p.kind === 'ring' ? 1.7 : 1.1, hex, node.health === 'critical');
            h.group.position.y = p.kind === 'tower' ? 0.05 : p.kind === 'ring' ? 0 : -0.9;
            group.add(h.group);
            materials.push(h.material);
        }

        let label = null;

        if (withLabel) {
            const problem = node.health === 'critical' || node.health === 'warning';
            label = labelSprite(problem ? `${node.label3d} · ${t(`health.${node.health}`)}` : node.label3d, problem || p.kind === 'tower');
            label.position.y = top + 0.7;

            // Service rings sit around a tower: their label goes beside it, not inside.
            if (p.kind === 'ring') label.position.set(2.2, 0.4, 0);
            group.add(label);
            materials.push(label.material);
        }

        for (const mesh of pick) mesh.userData.key = node.key;
        world.add(group);

        return { group, pick, materials, label, node, position: new THREE.Vector3(p.x, p.y + (p.kind === 'tower' ? p.height / 2 : 0), p.z) };
    }

    function buildLink(edge, a, b) {
        const lift = (p) => ({ x: p.x, y: p.y + (p.kind === 'tower' ? p.height * 0.75 : 0), z: p.z });
        const points = arc(lift(a), lift(b)).map(([x, y, z]) => new THREE.Vector3(x, y, z));
        const geometry = new THREE.BufferGeometry().setFromPoints(points);
        const confirmed = edge.origin === 'confirmed';
        const lineMaterial = confirmed
            ? new THREE.LineBasicMaterial({ color: color(colors.subtle), transparent: true, opacity: 0.85 })
            : new THREE.LineDashedMaterial({ color: color(colors.lineStrong), dashSize: 0.45, gapSize: 0.3, transparent: true, opacity: 0.9 });
        const line = new THREE.Line(geometry, lineMaterial);

        if (!confirmed) line.computeLineDistances();
        world.add(line);

        return { edge, line, material: lineMaterial, points, baseColor: confirmed ? colors.subtle : colors.lineStrong };
    }

    function clearWorld() {
        world.traverse((object) => {
            object.geometry?.dispose?.();
            const list = Array.isArray(object.material) ? object.material : object.material ? [object.material] : [];
            list.forEach((m) => { m.map?.dispose?.(); m.dispose?.(); });
        });
        world.clear();
        objects = new Map();
        links = [];
        waves = [];
        pulses = [];
    }

    function buildGround(b) {
        const size = Math.ceil((b.radius * 2 + 30) / 10) * 10;
        const grid = new THREE.GridHelper(size, size / 2, color(colors.lineStrong), color(colors.line));
        grid.material.transparent = true;
        grid.material.opacity = 0.55;
        grid.position.y = -0.02;
        world.add(grid);

        const disc = new THREE.Mesh(new THREE.CircleGeometry(b.radius + 12, 64), new THREE.MeshBasicMaterial({ color: color(colors.surface), transparent: true, opacity: 0.35, depthWrite: false }));
        disc.rotation.x = -Math.PI / 2;
        disc.position.y = -0.04;
        world.add(disc);
    }

    /**
     * Incident waves: from every resource with an active incident, a pulse
     * at its base and glowing beads running along each link towards what
     * depends on it, further links later (the wave spreads by depth).
     */
    function buildWaves(nodes, visible, graph, maxDepth) {
        const byTarget = new Map();

        for (const link of links) {
            if (!byTarget.has(link.edge.target)) byTarget.set(link.edge.target, []);
            byTarget.get(link.edge.target).push(link);
        }

        const used = new Set();

        for (const node of nodes) {
            if (node.incidents.active === 0 || !objects.has(node.key)) continue;

            const hex = severityColor(node);
            const origin = objects.get(node.key);

            const pulse = new THREE.Mesh(new THREE.RingGeometry(0.9, 1.15, 48), new THREE.MeshBasicMaterial({ color: color(hex), transparent: true, opacity: 0.8, side: THREE.DoubleSide, depthWrite: false }));
            pulse.rotation.x = -Math.PI / 2;
            pulse.position.set(origin.position.x, 0.06, origin.position.z);
            world.add(pulse);
            pulses.push({ mesh: pulse, offset: Math.random() });

            // Breadth-first along "depends on me" links, within the visible map.
            const depth = new Map([[node.key, 0]]);
            const queue = [node.key];

            while (queue.length) {
                const key = queue.shift();

                if (depth.get(key) >= maxDepth) continue;

                for (const link of byTarget.get(key) ?? []) {
                    const next = link.edge.source;

                    if (!visible.has(next)) continue;

                    const id = `${link.edge.key}|${node.key}`;

                    if (!used.has(id)) {
                        used.add(id);
                        // Beads run from the dependency (target) to the dependent (source).
                        const path = [...link.points].reverse();

                        if (reducedMotion) {
                            // No moving beads: the affected links keep the incident colour instead.
                            link.material.color.set(hex);
                            link.material.opacity = 1;
                            link.material.userData.wave = true;
                            link.baseColor = hex;
                        } else {
                            for (let k = 0; k < 2; k++) {
                                const bead = new THREE.Mesh(new THREE.SphereGeometry(0.2, 12, 8), new THREE.MeshBasicMaterial({ color: color(hex), transparent: true, blending: THREE.AdditiveBlending, depthWrite: false }));
                                world.add(bead);
                                waves.push({ mesh: bead, points: path, offset: depth.get(key) * 0.55 + k * 0.5, source: link.edge.source, target: link.edge.target });
                            }
                        }
                    }

                    if (!depth.has(next)) {
                        depth.set(next, depth.get(key) + 1);
                        queue.push(next);
                    }
                }
            }
        }
    }

    function build(topology, visible, graph, labelOf) {
        clearWorld();

        const nodes = topology.nodes.filter((n) => visible.has(n.key));
        const edges = topology.edges.filter((e) => visible.has(e.source) && visible.has(e.target));
        const positions = layout3d(nodes, edges);
        const b = bounds(positions);
        const labelAll = nodes.length <= MAX_LABELS;

        buildGround(b);

        for (const node of nodes) {
            const p = positions.get(node.key);

            if (!p) continue;

            // Servers and websites carry a name; services and pages only when something is wrong (the tooltip names the rest).
            const problem = node.health === 'critical' || node.health === 'warning' || node.incidents.active > 0;
            const withLabel = problem || (labelAll && (node.type === 'server' || node.type === 'domain'));
            objects.set(node.key, buildNode({ ...node, label3d: labelOf(node) }, p, withLabel));
        }

        for (const edge of edges) {
            if (positions.has(edge.source) && positions.has(edge.target)) links.push(buildLink(edge, positions.get(edge.source), positions.get(edge.target)));
        }

        buildWaves(nodes, visible, graph, topology.meta.max_depth);

        return b;
    }

    // ---------- camera ----------
    let lastBounds = { radius: 10, top: 6 };

    function fit(animate = true) {
        const b = lastBounds;
        resize();
        // Narrow (portrait) screens need more distance to show the same width.
        const distance = (b.radius * 1.05 + 9) * Math.max(1, 1.25 / (camera.aspect || 1));
        const to = { position: new THREE.Vector3(distance * 0.72, b.radius * 0.55 + b.top + 3, distance * 0.72), target: new THREE.Vector3(0, b.top * 0.2, 0) };
        moveCamera(to, animate);
    }

    function focus(key) {
        const object = objects.get(key);

        if (!object) return;

        const target = object.position.clone();
        const direction = camera.position.clone().sub(controls.target).normalize();
        const distance = Math.min(camera.position.distanceTo(controls.target), 28);
        controls.autoRotate = false;
        moveCamera({ position: target.clone().add(direction.multiplyScalar(Math.max(distance, 14))), target }, true);
    }

    function moveCamera(to, animate) {
        if (!animate || reducedMotion) {
            camera.position.copy(to.position);
            controls.target.copy(to.target);
            controls.update();

            return;
        }

        tween = { from: { position: camera.position.clone(), target: controls.target.clone() }, to, start: performance.now(), duration: 700 };
    }

    // ---------- highlight (same rules as the 2D map) ----------
    function setHighlight({ selected, focusKeys, impact }) {
        for (const [key, object] of objects) {
            const inFocus = !selected || focusKeys.has(key);

            for (const m of object.materials) {
                m.userData.base ??= m.opacity;
                m.opacity = inFocus ? m.userData.base : DIM;
            }

            object.group.scale.setScalar(key === selected ? 1.25 : 1);
        }

        for (const link of links) {
            const inFocus = !selected || (focusKeys.has(link.edge.source) && focusKeys.has(link.edge.target));
            link.material.userData.base ??= link.material.opacity;
            link.material.opacity = inFocus ? link.material.userData.base : DIM * 0.8;

            link.material.color.set(selected && inFocus ? (impact ? colors.bad : colors.accent) : link.baseColor);
        }

        for (const wave of waves) {
            wave.mesh.visible = !selected || (focusKeys.has(wave.source) && focusKeys.has(wave.target));
        }
    }

    // ---------- interaction ----------
    const pickables = () => [...objects.values()].flatMap((o) => o.pick);

    function pick(event) {
        const rect = renderer.domElement.getBoundingClientRect();
        pointer.set(((event.clientX - rect.left) / rect.width) * 2 - 1, -((event.clientY - rect.top) / rect.height) * 2 + 1);
        raycaster.setFromCamera(pointer, camera);
        const hit = raycaster.intersectObjects(pickables(), false)[0];

        return hit ? { key: hit.object.userData.key, x: event.clientX - rect.left, y: event.clientY - rect.top } : null;
    }

    let down = null;
    let moveQueued = null;
    const canvas = renderer.domElement;

    canvas.addEventListener('pointerdown', (event) => { down = { x: event.clientX, y: event.clientY }; });
    canvas.addEventListener('pointerup', (event) => {
        if (!down || Math.hypot(event.clientX - down.x, event.clientY - down.y) > 5) return;

        down = null;
        onSelect(pick(event)?.key ?? null);
    });
    canvas.addEventListener('pointermove', (event) => {
        if (moveQueued) return;

        moveQueued = requestAnimationFrame(() => {
            moveQueued = null;
            const hit = event.buttons ? null : pick(event);
            const key = hit?.key ?? null;
            canvas.style.cursor = key ? 'pointer' : 'grab';

            if (key !== hovered || key) {
                hovered = key;
                onHover(key, hit);
            }
        });
    });
    canvas.addEventListener('pointerleave', () => { hovered = null; onHover(null, null); });

    // ---------- loop ----------
    function resize() {
        const width = container.clientWidth;
        const height = container.clientHeight;

        if (!width || !height) return;

        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
    }

    const observer = new ResizeObserver(resize);
    observer.observe(container);

    function tick() {
        frame = null;

        if (!active || document.hidden) return;

        const elapsed = clock.getElapsedTime();

        if (tween) {
            const p = Math.min(1, (performance.now() - tween.start) / tween.duration);
            const ease = p < 0.5 ? 2 * p * p : 1 - (-2 * p + 2) ** 2 / 2;
            camera.position.lerpVectors(tween.from.position, tween.to.position, ease);
            controls.target.lerpVectors(tween.from.target, tween.to.target, ease);

            if (p === 1) tween = null;
        }

        if (!reducedMotion) {
            for (const wave of waves) {
                const progress = (((elapsed * WAVE_SPEED - wave.offset) % 1) + 1) % 1;
                const i = Math.min(wave.points.length - 2, Math.floor(progress * (wave.points.length - 1)));
                const f = progress * (wave.points.length - 1) - i;
                wave.mesh.position.lerpVectors(wave.points[i], wave.points[i + 1], f);
                wave.mesh.scale.setScalar(0.7 + Math.sin(progress * Math.PI) * 0.6);
            }

            for (const pulse of pulses) {
                const p = (elapsed * 0.6 + pulse.offset) % 1;
                pulse.mesh.scale.setScalar(1 + p * 2.6);
                pulse.mesh.material.opacity = 0.85 * (1 - p);
            }
        }

        controls.update();
        renderer.render(scene, camera);
        schedule();
    }

    function schedule() {
        if (active && !frame && !document.hidden) frame = requestAnimationFrame(tick);
    }

    document.addEventListener('visibilitychange', schedule);

    // ---------- public ----------
    return {
        /** Rebuilds when the shown resources change, otherwise only restyles. */
        render(topology, { visible, graph, labelOf, colors: next, highlight }) {
            const key = `${[...visible].sort().join('|')}#${topology.edges.map((e) => e.key).sort().join('|')}#${topology.nodes.map((n) => `${n.key}:${n.health}:${n.incidents.active}:${n.incidents.max_severity}`).join('|')}#${JSON.stringify(next)}`;
            colors = next;

            if (key !== structure) {
                const first = structure === null;
                structure = key;
                lastBounds = build(topology, visible, graph, labelOf);

                if (first) fit(false);
            }

            setHighlight(highlight);
            resize();
        },
        highlight: setHighlight,
        focus,
        fit: () => fit(true),
        resize,
        setActive(value) {
            active = value;
            canvas.hidden = !value;

            if (value) {
                resize();
                schedule();
            }
        },
        /** Screen position of a resource (for the tooltip). */
        project(key) {
            const object = objects.get(key);

            if (!object) return null;

            const v = object.position.clone().project(camera);

            return { x: ((v.x + 1) / 2) * container.clientWidth, y: ((1 - v.y) / 2) * container.clientHeight };
        },
        destroy() {
            active = false;
            observer.disconnect();
            document.removeEventListener('visibilitychange', schedule);
            clearWorld();
            controls.dispose();
            renderer.dispose();
            canvas.remove();
        },
    };
}
