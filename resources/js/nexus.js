// NEXUS infrastructure map. A separate Vite entry, loaded on /nexus only:
// Cytoscape.js stays out of the shared bundle (as Chart.js in charts.js).

import cytoscape from 'cytoscape';
import { numberLocale } from './i18n';
import { clear, h, icon, status } from './nexus/dom.js';
import { dotClass, renderPanel, typeIcon } from './nexus/panel.js';
import {
    HEALTH_ORDER, HEALTH_TONE, SEVERITY, TYPE_ORDER, adjacency, healthRank, impactOf, layeredPositions,
    structureKey, summarize, translator, upstreamOf, visibleKeys,
} from './nexus/model.js';

const POLL_MS = 30_000;
const MAX_BACKOFF = 8; // x POLL_MS
const TIMEOUT_MS = 15_000;
const NODE_SIZE = { server: 48, domain: 48, service: 36, page_group: 38, page: 32 };

function boot() {
    const root = document.querySelector('[data-nexus]');
    const configElement = document.getElementById('nexus-config');

    if (!root || !configElement) return;

    const config = JSON.parse(configElement.textContent);
    const t = translator(config.texts);
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    // The live status and refresh controls sit in the page header, outside the root.
    const $ = (name) => document.querySelector(`[data-nexus-${name}]`);

    const els = {
        canvas: $('canvas'), list: $('list'), overlay: $('overlay'), notices: $('notices'),
        panel: $('panel'), types: $('types'), health: $('health'), search: $('search'),
        incidents: $('incidents'), fit: $('fit'), view: $('view'), reset: $('reset'),
        live: $('live'), liveText: $('live-text'), pause: $('pause'), refresh: $('refresh'),
        tooltip: $('tooltip'), visible: $('visible'),
    };

    const state = {
        topology: null, nodes: new Map(), graph: null, structure: null,
        selected: null, impact: false, view: 'map', interacted: false, visible: new Set(),
        etag: null, inflight: null, timer: null, failures: 0, lastAttempt: 0, checkedAt: null, stopped: false, paused: false,
        filters: { types: new Set(TYPE_ORDER), health: new Set(HEALTH_ORDER), incidents: false, query: '' },
    };

    // Page groups have no name of their own: "12 pages" in the page's language.
    const labelOf = (node) => (node.type === 'page_group' ? t.count('pages', node.details?.pages?.total ?? 0) : node.label ?? '');
    const formatTime = (iso) => (iso ? new Date(iso).toLocaleTimeString(numberLocale(), { hour: '2-digit', minute: '2-digit', second: '2-digit' }) : '—');

    // ---------- colours from the design tokens (they follow the theme) ----------
    let colors = {};
    const readColors = () => {
        const css = getComputedStyle(document.documentElement);
        const token = (name, fallback) => css.getPropertyValue(name).trim() || fallback;

        colors = {
            bg: token('--ss-bg', '#fafafa'), surface: token('--ss-surface', '#ffffff'), line: token('--ss-line', '#e4e4e7'),
            lineStrong: token('--ss-line-strong', '#d4d4d8'), text: token('--ss-text', '#18181b'), muted: token('--ss-muted', '#52525b'),
            subtle: token('--ss-subtle', '#63636b'), accent: token('--ss-accent', '#2f5fd0'),
            ok: token('--ss-ok', '#15803d'), warn: token('--ss-warn', '#b45309'), bad: token('--ss-bad', '#b91c1c'),
        };
    };
    const toneColor = (tone) => ({ ok: colors.ok, warn: colors.warn, bad: colors.bad, info: colors.accent })[tone] ?? colors.subtle;

    // Node icons come from the shared sprite (partials/icons), so the map uses
    // exactly the icons of the rest of the interface.
    const iconCache = new Map();
    const iconImage = (name, color) => {
        const cacheKey = `${name}|${color}`;

        if (!iconCache.has(cacheKey)) {
            const symbol = document.getElementById(name);
            const svg = symbol
                ? `<svg xmlns="http://www.w3.org/2000/svg" viewBox="${symbol.getAttribute('viewBox')}" fill="none" stroke="${color}" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">${symbol.innerHTML}</svg>`
                : '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"/>';
            iconCache.set(cacheKey, `data:image/svg+xml;charset=utf-8,${encodeURIComponent(svg)}`);
        }

        return iconCache.get(cacheKey);
    };

    const nodeData = (node) => {
        const color = toneColor(HEALTH_TONE[node.health]);

        return {
            id: node.key,
            label: labelOf(node),
            type: node.type,
            health: node.health,
            color,
            icon: iconImage(typeIcon(node.type), color),
            incident: node.incidents.active > 0 ? toneColor((SEVERITY[node.incidents.max_severity] ?? SEVERITY.info).tone) : '',
        };
    };

    const stylesheet = () => [
        {
            selector: 'node',
            style: {
                width: (n) => NODE_SIZE[n.data('type')] ?? 34,
                height: (n) => NODE_SIZE[n.data('type')] ?? 34,
                shape: 'round-rectangle',
                'background-color': colors.surface,
                'background-image': 'data(icon)',
                'background-width': '52%',
                'background-height': '52%',
                'border-width': 1.5,
                'border-color': 'data(color)',
                label: 'data(label)',
                color: colors.muted,
                'font-family': 'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif',
                'font-size': 12,
                'text-valign': 'bottom',
                'text-margin-y': 7,
                'text-wrap': 'ellipsis',
                'text-max-width': 130,
                'text-background-color': colors.bg,
                'text-background-opacity': 0.85,
                'text-background-padding': 2,
                'text-background-shape': 'roundrectangle',
                'transition-property': 'opacity',
                'transition-duration': reducedMotion ? 0 : 150,
            },
        },
        { selector: 'node[type = "domain"], node[type = "page"]', style: { shape: 'ellipse' } },
        // Health is shown by colour and by border style, never by colour alone.
        { selector: 'node[health = "critical"]', style: { 'border-width': 5, 'border-style': 'double' } },
        { selector: 'node[health = "warning"]', style: { 'border-width': 3 } },
        { selector: 'node[health = "unknown"]', style: { 'border-style': 'dashed' } },
        { selector: 'node[health = "maintenance"], node[health = "paused"]', style: { 'border-style': 'dotted', 'background-opacity': 0.7 } },
        { selector: 'node[incident != ""]', style: { 'outline-width': 3, 'outline-color': 'data(incident)', 'outline-offset': 3, 'outline-opacity': 0.85 } },
        { selector: 'node:selected', style: { 'underlay-color': colors.accent, 'underlay-padding': 8, 'underlay-opacity': 0.18, 'underlay-shape': 'round-rectangle', color: colors.text } },
        { selector: 'node.is-impact', style: { 'underlay-color': colors.bad, 'underlay-padding': 7, 'underlay-opacity': 0.16, 'underlay-shape': 'round-rectangle' } },
        {
            selector: 'edge',
            style: {
                width: 1.5,
                'curve-style': 'bezier',
                'line-color': colors.lineStrong,
                'target-arrow-shape': 'triangle',
                'target-arrow-color': colors.lineStrong,
                'arrow-scale': 0.9,
                'transition-property': 'opacity',
                'transition-duration': reducedMotion ? 0 : 150,
            },
        },
        { selector: 'edge[origin = "confirmed"]', style: { width: 2, 'line-color': colors.subtle, 'target-arrow-color': colors.subtle } },
        { selector: 'edge[origin = "structural"]', style: { 'line-style': 'dashed', 'line-dash-pattern': [5, 4] } },
        { selector: 'edge.is-path', style: { 'line-color': colors.accent, 'target-arrow-color': colors.accent, width: 2.5 } },
        { selector: 'edge.is-impact', style: { 'line-color': colors.bad, 'target-arrow-color': colors.bad, width: 2.5 } },
        { selector: '.is-faded', style: { opacity: 0.15 } },
        { selector: '.is-hidden', style: { display: 'none' } },
    ];

    readColors();

    const cy = cytoscape({
        container: els.canvas,
        minZoom: 0.15,
        maxZoom: 2.5,
        boxSelectionEnabled: false,
        selectionType: 'single',
        style: stylesheet(),
    });

    // Follow the theme switch and the system setting, like charts.js.
    const onThemeChange = () => {
        readColors();
        cy.style().fromJson(stylesheet()).update();
        if (state.topology) updateInPlace();
    };
    new MutationObserver(onThemeChange).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', onThemeChange);

    // ---------- filters ----------
    const filterButton = (group, value, label, leading) => h('button', {
        type: 'button', class: 'ss-nexus-filter', 'aria-pressed': 'true', dataset: { group, value },
    }, leading, label, h('span', { class: 'ss-count' }, '0'));

    TYPE_ORDER.forEach((type) => els.types.append(filterButton('types', type, t(`types.${type}`), icon(typeIcon(type)))));
    HEALTH_ORDER.forEach((health) => els.health.append(filterButton('health', health, t(`health.${health}`), h('span', { class: dotClass(HEALTH_TONE[health]), 'aria-hidden': 'true' }))));

    const onFilterClick = (event) => {
        const button = event.target.closest('button[data-group]');

        if (!button) return;

        const set = state.filters[button.dataset.group];
        set.has(button.dataset.value) ? set.delete(button.dataset.value) : set.add(button.dataset.value);
        syncFilterButtons();
        applyFilters({ relayout: true });
    };

    els.types.addEventListener('click', onFilterClick);
    els.health.addEventListener('click', onFilterClick);

    const syncFilterButtons = () => {
        root.querySelectorAll('button[data-group]').forEach((button) => {
            button.setAttribute('aria-pressed', state.filters[button.dataset.group].has(button.dataset.value) ? 'true' : 'false');
        });
        els.incidents.setAttribute('aria-pressed', state.filters.incidents ? 'true' : 'false');
    };

    let searchTimer = null;
    els.search.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.filters.query = els.search.value;
            applyFilters({ relayout: true });
        }, 150);
    });

    els.incidents.addEventListener('click', () => {
        state.filters.incidents = !state.filters.incidents;
        syncFilterButtons();
        applyFilters({ relayout: true });
    });

    els.reset.addEventListener('click', () => {
        state.filters = { types: new Set(TYPE_ORDER), health: new Set(HEALTH_ORDER), incidents: false, query: '' };
        els.search.value = '';
        syncFilterButtons();
        applyFilters({ relayout: true });
    });

    els.fit.addEventListener('click', () => fit(true));
    els.view.addEventListener('click', () => {
        state.view = state.view === 'map' ? 'list' : 'map';
        renderView();
    });
    els.refresh.addEventListener('click', () => refresh());
    els.pause.addEventListener('click', () => {
        state.paused = !state.paused;
        els.pause.setAttribute('aria-pressed', state.paused ? 'true' : 'false');
        els.pause.textContent = state.paused ? t('live.resume') : t('live.pause');
        state.paused ? clearTimeout(state.timer) : refresh();
        setLive(state.paused ? 'paused' : 'ok');
    });

    // ---------- graph building ----------
    function rebuild() {
        const { nodes, edges } = state.topology;
        const keep = state.interacted ? { zoom: cy.zoom(), pan: { ...cy.pan() } } : null;

        cy.batch(() => {
            cy.elements().remove();
            cy.add([
                ...nodes.map((node) => ({ group: 'nodes', data: nodeData(node) })),
                ...edges.map((edge) => ({ group: 'edges', data: { id: edge.key, source: edge.source, target: edge.target, origin: edge.origin } })),
            ]);
        });

        applyFilters({ relayout: true, keepViewport: keep });
    }

    function updateInPlace() {
        cy.batch(() => {
            for (const node of state.topology.nodes) {
                cy.getElementById(node.key).data(nodeData(node));
            }
        });

        applyFilters({ relayout: false });
    }

    function applyFilters({ relayout, keepViewport = null }) {
        if (!state.topology) return;

        const visible = visibleKeys(state.topology, state.filters, state.topology.meta.max_depth, labelOf);
        const changed = visible.size !== state.visible.size || [...visible].some((k) => !state.visible.has(k));
        state.visible = visible;

        cy.batch(() => {
            cy.nodes().forEach((n) => { n.toggleClass('is-hidden', !visible.has(n.id())); });
            cy.edges().forEach((e) => { e.toggleClass('is-hidden', !(visible.has(e.data('source')) && visible.has(e.data('target')))); });

            if (relayout && changed) {
                const shown = state.topology.nodes.filter((n) => visible.has(n.key));
                const positions = layeredPositions(shown, state.topology.edges.filter((e) => visible.has(e.source) && visible.has(e.target)), { labelOf });
                cy.nodes().forEach((n) => { if (positions.has(n.id())) n.position(positions.get(n.id())); });
            }
        });

        if (state.selected && !visible.has(state.selected)) {
            select(null);
        } else {
            highlight();
        }

        if (keepViewport) {
            cy.viewport(keepViewport);
        } else if (relayout && changed) {
            fit(false);
        }

        els.visible.textContent = visible.size === state.topology.nodes.length
            ? t('toolbar.visible_all', { count: visible.size })
            : t('toolbar.visible_some', { visible: visible.size, total: state.topology.nodes.length });

        if (state.view === 'list') renderList();
        if (state.topology.nodes.length > 0) renderOverlay(visible.size === 0 ? 'filtered' : null);
    }

    function fit(animate) {
        const target = cy.elements(':visible');

        if (target.length === 0) return;

        if (animate && !reducedMotion) {
            cy.animate({ fit: { eles: target, padding: 48 } }, { duration: 250 });
        } else {
            cy.fit(target, 48);
        }

        if (cy.zoom() > 1.4) cy.zoom({ level: 1.4, renderedPosition: { x: cy.width() / 2, y: cy.height() / 2 } });
    }

    cy.on('viewport', hideTooltip);
    cy.on('scrollzoom pinchzoom dragpan', () => { state.interacted = true; });
    cy.on('tap', 'node', (event) => select(event.target.id()));
    cy.on('tap', (event) => { if (event.target === cy) select(null); });

    // ---------- selection and impact ----------
    function select(key) {
        state.selected = key && state.nodes.has(key) ? key : null;
        state.impact = false;

        cy.batch(() => {
            cy.nodes(':selected').unselect();
            if (state.selected) cy.getElementById(state.selected).select();
        });

        highlight();
        renderDetail();
        if (state.view === 'list') renderList();
    }

    function highlight() {
        cy.batch(() => {
            cy.elements().removeClass('is-faded is-path is-impact');

            if (!state.selected) return;

            const depth = state.topology.meta.max_depth;
            const impact = impactOf(state.selected, state.graph, depth);
            const focus = new Set([state.selected, ...impact.keys()]);

            if (!state.impact) {
                for (const key of upstreamOf(state.selected, state.graph, depth).keys()) focus.add(key);
            }

            cy.elements().not('.is-hidden').forEach((element) => {
                const inFocus = element.isNode()
                    ? focus.has(element.id())
                    : focus.has(element.data('source')) && focus.has(element.data('target'));

                element.toggleClass('is-faded', !inFocus);

                if (state.impact && inFocus && element.id() !== state.selected) element.addClass('is-impact');
                if (!state.impact && inFocus && element.isEdge()) element.addClass('is-path');
            });
        });
    }

    function renderDetail() {
        const node = state.selected ? state.nodes.get(state.selected) : null;

        if (!node) {
            clear(els.panel).append(
                h('div', { class: 'ss-empty !border-0 !p-2 !text-left' },
                    h('div', { class: 'ss-empty-icon !mx-0' }, icon('i-nexus')),
                    h('p', { class: 'ss-empty-title' }, t('panel.empty_title')),
                    h('p', { class: 'ss-empty-text !mx-0' }, t('panel.empty_text')),
                ),
                legend(),
            );

            return;
        }

        renderPanel(els.panel, {
            node,
            nodes: state.nodes,
            edges: state.topology.edges,
            config,
            t,
            labelOf,
            origin: window.location.origin,
            impactActive: state.impact,
            onSelect: (key) => { select(key); centerOn(key); },
            onClose: () => select(null),
            onImpact: () => { state.impact = !state.impact; highlight(); renderDetail(); },
        });
    }

    function centerOn(key) {
        const element = cy.getElementById(key);

        if (state.view === 'map' && element.nonempty() && element.visible()) {
            cy.animate({ center: { eles: element } }, { duration: reducedMotion ? 0 : 200 });
        }
    }

    function legend() {
        return h('section', { class: 'mt-5 border-t border-line pt-4' },
            h('h3', { class: 'ss-nexus-heading' }, t('legend.title')),
            h('ul', { class: 'grid gap-2 text-[13px]' },
                HEALTH_ORDER.map((health) => h('li', { class: 'flex items-center gap-2.5' }, h('span', { class: `ss-nexus-swatch is-${health} ss-nexus-tone-${HEALTH_TONE[health]}`, 'aria-hidden': 'true' }), t(`health.${health}`))),
                h('li', { class: 'flex items-center gap-2.5' }, h('span', { class: 'ss-nexus-swatch is-incident', 'aria-hidden': 'true' }), t('legend.incident')),
                h('li', { class: 'flex items-center gap-2.5' }, h('span', { class: 'ss-nexus-line', 'aria-hidden': 'true' }), t('legend.confirmed')),
                h('li', { class: 'flex items-center gap-2.5' }, h('span', { class: 'ss-nexus-line is-dashed', 'aria-hidden': 'true' }), t('legend.structural')),
            ),
            h('p', { class: 'mt-3 text-xs text-muted' }, t('legend.direction')),
        );
    }

    // ---------- tooltip ----------
    cy.on('mouseover', 'node', (event) => {
        const node = state.nodes.get(event.target.id());

        if (!node) return;

        const position = event.target.renderedPosition();

        clear(els.tooltip).append(
            h('strong', { class: 'truncate' }, labelOf(node)),
            h('span', { class: 'text-muted' }, `${t(`types.${node.type}`)} · ${t(`health.${node.health}`)}`),
            node.incidents.active > 0 ? h('span', { class: 'ss-text-bad' }, t.count('panel.incidents', node.incidents.active)) : null,
        );
        els.tooltip.style.left = `${position.x}px`;
        els.tooltip.style.top = `${position.y - ((NODE_SIZE[node.type] ?? 34) / 2) * cy.zoom() - 10}px`;
        els.tooltip.hidden = false;
    });
    cy.on('mouseout', 'node', hideTooltip);

    function hideTooltip() {
        els.tooltip.hidden = true;
    }

    // ---------- list view (also the keyboard-accessible alternative) ----------
    function renderView() {
        const list = state.view === 'list';

        els.canvas.parentElement.hidden = list;
        els.list.hidden = !list;
        clear(els.view).append(icon(list ? 'i-nexus' : 'i-list'), list ? t('toolbar.map') : t('toolbar.list'));
        els.view.setAttribute('aria-pressed', list ? 'true' : 'false');

        if (list) {
            renderList();
        } else {
            cy.resize();
            fit(false);
        }
    }

    function renderList() {
        if (!state.topology) return;

        const rows = state.topology.nodes
            .filter((n) => state.visible.has(n.key))
            .sort((a, b) => healthRank(a.health) - healthRank(b.health) || b.incidents.active - a.incidents.active || labelOf(a).localeCompare(labelOf(b)));

        clear(els.list).append(h('div', { class: 'ss-table-wrap' }, h('table', { class: 'ss-table' },
            h('thead', {}, h('tr', {}, h('th', {}, t('list.resource')), h('th', {}, t('list.health')), h('th', {}, t('list.incidents')), h('th', { class: 'num' }, t('list.impact')))),
            h('tbody', {}, rows.map((node) => {
                const severity = node.incidents.max_severity;

                return h('tr', { 'aria-selected': node.key === state.selected ? 'true' : null },
                    h('td', {},
                        h('button', { type: 'button', class: 'inline-flex items-center gap-2 text-left font-semibold hover:text-accent', onclick: () => select(node.key) }, icon(typeIcon(node.type)), labelOf(node)),
                        h('div', { class: 'text-xs text-muted' }, t(`types.${node.type}`)),
                    ),
                    h('td', {}, status(t(`health.${node.health}`), HEALTH_TONE[node.health] ?? 'muted')),
                    h('td', {}, node.incidents.active > 0
                        ? status(`${node.incidents.active} · ${config.labels.severity[severity] ?? severity}`, (SEVERITY[severity] ?? SEVERITY.info).tone)
                        : h('span', { class: 'text-muted' }, '—')),
                    h('td', { class: 'num' }, String(node.blast_radius)),
                );
            })),
        )));
    }

    // ---------- summary, notices and states ----------
    function renderSummary() {
        const { meta, edges } = state.topology;
        const totals = summarize(state.topology);
        const confirmed = edges.filter((e) => e.origin === 'confirmed').length;
        const set = (name, value) => { const el = root.querySelector(`[data-kpi="${name}"]`); if (el) el.textContent = value; };

        set('nodes', String(meta.counts.nodes));
        set('nodes-caption', t('stats.resources_caption', { servers: totals.types.server, services: totals.types.service, domains: totals.types.domain }));
        set('problems', String(totals.problems));
        set('problems-caption', t('stats.problems_caption', { critical: totals.health.critical, warning: totals.health.warning }));
        set('incidents', String(meta.counts.active_incidents));
        set('incidents-caption', t('stats.incidents_caption', { count: totals.withIncidents }));
        set('edges', String(meta.counts.edges));
        set('edges-caption', t('stats.edges_caption', { confirmed, structural: meta.counts.edges - confirmed }));

        const tone = (name, value) => {
            const tile = root.querySelector(`[data-kpi-tile="${name}"]`);
            tile?.classList.remove('is-ok', 'is-warn', 'is-bad');
            if (value) tile?.classList.add(value);
        };
        tone('problems', totals.health.critical ? 'is-bad' : totals.health.warning ? 'is-warn' : 'is-ok');
        tone('incidents', meta.counts.active_incidents ? 'is-bad' : 'is-ok');

        root.querySelectorAll('button[data-group]').forEach((button) => {
            const counts = button.dataset.group === 'types' ? totals.types : totals.health;
            button.querySelector('.ss-count').textContent = String(counts[button.dataset.value] ?? 0);
        });
        els.incidents.querySelector('.ss-count').textContent = String(totals.withIncidents);
    }

    function renderNotices(error = null) {
        clear(els.notices);
        const meta = state.topology?.meta;
        const alert = (kind, iconName, ...content) => h('div', { class: `ss-alert ss-alert-${kind}`, role: kind === 'error' ? 'alert' : 'status' }, icon(iconName), h('div', {}, ...content));

        if (error) {
            els.notices.append(alert('error', 'i-alert',
                h('strong', {}, error.title), ' ', error.message,
                error.retry ? [' ', h('button', { type: 'button', class: 'underline underline-offset-2', onclick: () => refresh() }, t('states.retry'))] : null,
            ));
        }

        if (meta?.truncated?.length) {
            els.notices.append(alert('warning', 'i-alert',
                h('strong', {}, t('states.truncated_title')), ' ',
                t('states.truncated_text', { kinds: meta.truncated.map((k) => t(`truncated.${k}`)).join(', ') }),
            ));
        }

        if (meta?.counts?.unplaced_incidents > 0) {
            els.notices.append(alert('info', 'i-siren',
                t.count('states.unplaced', meta.counts.unplaced_incidents), ' ',
                h('a', { class: 'underline underline-offset-2', href: config.urls.incidents }, t('states.all_incidents')),
            ));
        }

        els.notices.hidden = els.notices.childElementCount === 0;
    }

    function renderOverlay(kind) {
        clear(els.overlay);
        els.overlay.hidden = kind === null;

        if (kind === 'loading') {
            els.overlay.append(h('div', { class: 'flex items-center gap-2.5 text-sm text-muted', role: 'status' }, h('span', { class: 'ss-nexus-spinner', 'aria-hidden': 'true' }), t('states.loading')));
        } else if (kind === 'empty') {
            els.overlay.append(h('div', { class: 'ss-empty bg-surface' },
                h('div', { class: 'ss-empty-icon' }, icon('i-nexus')),
                h('p', { class: 'ss-empty-title' }, t('states.empty_title')),
                h('p', { class: 'ss-empty-text' }, t('states.empty_text')),
                h('div', { class: 'actions' },
                    config.urls.serversAdd ? h('a', { class: 'ss-btn ss-btn-secondary ss-btn-sm', href: config.urls.serversAdd }, icon('i-server'), t('states.add_server')) : null,
                    config.urls.domainsCreate ? h('a', { class: 'ss-btn ss-btn-secondary ss-btn-sm', href: config.urls.domainsCreate }, icon('i-globe'), t('states.add_domain')) : null,
                ),
            ));
        } else if (kind === 'filtered') {
            const incidents = state.filters.incidents;
            els.overlay.append(h('div', { class: 'ss-empty bg-surface' },
                h('div', { class: 'ss-empty-icon' }, icon(incidents ? 'i-check' : 'i-search')),
                h('p', { class: 'ss-empty-title' }, t(incidents ? 'states.no_incidents_title' : 'states.filtered_title')),
                h('p', { class: 'ss-empty-text' }, t(incidents ? 'states.no_incidents_text' : 'states.filtered_text')),
            ));
        }
    }

    function setLive(kind) {
        // When the data was last confirmed (a 200 or a 304), not when the server built it.
        const time = formatTime(state.checkedAt);
        const text = {
            loading: t('live.loading'),
            ok: t('live.updated', { time }),
            paused: t('live.paused', { time }),
            stale: t('live.stale', { time }),
            error: t('live.not_loaded'),
            stopped: t('live.stopped'),
        }[kind];

        els.live.dataset.state = kind;
        els.liveText.textContent = text;
    }

    // ---------- data ----------
    function apply(topology) {
        state.topology = topology;
        state.nodes = new Map(topology.nodes.map((n) => [n.key, n]));
        state.graph = adjacency(topology.edges);

        const structure = structureKey(topology);

        if (structure !== state.structure) {
            state.structure = structure;
            state.visible = new Set();
            rebuild();
        } else {
            updateInPlace();
        }

        renderSummary();
        renderNotices();

        if (topology.nodes.length === 0) renderOverlay('empty');

        // Keep the selection while the resource is still there.
        if (state.selected && !state.nodes.has(state.selected)) {
            select(null);
        } else {
            renderDetail();
        }
    }

    async function refresh() {
        if (state.inflight || state.stopped) return state.inflight;

        clearTimeout(state.timer);
        state.lastAttempt = Date.now();
        els.refresh.disabled = true;

        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), TIMEOUT_MS);

        state.inflight = (async () => {
            try {
                // no-store: let a 304 reach this code instead of being answered by the HTTP cache.
                const response = await fetch(config.urls.graph, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(state.etag ? { 'If-None-Match': state.etag } : {}) },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });

                if (response.status === 304) {
                    state.failures = 0;
                    state.checkedAt = new Date().toISOString();
                    renderNotices();
                    setLive(state.paused ? 'paused' : 'ok');

                    return;
                }

                if (response.status === 401 || response.status === 403 || response.status === 419) {
                    state.stopped = true;
                    renderNotices({ title: t('states.session_title'), message: t('states.session_text') });
                    setLive('stopped');

                    return;
                }

                if (!response.ok) throw new Error(`HTTP ${response.status}`);

                const body = await response.json();

                if (!body?.data?.nodes || !body?.data?.edges || !body?.data?.meta) throw new Error('Unexpected response');

                state.etag = response.headers.get('ETag');
                state.failures = 0;
                state.checkedAt = new Date().toISOString();
                apply(body.data);
                setLive(state.paused ? 'paused' : 'ok');
            } catch {
                state.failures++;
                const first = !state.topology;
                renderNotices({
                    title: t(first ? 'states.load_failed_title' : 'states.update_failed_title'),
                    message: t(first ? 'states.load_failed_text' : 'states.update_failed_text'),
                    retry: true,
                });
                setLive(first ? 'error' : 'stale');

                if (first) renderOverlay(null);
            } finally {
                clearTimeout(timeout);
                state.inflight = null;
                els.refresh.disabled = false;
                schedule();
            }
        })();

        return state.inflight;
    }

    function schedule() {
        clearTimeout(state.timer);

        if (state.stopped || state.paused || document.hidden) return;

        const delay = POLL_MS * Math.min(2 ** state.failures, MAX_BACKOFF);
        state.timer = setTimeout(refresh, Math.max(0, delay - (Date.now() - state.lastAttempt)));
    }

    // Pause while the tab is hidden; catch up at once when it comes back.
    document.addEventListener('visibilitychange', () => {
        if (state.stopped || state.paused) return;

        if (document.hidden) {
            clearTimeout(state.timer);
        } else if (Date.now() - state.lastAttempt >= POLL_MS) {
            refresh();
        } else {
            schedule();
        }
    });

    window.addEventListener('pagehide', () => clearTimeout(state.timer));

    els.live.hidden = false;
    els.pause.textContent = t('live.pause');
    setLive('loading');
    renderDetail();
    renderOverlay('loading');
    refresh();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
