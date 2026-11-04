// The wall screen's data boards: servers, websites, jobs and deploys,
// incidents, and the overview tiles. Each takes the wall data
// (NexusController::wallData) and returns DOM; nothing is parsed as HTML.

import { h, icon } from '../nexus/dom.js';

const TONE = {
    online: 'ok', offline: 'bad', pending: 'muted', maintenance: 'muted',
    healthy: 'ok', warning: 'warn', critical: 'bad', unknown: 'muted', paused: 'muted',
    up: 'ok', down: 'bad', new: 'muted',
};

/** "4 min", "3 h", "2 d" since an ISO time. */
export function since(iso, t, now = Date.now()) {
    if (!iso) return t('board.never');
    const minutes = Math.max(0, Math.round((now - new Date(iso).getTime()) / 60_000));

    if (minutes < 60) return t('board.ago', { time: t('board.minutes', { count: minutes }) });
    if (minutes < 60 * 24) return t('board.ago', { time: t('board.hours', { count: Math.round(minutes / 60) }) });

    return t('board.ago', { time: `${Math.round(minutes / 1440)} d` });
}

function duration(minutes, t) {
    if (minutes === null || minutes === undefined) return '—';

    return minutes < 120 ? t('board.minutes', { count: minutes }) : t('board.hours', { count: Math.round(minutes / 60) });
}

function uptime(seconds) {
    if (!seconds) return '—';
    const days = Math.floor(seconds / 86_400);

    return days > 0 ? `${days}d ${Math.floor((seconds % 86_400) / 3600)}h` : `${Math.floor(seconds / 3600)}h`;
}

function meter(label, value, number) {
    const tone = value === null ? 'muted' : value >= 90 ? 'bad' : value >= 75 ? 'warn' : 'ok';

    return h('div', { class: `ss-wall-meter is-${tone}` },
        h('span', {}, label),
        h('b', {}, value === null ? '—' : `${number.format(value)}%`),
        h('i', { style: null, dataset: { fill: String(Math.max(0, Math.min(100, value ?? 0))) } }),
    );
}

/** Bars are sized by script (no inline style attributes in markup). */
export function sizeMeters(root) {
    for (const bar of root.querySelectorAll('[data-fill]')) bar.style.setProperty('--fill', `${bar.dataset.fill}%`);
}

export function serversBoard(data, t, number) {
    const servers = data?.servers ?? [];

    return servers.map((s) => h('article', { class: `ss-wall-card is-${TONE[s.status] ?? 'muted'}` },
        h('header', {},
            h('span', { class: 'ss-wall-dot' }),
            h('strong', {}, s.name),
            h('small', {}, t(`board.status.${s.status}`)),
        ),
        h('div', { class: 'ss-wall-meters' },
            meter(t('board.cpu'), s.cpu, number),
            meter(t('board.memory'), s.memory, number),
            meter(t('board.disk'), s.disk, number),
        ),
        h('footer', {},
            h('span', {}, `${t('board.load')} ${s.load === null ? '—' : s.load.toFixed(2)}`),
            h('span', {}, t('board.up', { time: uptime(s.uptime) })),
            s.os ? h('span', {}, s.os) : null,
        ),
    ));
}

export function sitesBoard(data, t, number) {
    const sites = data?.sites ?? [];

    return sites.map((s) => {
        const ssl = s.ssl_days;
        const sslTone = ssl === null ? 'muted' : ssl <= 7 ? 'bad' : ssl <= 21 ? 'warn' : 'ok';
        const ms = s.response_ms;
        const msTone = ms === null ? 'muted' : ms >= 2000 ? 'bad' : ms >= 800 ? 'warn' : 'ok';

        return h('div', { class: `ss-wall-site is-${TONE[s.status] ?? 'muted'}` },
            h('span', { class: 'ss-wall-dot' }),
            h('strong', {}, s.name),
            h('span', { class: `ss-wall-figure is-${msTone}` }, h('small', {}, t('board.response')), ms === null ? '—' : `${number.format(ms)} ms`),
            h('span', { class: 'ss-wall-figure is-quiet' }, h('small', {}, t('board.checked')), since(s.checked_at, t)),
            h('span', { class: `ss-wall-figure is-${sslTone}` }, h('small', {}, t('board.ssl')), ssl === null ? '—' : t('board.days', { days: ssl })),
        );
    });
}

function column(title, items, empty) {
    return h('section', { class: 'ss-wall-column' },
        h('h2', {}, title),
        items.length ? h('ul', { role: 'list' }, items) : h('p', { class: 'ss-wall-quiet' }, empty),
    );
}

export function activityBoard(data, t) {
    const jobs = (data?.jobs ?? []).map((j) => h('li', { class: `is-${TONE[j.status] ?? 'muted'}` },
        h('span', { class: 'ss-wall-dot' }),
        h('div', {}, h('strong', {}, j.name), h('small', {}, [j.server, since(j.last_ping_at, t)].filter(Boolean).join(' · '))),
        h('em', {}, t(`board.job.${j.status}`)),
    ));
    const deploys = (data?.deploys ?? []).map((d) => h('li', { class: 'is-info' },
        icon('i-flag'),
        h('div', {}, h('strong', {}, d.title || '—'), h('small', {}, [d.environment, d.actor].filter(Boolean).join(' · '))),
        h('em', {}, since(d.deployed_at, t)),
    ));

    return [column(t('board.jobs'), jobs, t('board.quiet')), column(t('board.deploys'), deploys, t('board.quiet'))];
}

export function incidentBoard(data, t, severityLabel) {
    const inc = data?.incidents ?? {};
    const row = (i, resolved) => h('li', { class: resolved ? 'is-ok' : (['critical', 'high'].includes(i.severity) ? 'is-bad' : 'is-warn') },
        h('span', { class: 'ss-wall-dot' }),
        h('div', {}, h('strong', {}, i.title), h('small', {}, [resolved ? null : severityLabel(i.severity), i.subject].filter(Boolean).join(' · '))),
        h('em', {}, since(resolved ? i.resolved_at : i.opened_at, t)),
    );
    const logs = (data?.logs?.top ?? []).map((l) => h('li', { class: 'is-bad' },
        icon('i-logs'),
        h('div', {}, h('strong', { class: 'is-mono' }, l.message), h('small', {}, since(l.last_at, t))),
        h('em', {}, t('board.times', { count: l.count })),
    ));

    return [
        column(t('board.open'), (inc.open ?? []).map((i) => row(i, false)), t('board.quiet')),
        h('div', { class: 'ss-wall-stack' },
            column(t('board.recent'), (inc.recent ?? []).map((i) => row(i, true)), t('board.quiet')),
            column(t('board.logs'), logs, t('board.quiet')),
        ),
    ];
}

/** Eight tiles: the topology for servers, sites and response time; the wall data for the rest. */
export function overviewTiles({ vitals, graph, incidents, data }, t, number) {
    const servers = vitals.length;
    const online = vitals.filter((v) => !['flat', 'unknown'].includes(v.rhythm)).length;
    const sites = (graph.nodes ?? []).filter((n) => n.type === 'domain');
    const reachable = sites.filter((n) => n.health === 'ok').length;
    const times = sites.map((n) => n.details?.response_ms).filter((ms) => typeof ms === 'number');
    const average = times.length ? times.reduce((a, b) => a + b, 0) / times.length : null;
    const jobs = data?.jobs ?? [];
    const jobsUp = jobs.filter((j) => j.status === 'up').length;
    const opened = data?.incidents?.opened_24h ?? null;
    const errors = data?.logs?.errors_1h ?? null;
    const tile = (value, label, tone) => h('div', { class: `ss-wall-tile is-${tone}` }, h('div', { class: 'ss-wall-tile-value' }, value), h('div', { class: 'ss-wall-tile-label' }, label));

    return [
        tile(`${online}/${servers}`, t('tiles.servers'), online === servers ? 'ok' : 'bad'),
        tile(`${reachable}/${sites.length}`, t('tiles.sites'), reachable === sites.length ? 'ok' : 'bad'),
        tile(String(incidents.length), t('tiles.incidents'), incidents.length ? 'bad' : 'ok'),
        tile(average === null ? '—' : `${number.format(average)} ms`, t('tiles.response'), 'info'),
        jobs.length ? tile(`${jobsUp}/${jobs.length}`, t('tiles.jobs'), jobsUp === jobs.filter((j) => j.status !== 'paused' && j.status !== 'new').length ? 'ok' : 'bad') : null,
        opened === null ? null : tile(String(opened), t('tiles.opened'), opened ? 'warn' : 'ok'),
        tile(duration(data?.incidents?.mttr_minutes ?? null, t), t('tiles.mttr'), 'info'),
        errors === null ? null : tile(number.format(errors), t('tiles.errors'), errors ? 'warn' : 'ok'),
    ].filter(Boolean);
}
