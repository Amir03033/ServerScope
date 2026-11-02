// The detail panel for a selected node. Shows only fields the topology
// endpoint returns on purpose; every value is rendered as text.

import { append, bar, clear, h, icon, status } from './dom.js';
import { HEALTH_TONE, SEVERITY, relativeTime, safeUrl, statusTone } from './model.js';

const ICONS = { server: 'i-server', service: 'i-cpu', domain: 'i-globe', page_group: 'i-file', page: 'i-file' };

const DOMAIN_CHECKS = ['http', 'ssl', 'dns', 'expiry'];

const PAGE_STATES = ['down', 'degraded', 'unknown', 'up', 'paused'];

/** The ss-dot variant for a tone (unknown keeps the plain grey dot). */
export function dotClass(tone) {
    return { ok: 'ss-dot ss-dot-ok', warn: 'ss-dot ss-dot-warn', bad: 'ss-dot ss-dot-bad', info: 'ss-dot ss-dot-accent' }[tone] ?? 'ss-dot';
}

export function typeIcon(type) {
    return ICONS[type] ?? 'i-list';
}

/** Label of a status code for a node type, from the lang files. */
export function statusLabel(config, type, code) {
    return config.labels.status[type]?.[code] ?? config.labels.status.common?.[code] ?? code;
}

/**
 * @param {HTMLElement} root
 * @param {object} context { node, nodes: Map, edges, config, t, labelOf, origin, impactActive, onSelect, onClose, onImpact }
 */
export function renderPanel(root, context) {
    const { node, config, t } = context;
    clear(root);

    const tone = HEALTH_TONE[node.health] ?? 'muted';
    const healthLabel = t(`health.${node.health}`);
    const code = statusLabel(config, node.type, node.status);
    const url = safeUrl(node.url, context.origin);
    const details = node.details ?? {};

    root.append(
        h('div', { class: 'ss-panel-head !mb-0 !flex-nowrap' },
            h('div', { class: 'flex min-w-0 items-start gap-3' },
                h('span', { class: `ss-stat-icon ss-nexus-tone-${tone}` }, icon(typeIcon(node.type))),
                h('div', { class: 'min-w-0' },
                    h('h2', { class: 'ss-panel-title break-words' }, context.labelOf(node)),
                    h('p', { class: 'ss-panel-sub' }, t(`types.${node.type}`)),
                ),
            ),
            h('button', { type: 'button', class: 'ss-icon-btn', 'aria-label': t('panel.close'), title: t('panel.close'), onclick: context.onClose }, icon('i-x')),
        ),
        h('div', { class: 'mt-3 flex flex-wrap gap-1.5' },
            status(healthLabel, tone),
            code !== healthLabel ? status(code, statusTone(node.status)) : null,
        ),
    );

    if (node.incidents.active > 0) {
        root.append(incidentBlock(node, context));
    }

    const facts = [];

    if (node.type === 'server') {
        if (details.environment) facts.push([t('panel.environment'), config.labels.environment[details.environment] ?? details.environment]);
        facts.push([t('panel.last_seen'), details.last_seen_at ? relativeTime(details.last_seen_at, t) : t('panel.never')]);
    }

    if (node.type === 'service' && details.kind) {
        facts.push([t('panel.kind'), config.labels.service_kind[details.kind] ?? details.kind]);
    }

    facts.push([t('panel.impact'), node.blast_radius === 0 ? t('panel.impact_none') : t.count('panel.impact', node.blast_radius)]);

    root.append(h('dl', { class: 'ss-dl mt-4' }, facts.map(([term, value]) => [h('dt', {}, term), h('dd', {}, value)])));

    if (node.blast_radius > 0) {
        root.append(h('button', {
            type: 'button',
            class: `ss-btn ss-btn-sm mt-3 w-full ${context.impactActive ? 'ss-btn-danger' : 'ss-btn-secondary'}`,
            'aria-pressed': context.impactActive ? 'true' : 'false',
            onclick: context.onImpact,
        }, icon('i-siren'), context.impactActive ? t('panel.hide_impact') : t('panel.show_impact')));
    }

    if (node.type === 'server' && details.metrics) {
        root.append(metricsBlock(details.metrics, t));
    }

    if (node.type === 'domain') {
        const checks = DOMAIN_CHECKS.filter((check) => details[`${check}_status`]);

        if (checks.length) {
            root.append(section(t('panel.checks'), h('dl', { class: 'ss-dl' }, checks.map((check) => {
                const value = details[`${check}_status`];

                return [
                    h('dt', {}, config.labels.checks[check].title),
                    h('dd', { class: 'text-right' }, status(config.labels.checks[check].values[value] ?? value, statusTone(value))),
                ];
            }))));
        }
    }

    if (node.type === 'page_group' && details.pages) {
        root.append(section(t.count('panel.pages', details.pages.total), h('div', { class: 'flex flex-wrap gap-1.5' },
            PAGE_STATES.filter((state) => details.pages[state] > 0).map((state) => status(`${details.pages[state]} · ${statusLabel(config, 'page', state)}`, statusTone(state))),
        )));
    }

    // relations() returns null without edges; append() from dom.js skips it (Element.append would print "null").
    append(root, [
        relations('panel.depends_on', context.edges.filter((e) => e.source === node.key), 'target', context),
        relations('panel.used_by', context.edges.filter((e) => e.target === node.key), 'source', context),
    ]);

    if (url) {
        root.append(h('div', { class: 'mt-5 border-t border-line pt-4' },
            h('a', { class: 'ss-btn ss-btn-primary w-full', href: url }, t(`panel.open.${node.type}`)),
        ));
    }
}

function section(title, ...children) {
    return h('section', { class: 'mt-5 border-t border-line pt-4' }, h('h3', { class: 'ss-nexus-heading' }, title), ...children);
}

function incidentBlock(node, context) {
    const { t, config } = context;
    const primary = node.incidents.primary;
    const severity = node.incidents.max_severity;
    const tone = (SEVERITY[severity] ?? SEVERITY.info).tone;
    const url = primary ? safeUrl(primary.url, context.origin) : null;
    const investigationUrl = primary?.investigation_url ? safeUrl(primary.investigation_url, context.origin) : null;

    return h('div', { class: `ss-alert ss-alert-${tone === 'bad' ? 'error' : tone === 'warn' ? 'warning' : 'info'} mt-4 !animate-none`, role: 'note' },
        icon('i-siren'),
        h('div', { class: 'min-w-0 flex-1' },
            h('div', { class: 'flex flex-wrap items-center justify-between gap-2' },
                h('strong', {}, t.count('panel.incidents', node.incidents.active)),
                status(config.labels.severity[severity] ?? severity, tone),
            ),
            primary ? h('div', { class: 'mt-1.5' },
                url ? h('a', { class: 'font-semibold text-fg underline-offset-2 hover:underline', href: url }, primary.title) : h('span', { class: 'font-semibold' }, primary.title),
                h('div', { class: 'mt-0.5 text-xs text-muted' }, [
                    config.labels.status.common[primary.status] ?? primary.status,
                    primary.opened_at ? ` · ${t('panel.opened', { time: relativeTime(primary.opened_at, t) })}` : '',
                    primary.grouped ? ` · ${t('panel.grouped')}` : '',
                ].join('')),
                investigationUrl ? h('a', { class: 'mt-1 inline-block text-xs underline-offset-2 hover:underline', href: investigationUrl }, t('panel.investigation')) : null,
            ) : null,
        ),
    );
}

function metricsBlock(metrics, t) {
    const row = (label, value) => {
        if (value === null || value === undefined) return null;

        const percent = Math.max(0, Math.min(100, Number(value)));

        return h('div', { class: 'grid gap-1.5' },
            h('div', { class: 'flex justify-between text-xs' }, h('span', { class: 'text-muted' }, label), h('strong', { class: 'tabular-nums' }, `${percent.toLocaleString(document.documentElement.lang === 'nl' ? 'nl-NL' : 'en-GB', { maximumFractionDigits: 1 })}%`)),
            bar(percent, label),
        );
    };

    return section(t('panel.metrics'), h('div', { class: 'grid gap-3' },
        row(t('panel.cpu'), metrics.cpu_percent),
        row(t('panel.memory'), metrics.memory_percent),
        metrics.collected_at ? h('div', { class: 'text-xs text-muted' }, t('panel.measured', { time: relativeTime(metrics.collected_at, t) })) : null,
    ));
}

function relations(titleKey, edges, end, context) {
    const { t } = context;
    const items = edges.map((edge) => [edge, context.nodes.get(edge[end])]).filter(([, other]) => other);

    if (items.length === 0) return null;

    return section(t(titleKey, { count: items.length }), h('ul', { class: 'grid' }, items.map(([edge, other]) => {
        const tone = HEALTH_TONE[other.health] ?? 'muted';
        const relation = edge.relation ? t(`relations.${edge.relation}`) : edge.label;

        return h('li', { class: 'ss-row' },
            h('button', { type: 'button', class: 'flex min-w-0 flex-1 items-center gap-2.5 text-left', onclick: () => context.onSelect(other.key) },
                h('span', { class: dotClass(tone), title: t(`health.${other.health}`) }),
                h('span', { class: 'ss-row-main' },
                    h('strong', {}, context.labelOf(other)),
                    h('span', { class: 'ss-row-sub' }, [t(`types.${other.type}`), relation].filter(Boolean).join(' · ')),
                ),
            ),
            h('span', { class: 'ss-chip' }, edge.origin === 'confirmed' ? t('panel.confirmed') : t('panel.structural')),
        );
    })));
}
