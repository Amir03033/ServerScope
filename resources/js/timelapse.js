// Time travel: the last 24 hours or 7 days as a film over the skyline.
// Towers grow and shrink with the CPU of every frame, incidents flash by.
// Two ways to watch: the director's cut (timelapse/edit.js: quiet hours
// race by, incidents slow down with the camera on their tower and a
// caption) or free play at a chosen speed; the slider scrubs either way.
// Export records the director's cut in the browser (WebM, or MP4 where
// the browser records that) and downloads it; nothing leaves the browser.

import { numberLocale } from './i18n';
import { append, clear, h } from './nexus/dom.js';
import { translator } from './nexus/model.js';
import { city } from './skyline/model.js';
import { autoEdit, lengthOf, positionAt, summary, vitalsAt } from './timelapse/edit.js';

const FREE_FPS = 3; // frames of history per second at 1×

function boot() {
    const root = document.querySelector('[data-timelapse]');
    const configElement = document.getElementById('timelapse-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const tSkyline = translator(config.skyline);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const $ = (name) => root.querySelector(`[data-tl-${name}]`);
    const locale = numberLocale();

    let data = config.initial;
    let shots = autoEdit(data);
    let mode = 'cut';
    let playing = false;
    let time = 0; // ms into the cut
    let frame = 0; // fractional frame
    let shownFrame = -1;
    let focus = undefined;
    let scene = null;
    let recorder = null;

    const when = (ts, withDay = data.range === '7d') => new Date(ts * 1000).toLocaleString(locale, withDay ? { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' } : { hour: '2-digit', minute: '2-digit' });
    const duration = (seconds) => {
        const m = Math.round(seconds / 60);

        return m < 60 ? t('minutes', { n: m }) : t('hours', { n: Math.round((m / 60) * 10) / 10 });
    };

    // ---------- overlay: what the film says ----------
    function captionFor(shot) {
        if (!shot?.caption) return null;

        if (shot.caption.kind === 'incident') {
            const inc = shot.caption.incident;

            return { tone: inc.severity === 'critical' ? 'bad' : 'warn', kicker: `${when(inc.opened_at)} · ${t(`severity.${inc.severity}`)}`, text: inc.title };
        }

        const p = shot.caption.peak;

        return { tone: 'info', kicker: t('peak_kicker'), text: t('peak', { server: p.server, cpu: Math.round(p.cpu) }) };
    }

    function cardFor(shot) {
        if (shot?.type === 'title') {
            return { title: config.team ?? 'ServerScope', lines: [t(`range_title.${data.range}`), `${when(data.from, true)} – ${when(data.to, true)}`] };
        }

        if (shot?.type === 'end') {
            const s = summary(data);

            return {
                title: s.incidents === 0 ? t('end_calm') : t('end_title'),
                lines: [
                    s.uptime === null ? null : t('end_uptime', { uptime: s.uptime }),
                    t('end_incidents', { count: s.incidents, critical: s.critical }),
                    s.longest > 0 ? t('end_longest', { duration: duration(s.longest) }) : null,
                    s.peak ? t('peak', { server: s.peak.server, cpu: Math.round(s.peak.cpu) }) : null,
                ].filter(Boolean),
            };
        }

        return null;
    }

    function renderOverlay(shot) {
        const i = Math.max(0, Math.min(data.frames.length - 1, Math.floor(frame)));
        $('clock').textContent = when(data.frames[i], true);

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

        if (card && el.dataset.title !== `${card.title}|${shot.type}`) {
            el.dataset.title = `${card.title}|${shot.type}`;
            clear(el);
            append(el, [h('div', { class: 'ss-tl-card-title' }, card.title), ...card.lines.map((l) => h('div', { class: 'ss-tl-card-line' }, l))]);
        }

        return { caption, card };
    }

    // ---------- the city at the current frame ----------
    function renderFrame(shot) {
        const i = Math.max(0, Math.min(data.frames.length - 1, Math.floor(frame)));

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

    // ---------- timeline: incidents as marks ----------
    function renderTimeline() {
        const scrub = $('scrub');
        scrub.max = String(data.frames.length - 1);
        clear($('marks'));
        append($('marks'), data.incidents.map((inc) => {
            const span = data.to - data.from;
            const left = Math.max(0, (inc.opened_at - data.from) / span) * 100;
            const right = Math.min(1, ((inc.resolved_at ?? data.to) - data.from) / span) * 100;
            const mark = h('span', { class: `ss-tl-mark is-${inc.severity === 'critical' ? 'bad' : 'warn'}`, title: inc.title });
            mark.style.left = `${left}%`;
            mark.style.width = `${Math.max(0.6, right - left)}%`;

            return mark;
        }));

        clear($('incidents'));
        append($('incidents'), data.incidents.length === 0
            ? [h('li', { class: 'text-sm text-muted' }, t('no_incidents'))]
            : [...data.incidents].reverse().map((inc) => h('li', { class: 'ss-globe-row' },
                h('div', { class: 'min-w-0' },
                    h('button', { type: 'button', class: 'ss-skyline-name', onclick: () => jump(inc.opened_at) }, inc.title),
                    h('span', { class: 'block text-xs text-muted' }, `${when(inc.opened_at, true)} · ${inc.resolved_at ? t('lasted', { duration: duration(inc.resolved_at - inc.opened_at) }) : t('still_open')}`),
                ),
                h('span', { class: `ss-status ss-status-${inc.severity === 'critical' ? 'bad' : 'warn'}` }, t(`severity.${inc.severity}`)),
            )));
    }

    /** Free play from a moment (a click on an incident). */
    function jump(ts) {
        setMode('free');
        frame = Math.max(0, Math.floor((ts - data.from) / data.size) - 1);
        shownFrame = -1;
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
        const button = $('play');
        button.setAttribute('aria-label', playing ? t('pause') : t('play'));
        button.querySelector('use').setAttribute('href', playing ? '#i-pause' : '#i-play');
    }

    // ---------- loop ----------
    let last = null;
    let current = null;

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
            renderTimeline();
            draw();
            $('status').textContent = t('hint');
        } catch {
            $('status').textContent = t('load_failed');
        }
    });

    // ---------- export: record the director's cut ----------
    $('export').addEventListener('click', () => (recorder ? recorder.cancel() : startRecording()));

    function startRecording() {
        const source = scene?.canvas;

        if (!source || !('MediaRecorder' in window) || !HTMLCanvasElement.prototype.captureStream) {
            $('status').textContent = t('export_unsupported');

            return;
        }

        // A 1280×720 frame: the skyline, the clock, captions and cards drawn on top.
        const out = document.createElement('canvas');
        out.width = 1280;
        out.height = 720;
        const ctx = out.getContext('2d');
        const type = ['video/mp4;codecs=avc1', 'video/webm;codecs=vp9', 'video/webm'].find((m) => MediaRecorder.isTypeSupported(m));

        if (!type) {
            $('status').textContent = t('export_unsupported');

            return;
        }

        const chunks = [];
        const media = new MediaRecorder(out.captureStream(30), { mimeType: type, videoBitsPerSecond: 6_000_000 });
        media.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
        let cancelled = false;
        media.onstop = () => {
            recorder = null;
            $('export-label').textContent = t('export');

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

        recorder = {
            frame(shot) {
                ctx.fillStyle = '#050814';
                ctx.fillRect(0, 0, out.width, out.height);
                // Cover-fit the skyline.
                const scale = Math.max(out.width / source.width, out.height / source.height);
                const w = source.width * scale;
                const hh = source.height * scale;
                ctx.drawImage(source, (out.width - w) / 2, (out.height - hh) / 2, w, hh);

                const { caption, card } = renderOverlay(shot);
                const i = Math.max(0, Math.min(data.frames.length - 1, Math.floor(frame)));
                ctx.fillStyle = 'rgba(5,8,20,.65)';
                ctx.fillRect(28, 24, 330, 52);
                ctx.fillStyle = '#e5e7eb';
                ctx.font = font(600, 26);
                ctx.fillText(when(data.frames[i], true), 44, 60);

                if (caption) {
                    ctx.fillStyle = 'rgba(20,6,8,.85)';
                    ctx.fillRect(140, 560, 1000, 120);
                    ctx.fillStyle = caption.tone === 'bad' ? '#ff4d4f' : caption.tone === 'warn' ? '#f5a524' : '#7c9cff';
                    ctx.fillRect(140, 560, 6, 120);
                    ctx.font = font(700, 20);
                    ctx.fillText(caption.kicker.toUpperCase(), 170, 600);
                    ctx.fillStyle = '#ffffff';
                    ctx.font = font(700, 38);
                    ctx.fillText(caption.text.slice(0, 48), 170, 650);
                }

                if (card) {
                    ctx.fillStyle = 'rgba(5,8,20,.78)';
                    ctx.fillRect(0, 0, out.width, out.height);
                    ctx.textAlign = 'center';
                    ctx.fillStyle = '#ffffff';
                    ctx.font = font(700, 64);
                    ctx.fillText(card.title.slice(0, 32), out.width / 2, 300);
                    ctx.fillStyle = '#cbd5e1';
                    ctx.font = font(500, 28);
                    card.lines.forEach((line, k) => ctx.fillText(line, out.width / 2, 370 + k * 44));
                    ctx.textAlign = 'left';
                }

                // Progress and signature.
                ctx.fillStyle = 'rgba(255,255,255,.15)';
                ctx.fillRect(0, out.height - 4, out.width, 4);
                ctx.fillStyle = '#6b8cff';
                ctx.fillRect(0, out.height - 4, out.width * Math.min(1, time / total), 4);
                ctx.fillStyle = 'rgba(229,231,235,.7)';
                ctx.font = font(600, 18);
                ctx.textAlign = 'right';
                ctx.fillText('ServerScope', out.width - 28, 52);
                ctx.textAlign = 'left';

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
        focus = undefined;
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

    renderTimeline();

    if (webgl) {
        $('overlay').hidden = false;
        import('./skyline/scene.js').then(({ createSkyline }) => {
            scene = createSkyline($('stage'), { reducedMotion, t: tSkyline, record: true });
            $('overlay').hidden = true;
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
