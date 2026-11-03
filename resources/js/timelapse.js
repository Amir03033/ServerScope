// Time travel: the last 24 hours or 7 days as a film over the skyline.
// Towers grow and shrink with the CPU of every frame, incidents flash by.
// Two ways to watch: the director's cut (timelapse/edit.js: quiet hours
// race by, incidents slow down with the camera on their tower and a
// caption) or free play at a chosen speed. The activity strip under the
// picture (mean CPU over the period, incidents in red) is the scrubber.
// Export records the director's cut in the browser (MP4 or WebM) and
// downloads it; nothing leaves the browser.

import { numberLocale } from './i18n';
import { append, clear, h } from './nexus/dom.js';
import { translator } from './nexus/model.js';
import { city } from './skyline/model.js';
import { autoEdit, lengthOf, positionAt, summary, vitalsAt } from './timelapse/edit.js';

const FREE_FPS = 3; // frames of history per second at 1×
const PLAY = 'M8 5.5v13l11-6.5z';
const PAUSE = 'M7 5h3.5v14H7zM13.5 5H17v14h-3.5z';

function boot() {
    const root = document.querySelector('[data-timelapse]');
    const configElement = document.getElementById('timelapse-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const tSkyline = translator(config.skyline);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (name) => root.querySelector(`[data-tl-${name}]`);
    const player = root.querySelector('.ss-tl');
    const locale = numberLocale();

    let data = config.initial;
    let shots = autoEdit(data);
    let mode = 'cut';
    let playing = false;
    let time = 0; // ms into the cut
    let frame = 0; // fractional frame
    let shownFrame = -1;
    let focus;
    let scene = null;
    let recorder = null;
    let current = null;

    const clockOf = (ts) => new Date(ts * 1000).toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' });
    const dayOf = (ts) => new Date(ts * 1000).toLocaleDateString(locale, { weekday: 'short', day: 'numeric', month: 'short' });
    const duration = (seconds) => {
        const m = Math.round(seconds / 60);

        return m < 60 ? t('minutes', { n: m }) : t('hours', { n: Math.round((m / 60) * 10) / 10 });
    };
    const frameIndex = () => Math.max(0, Math.min(data.frames.length - 1, Math.floor(frame)));

    // ---------- what the film says ----------
    function captionFor(shot) {
        if (!shot?.caption) return null;

        if (shot.caption.kind === 'incident') {
            const inc = shot.caption.incident;

            return { tone: inc.severity === 'critical' ? 'bad' : 'warn', kicker: `${clockOf(inc.opened_at)} · ${t(`severity.${inc.severity}`)}`, text: inc.title };
        }

        const p = shot.caption.peak;

        return { tone: 'info', kicker: t('peak_kicker'), text: t('peak', { server: p.server, cpu: Math.round(p.cpu) }) };
    }

    function cardFor(shot) {
        if (shot?.type === 'title') {
            return { kicker: t(`range_title.${data.range}`), title: config.team ?? 'ServerScope', lines: [`${dayOf(data.from)} ${clockOf(data.from)} – ${dayOf(data.to)} ${clockOf(data.to)}`], stats: [] };
        }

        if (shot?.type === 'end') {
            const s = summary(data);

            return {
                kicker: s.incidents === 0 ? t('end_calm') : t('end_title'),
                title: config.team ?? 'ServerScope',
                lines: [],
                stats: [
                    s.uptime === null ? null : { value: `${s.uptime}%`, label: t('stat_uptime') },
                    { value: String(s.incidents), label: t('stat_incidents') },
                    s.longest > 0 ? { value: duration(s.longest), label: t('stat_longest') } : null,
                    s.peak ? { value: `${Math.round(s.peak.cpu)}%`, label: t('stat_peak', { server: s.peak.server }) } : null,
                ].filter(Boolean),
            };
        }

        return null;
    }

    function renderOverlay(shot) {
        const i = frameIndex();
        $('day').textContent = dayOf(data.frames[i]);
        $('time').textContent = clockOf(data.frames[i]);
        $('now').textContent = clockOf(data.frames[i]);
        player.dataset.cinema = String(mode === 'cut' && (playing || Boolean(recorder)));

        const caption = mode === 'cut' ? captionFor(shot) : null;
        const cap = $('caption');
        cap.hidden = !caption;

        if (caption && cap.dataset.text !== caption.text) {
            cap.dataset.text = caption.text;
            cap.dataset.tone = caption.tone;
            clear(cap);
            append(cap, [h('div', { class: 'ss-tl-kicker' }, caption.kicker), h('div', { class: 'ss-tl-caption-text' }, caption.text)]);
        }

        const card = mode === 'cut' ? cardFor(shot) : null;
        const el = $('card');
        el.hidden = !card;
        $('stamp').hidden = Boolean(card);

        if (card && el.dataset.key !== `${shot.type}|${card.title}`) {
            el.dataset.key = `${shot.type}|${card.title}`;
            clear(el);
            append(el, [
                h('div', { class: 'ss-tl-card-kicker' }, card.kicker),
                h('div', { class: 'ss-tl-card-title' }, card.title),
                ...card.lines.map((l) => h('div', { class: 'ss-tl-card-line' }, l)),
                card.stats.length ? h('div', { class: 'ss-tl-card-stats' }, card.stats.map((s) => h('div', { class: 'ss-tl-card-stat' }, h('strong', {}, s.value), h('span', {}, s.label)))) : null,
            ]);
        }

        return { caption, card };
    }

    // ---------- the city at the current frame ----------
    function renderFrame(shot) {
        const i = frameIndex();

        if (i !== shownFrame) {
            shownFrame = i;
            scene?.update(city(vitalsAt(data, i)));
            $('scrub').value = String(i);
        }

        const wanted = mode === 'cut' ? (shot?.focus ?? null) : null;

        if (scene && wanted !== focus) {
            focus = wanted;
            wanted ? scene.focus(wanted) : scene.overview();
        }
    }

    // ---------- the activity strip: mean CPU, incidents, under the scrubber ----------
    function renderStrip() {
        const canvas = $('strip');
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        const w = canvas.clientWidth;
        const hh = canvas.clientHeight;

        if (!w || !hh) return;

        canvas.width = Math.round(w * ratio);
        canvas.height = Math.round(hh * ratio);
        const ctx = canvas.getContext('2d');
        ctx.setTransform(ratio, 0, 0, ratio, 0, 0);
        ctx.clearRect(0, 0, w, hh);
        ctx.fillStyle = 'rgba(255,255,255,.04)';
        ctx.fillRect(0, 0, w, hh);

        const n = data.frames.length;
        const x = (i) => (i / Math.max(1, n - 1)) * w;
        const span = data.to - data.from;

        // Incidents: red (critical) or amber bands behind the curve.
        for (const inc of data.incidents) {
            const a = Math.max(0, (inc.opened_at - data.from) / span) * w;
            const b = Math.min(1, ((inc.resolved_at ?? data.to) - data.from) / span) * w;
            ctx.fillStyle = inc.severity === 'critical' ? 'rgba(255,77,79,.16)' : 'rgba(245,165,36,.13)';
            ctx.fillRect(a, 0, Math.max(3, b - a), hh);
            ctx.fillStyle = inc.severity === 'critical' ? '#ff4d4f' : '#f5a524';
            ctx.fillRect(a, 0, Math.max(3, b - a), 3);
        }

        // Mean CPU of all servers per frame, as a soft area.
        const mean = data.frames.map((_, i) => {
            const values = data.servers.map((s) => s.cpu[i]).filter((v) => v !== null);

            return values.length ? values.reduce((p, q) => p + q, 0) / values.length : null;
        });
        const y = (v) => hh - 4 - (v / 100) * (hh - 10);
        const gradient = ctx.createLinearGradient(0, 0, 0, hh);
        gradient.addColorStop(0, 'rgba(107,140,255,.55)');
        gradient.addColorStop(1, 'rgba(107,140,255,.02)');
        ctx.beginPath();
        let open = false;
        let startX = 0;

        mean.forEach((v, i) => {
            if (v === null) return;

            if (!open) { ctx.moveTo(x(i), hh); startX = x(i); open = true; }
            ctx.lineTo(x(i), y(v));
        });

        if (open) {
            const last = mean.findLastIndex((v) => v !== null);
            ctx.lineTo(x(last), hh);
            ctx.lineTo(startX, hh);
            ctx.fillStyle = gradient;
            ctx.fill();
        }

        ctx.beginPath();
        open = false;
        mean.forEach((v, i) => {
            if (v === null) { open = false; return; }
            open ? ctx.lineTo(x(i), y(v)) : ctx.moveTo(x(i), y(v));
            open = true;
        });
        ctx.strokeStyle = '#8aa4ff';
        ctx.lineWidth = 1.5;
        ctx.stroke();
    }

    function renderIncidents() {
        $('scrub').max = String(data.frames.length - 1);
        $('end').textContent = clockOf(data.frames[data.frames.length - 1]);
        clear($('incidents'));
        append($('incidents'), data.incidents.length === 0
            ? [h('li', { class: 'text-sm text-muted' }, t('no_incidents'))]
            : [...data.incidents].reverse().map((inc) => h('li', { class: 'ss-globe-row' },
                h('div', { class: 'min-w-0' },
                    h('button', { type: 'button', class: 'ss-skyline-name', onclick: () => jump(inc.opened_at) }, inc.title),
                    h('span', { class: 'block text-xs text-muted' }, `${dayOf(inc.opened_at)} ${clockOf(inc.opened_at)} · ${inc.resolved_at ? t('lasted', { duration: duration(inc.resolved_at - inc.opened_at) }) : t('still_open')}`),
                ),
                h('span', { class: `ss-status ss-status-${inc.severity === 'critical' ? 'bad' : 'warn'}` }, t(`severity.${inc.severity}`)),
            )));
    }

    /** Free play from just before a moment (a click on an incident). */
    function jump(ts) {
        setPlaying(false);
        setMode('free');
        frame = Math.max(0, Math.floor((ts - data.from) / data.size) - 1);
        draw();
        $('stage').scrollIntoView({ block: 'nearest', behavior: reducedMotion ? 'auto' : 'smooth' });
    }

    function setMode(next) {
        mode = next;
        root.querySelectorAll('[data-tl-mode]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.tlMode === mode)));
        $('speed').hidden = mode !== 'free';
        focus = undefined;

        if (mode === 'cut') {
            // Continue the cut from the frame you are at.
            let start = 0;
            time = 0;

            for (const shot of shots) {
                if (shot.type === 'play' && frame >= shot.from && frame <= shot.to + 1) {
                    time = start + ((frame - shot.from) / (shot.to - shot.from + 1)) * shot.ms;
                    break;
                }

                start += shot.ms;
            }
        }
    }

    function setPlaying(next) {
        playing = next;
        $('play').setAttribute('aria-label', playing ? t('pause') : t('play'));
        $('play-icon').setAttribute('d', playing ? PAUSE : PLAY);
    }

    // ---------- loop ----------
    let last = null;

    function draw() {
        const pos = mode === 'cut' ? positionAt(shots, time) : null;

        if (pos?.frame !== null && pos?.frame !== undefined) frame = pos.frame;

        current = pos?.shot ?? null;
        renderFrame(current);
        renderOverlay(current);
    }

    function loop(now) {
        const dt = last === null ? 0 : Math.min(0.25, (now - last) / 1000);
        last = now;

        if (playing) {
            if (mode === 'cut') {
                time += dt * 1000;

                if (time >= lengthOf(shots)) {
                    time = lengthOf(shots);
                    setPlaying(false);
                    recorder?.finish();
                }
            } else {
                frame += dt * FREE_FPS * Number($('speed').value);

                if (frame >= data.frames.length - 1) {
                    frame = data.frames.length - 1;
                    setPlaying(false);
                }
            }

            draw();
        }

        recorder?.frame(current);
        requestAnimationFrame(loop);
    }

    // ---------- controls ----------
    $('play').addEventListener('click', () => {
        if (!playing && mode === 'cut' && time >= lengthOf(shots)) time = 0;
        if (!playing && mode === 'free' && frame >= data.frames.length - 1) frame = 0;
        setPlaying(!playing);
        draw();
    });

    $('scrub').addEventListener('input', () => {
        setPlaying(false);
        if (mode === 'cut') setMode('free');
        frame = Number($('scrub').value);
        draw();
    });

    root.querySelectorAll('[data-tl-mode]').forEach((b) => b.addEventListener('click', () => { setMode(b.dataset.tlMode); draw(); }));

    $('range').addEventListener('change', async () => {
        setPlaying(false);
        $('status').textContent = t('loading');

        try {
            const response = await fetch(`${config.urls.data}?range=${encodeURIComponent($('range').value)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });

            if (!response.ok) throw new Error();

            data = (await response.json()).data;
            shots = autoEdit(data);
            time = 0;
            frame = 0;
            shownFrame = -1;
            renderIncidents();
            renderStrip();
            draw();
            $('status').textContent = t('hint');
        } catch {
            $('status').textContent = t('load_failed');
        }
    });

    new ResizeObserver(renderStrip).observe($('strip'));

    // ---------- export: record the director's cut ----------
    $('export').addEventListener('click', () => (recorder ? recorder.cancel() : startRecording()));

    function startRecording() {
        const source = scene?.canvas;

        if (!source || !('MediaRecorder' in window) || !HTMLCanvasElement.prototype.captureStream) {
            $('status').textContent = t('export_unsupported');

            return;
        }

        const type = ['video/mp4;codecs=avc1', 'video/webm;codecs=vp9', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m));

        if (!type) {
            $('status').textContent = t('export_unsupported');

            return;
        }

        // A 1280×720 frame: the skyline, letterbox, timecode, lower third and cards.
        const out = document.createElement('canvas');
        out.width = 1280;
        out.height = 720;
        const ctx = out.getContext('2d');
        const chunks = [];
        const media = new MediaRecorder(out.captureStream(30), { mimeType: type, videoBitsPerSecond: 6_000_000 });
        media.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
        let cancelled = false;
        media.onstop = () => {
            recorder = null;
            $('export-label').textContent = t('export');
            renderOverlay(current);

            if (cancelled) {
                $('status').textContent = t('export_cancelled');

                return;
            }

            const blob = new Blob(chunks, { type: type.split(';')[0] });
            const a = h('a', { href: URL.createObjectURL(blob), download: `serverscope-${data.range}-${new Date(data.to * 1000).toISOString().slice(0, 10)}.${type.includes('mp4') ? 'mp4' : 'webm'}` });
            document.body.append(a);
            a.click();
            a.remove();
            setTimeout(() => URL.revokeObjectURL(a.href), 10_000);
            $('status').textContent = t('export_done');
        };

        const font = (weight, size) => `${weight} ${size}px Inter, ui-sans-serif, system-ui, sans-serif`;
        const total = lengthOf(shots);
        const W = out.width;
        const H = out.height;
        const bar = Math.round(H * 0.07);

        recorder = {
            frame(shot) {
                ctx.fillStyle = '#050814';
                ctx.fillRect(0, 0, W, H);
                const scale = Math.max(W / source.width, H / source.height);
                ctx.drawImage(source, (W - source.width * scale) / 2, (H - source.height * scale) / 2, source.width * scale, source.height * scale);

                const { caption, card } = renderOverlay(shot);
                const i = frameIndex();

                if (card) {
                    const g = ctx.createRadialGradient(W / 2, H / 2, 0, W / 2, H / 2, W * 0.45);
                    g.addColorStop(0, 'rgba(5,8,20,.85)');
                    g.addColorStop(1, 'rgba(5,8,20,.25)');
                    ctx.fillStyle = g;
                    ctx.fillRect(0, 0, W, H);
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#6b8cff';
                    ctx.font = font(700, 18);
                    ctx.fillText(card.kicker.toUpperCase(), W / 2, 270);
                    ctx.fillStyle = '#ffffff';
                    ctx.font = font(800, 68);
                    ctx.fillText(card.title.slice(0, 30), W / 2, 345);
                    ctx.fillStyle = 'rgba(255,255,255,.72)';
                    ctx.font = font(500, 24);
                    card.lines.forEach((line, k) => ctx.fillText(line, W / 2, 395 + k * 36));
                    card.stats.forEach((s, k) => {
                        const cx = W / 2 + (k - (card.stats.length - 1) / 2) * 230;
                        ctx.fillStyle = '#ffffff';
                        ctx.font = font(700, 40);
                        ctx.fillText(s.value, cx, 430);
                        ctx.fillStyle = 'rgba(255,255,255,.55)';
                        ctx.font = font(600, 15);
                        ctx.fillText(s.label.toUpperCase(), cx, 460);
                    });
                    ctx.textAlign = 'left';
                } else {
                    // Letterbox, timecode, lower third.
                    ctx.fillStyle = '#000';
                    ctx.fillRect(0, 0, W, bar);
                    ctx.fillRect(0, H - bar, W, bar);
                    ctx.textAlign = 'right';
                    ctx.fillStyle = 'rgba(255,255,255,.65)';
                    ctx.font = font(600, 15);
                    ctx.fillText(dayOf(data.frames[i]).toUpperCase(), W - 40, bar + 34);
                    ctx.fillStyle = '#ffffff';
                    ctx.font = font(700, 48);
                    ctx.fillText(clockOf(data.frames[i]), W - 40, bar + 82);
                    ctx.textAlign = 'left';

                    if (caption) {
                        const y0 = H - bar - 130;
                        ctx.fillStyle = 'rgba(8,10,20,.88)';
                        ctx.fillRect(40, y0, 640, 104);
                        ctx.fillStyle = caption.tone === 'bad' ? '#ff4d4f' : caption.tone === 'warn' ? '#f5a524' : '#6b8cff';
                        ctx.fillRect(40, y0, 5, 104);
                        ctx.font = font(700, 15);
                        ctx.fillText(caption.kicker.toUpperCase(), 66, y0 + 36);
                        ctx.fillStyle = '#ffffff';
                        ctx.font = font(700, 32);
                        ctx.fillText(caption.text.slice(0, 40), 66, y0 + 78);
                    }
                }

                // Progress line and signature on the bottom bar.
                ctx.fillStyle = 'rgba(255,255,255,.12)';
                ctx.fillRect(0, H - 3, W, 3);
                ctx.fillStyle = '#6b8cff';
                ctx.fillRect(0, H - 3, W * Math.min(1, time / total), 3);
                ctx.fillStyle = 'rgba(229,231,235,.75)';
                ctx.font = font(700, 16);
                ctx.fillText('ServerScope', 40, bar - 18 > 20 ? bar - 18 : 34);

                $('status').textContent = t('export_progress', { done: Math.round(time / 1000), total: Math.round(total / 1000) });
            },
            finish() {
                if (media.state === 'recording') setTimeout(() => media.stop(), 300);
            },
            cancel() {
                cancelled = true;
                setPlaying(false);
                media.stop();
            },
        };

        setMode('cut');
        time = 0;
        shownFrame = -1;
        draw();
        media.start(1000);
        $('export-label').textContent = t('export_cancel');
        setPlaying(true);
    }

    // ---------- start ----------
    const webgl = (() => {
        try {
            const c = document.createElement('canvas');

            return Boolean(window.WebGLRenderingContext && (c.getContext('webgl2') || c.getContext('webgl')));
        } catch {
            return false;
        }
    })();

    renderIncidents();
    renderStrip();

    if (webgl) {
        $('overlay').hidden = false;
        import('./skyline/scene.js').then(({ createSkyline }) => {
            scene = createSkyline($('stage'), {
                reducedMotion,
                t: tSkyline,
                record: true,
                zoom: 0.9,
                // In the film a tower says its load at that moment, not "lights on".
                label: (tower) => (tower.cpu === null ? `${tower.name} · —` : `${tower.name} · ${Math.round(tower.cpu)}%`),
            });
            $('overlay').hidden = true;
            shownFrame = -1;
            draw();
        }).catch(() => { $('overlay').hidden = true; });
    } else {
        $('status').textContent = t('no_webgl');
    }

    draw();
    requestAnimationFrame(loop);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
