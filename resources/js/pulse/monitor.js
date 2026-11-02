// One heartbeat monitor on a canvas: a trace that sweeps from left to
// right like a patient monitor, with a short gap ahead of the writing
// point. The shape comes from the server's vital signs (vitals.js).

import { beat } from './vitals.js';

const SPEED = 70; // px per second
const GAP = 14; // px erased ahead of the writing point

const TONE = { steady: '--ss-ok', irregular: '--ss-warn', chaos: '--ss-bad', flat: '--ss-bad', unknown: '--ss-subtle', rest: '--ss-subtle' };

function token(name, fallback) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback;
}

export function createMonitor(canvas, { reducedMotion }) {
    const ctx = canvas.getContext('2d');
    let width = 0;
    let height = 0;
    let ratio = 1;
    let buffer = new Float32Array(0);
    let head = 0;
    let time = 0;
    let phase = 0;
    let interval = 1;
    let skip = false;
    let carry = 0;
    let vitals = { rhythm: 'unknown', bpm: 60, width: 1 };
    let colors = { line: token('--ss-subtle', '#71717a'), grid: token('--ss-line', '#27272a') };

    function nextInterval() {
        interval = 60 / vitals.bpm;

        // An irregular rhythm: uneven beats, now and then one dropped.
        if (vitals.rhythm === 'irregular') {
            interval *= 0.65 + Math.random() * 0.7;
            skip = Math.random() < 0.12;
        } else {
            skip = false;
        }
    }

    function sample(dt) {
        time += dt;

        switch (vitals.rhythm) {
            case 'steady':
            case 'irregular':
                phase += dt / interval;

                if (phase >= 1) {
                    phase -= 1;
                    nextInterval();
                }

                return skip ? 0 : beat(phase, vitals.width);
            case 'chaos': {
                // Fibrillation: fast, uneven waves with sudden spikes.
                const t = time * Math.PI * 2;
                let v = 0.32 * Math.sin(t * 5.3 + Math.sin(t * 0.7) * 2) + 0.22 * Math.sin(t * 8.9) + (Math.random() - 0.5) * 0.18;
                if (Math.random() < 0.012) v += (Math.random() < 0.5 ? -1 : 1) * (0.6 + Math.random() * 0.4);

                return v;
            }
            case 'rest':
                return 0.04 * Math.sin(time * Math.PI);
            case 'flat':
                return (Math.random() - 0.5) * 0.015;
            default:
                return 0;
        }
    }

    function resize() {
        const w = Math.max(1, Math.round(canvas.clientWidth));
        const h = Math.max(1, Math.round(canvas.clientHeight));
        ratio = Math.min(window.devicePixelRatio || 1, 2);

        if (w === width && h === height) return;

        const old = buffer;
        width = w;
        height = h;
        canvas.width = Math.round(w * ratio);
        canvas.height = Math.round(h * ratio);
        buffer = new Float32Array(w).fill(Number.NaN);
        buffer.set(old.subarray(0, Math.min(old.length, w)));
        head %= w;

        if (reducedMotion) fill();
    }

    /** Write px pixels of trace. */
    function write(px) {
        for (let i = 0; i < px; i++) {
            buffer[head] = sample(1 / SPEED);
            head = (head + 1) % width;
        }
    }

    /** A full, still trace (reduced motion, or right after a change). */
    function fill() {
        head = 0;
        write(width);
        head = width - 1;
    }

    function draw() {
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, width, height);

        // Faint monitor grid.
        ctx.strokeStyle = colors.grid;
        ctx.globalAlpha = 0.45;
        ctx.lineWidth = 1;
        ctx.beginPath();
        for (let x = 0.5; x < width; x += 16) { ctx.moveTo(x, 0); ctx.lineTo(x, height); }
        for (let y = 0.5; y < height; y += 12) { ctx.moveTo(0, y); ctx.lineTo(width, y); }
        ctx.stroke();
        ctx.globalAlpha = 1;

        const mid = height * 0.62;
        const amp = height * 0.48;
        const y = (v) => mid - v * amp;

        ctx.strokeStyle = colors.line;
        ctx.lineWidth = 1.6;
        ctx.lineJoin = 'round';
        ctx.shadowColor = colors.line;
        ctx.shadowBlur = vitals.rhythm === 'unknown' || vitals.rhythm === 'rest' ? 0 : 6;
        ctx.setLineDash(vitals.rhythm === 'unknown' ? [4, 5] : []);

        const segment = (from, to, fade) => {
            ctx.beginPath();
            let open = false;

            for (let x = from; x < to; x++) {
                const v = buffer[x];

                if (Number.isNaN(v)) { open = false; continue; }

                open ? ctx.lineTo(x, y(v)) : ctx.moveTo(x, y(v));
                open = true;
            }

            ctx.globalAlpha = fade;
            ctx.stroke();
        };

        if (reducedMotion) {
            segment(0, width, 1);
        } else {
            // Older trace (after the gap) a little dimmer than the fresh one.
            segment(Math.min(width, head + GAP), width, 0.55);
            segment(0, head, 1);

            const v = buffer[(head - 1 + width) % width];

            if (!Number.isNaN(v)) {
                ctx.globalAlpha = 1;
                ctx.shadowBlur = 12;
                ctx.fillStyle = colors.line;
                ctx.beginPath();
                ctx.arc(head - 1, y(v), 2.4, 0, Math.PI * 2);
                ctx.fill();
            }
        }

        ctx.globalAlpha = 1;
        ctx.shadowBlur = 0;
        ctx.setLineDash([]);
    }

    resize();

    return {
        /** New vital signs; the trace keeps running and changes shape from here on. */
        set(next) {
            const changed = next.rhythm !== vitals.rhythm;
            vitals = next;
            colors = { line: token(TONE[next.rhythm] ?? '--ss-subtle', '#71717a'), grid: token('--ss-line', '#27272a') };
            if (changed) nextInterval();
            if (reducedMotion) fill();
            draw();
        },
        tick(dt) {
            resize();
            carry += dt * SPEED;
            const px = Math.floor(carry);
            carry -= px;
            write(px);
            draw();
        },
        resize() {
            resize();
            draw();
        },
        theme() {
            this.set(vitals);
        },
    };
}
