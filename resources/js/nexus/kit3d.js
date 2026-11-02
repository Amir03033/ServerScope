// Small three.js helpers shared by the NEXUS 3D view and the status page hero.

import * as THREE from 'three';

/** A camera-facing text label drawn on a canvas (no external fonts or images: fits the CSP). */
export function labelSprite(text, { important = false, colors, scale = 0.017 }) {
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');
    const size = 44;
    const font = `${important ? 600 : 500} ${size}px Inter, ui-sans-serif, system-ui, sans-serif`;
    ctx.font = font;
    canvas.width = Math.ceil(ctx.measureText(text).width) + 36;
    canvas.height = size + 28;
    ctx.font = font;
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
    sprite.scale.set(canvas.width * scale, canvas.height * scale, 1);
    sprite.renderOrder = 10;

    return sprite;
}

/** The design tokens of the current theme (hex values from app.css). */
export function themeColors() {
    const css = getComputedStyle(document.documentElement);
    const token = (name, fallback) => css.getPropertyValue(name).trim() || fallback;

    return {
        bg: token('--ss-bg', '#fafafa'), surface: token('--ss-surface', '#ffffff'), line: token('--ss-line', '#e4e4e7'),
        lineStrong: token('--ss-line-strong', '#d4d4d8'), text: token('--ss-text', '#18181b'), muted: token('--ss-muted', '#52525b'),
        subtle: token('--ss-subtle', '#63636b'), accent: token('--ss-accent', '#2f5fd0'),
        ok: token('--ss-ok', '#15803d'), warn: token('--ss-warn', '#b45309'), bad: token('--ss-bad', '#b91c1c'),
    };
}

/** Free a group's geometries, materials and textures. */
export function dispose(object) {
    object.traverse((child) => {
        child.geometry?.dispose?.();
        const list = Array.isArray(child.material) ? child.material : child.material ? [child.material] : [];
        list.forEach((m) => { m.map?.dispose?.(); m.dispose?.(); });
    });
}

/** The point at progress (0..1) along a polyline of Vector3s. */
export function pointAt(points, progress) {
    const i = Math.min(points.length - 2, Math.floor(progress * (points.length - 1)));
    const f = progress * (points.length - 1) - i;

    return new THREE.Vector3().lerpVectors(points[i], points[i + 1], f);
}
