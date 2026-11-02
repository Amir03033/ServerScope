// Small DOM helpers for NEXUS. Every value is set as text or as an
// attribute; nothing is ever parsed as HTML.

const SVG = 'http://www.w3.org/2000/svg';

/**
 * h('a', { class: 'ss-btn', href: url }, 'Open', icon('i-link'))
 * Strings and numbers become text nodes; null/false children are skipped.
 */
export function h(tag, attributes = {}, ...children) {
    const element = document.createElement(tag);

    for (const [name, value] of Object.entries(attributes)) {
        if (value === null || value === undefined || value === false) continue;

        if (name.startsWith('on') && typeof value === 'function') {
            element.addEventListener(name.slice(2), value);
        } else if (name === 'dataset') {
            Object.assign(element.dataset, value);
        } else {
            element.setAttribute(name, value === true ? '' : String(value));
        }
    }

    return append(element, children);
}

export function append(element, children) {
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        element.append(child instanceof Node ? child : document.createTextNode(String(child)));
    }

    return element;
}

/** <svg class="ico"><use href="#i-name"/></svg> from the shared sprite. */
export function icon(name, className = 'ico') {
    const svg = document.createElementNS(SVG, 'svg');
    const use = document.createElementNS(SVG, 'use');

    svg.setAttribute('class', className);
    svg.setAttribute('aria-hidden', 'true');
    use.setAttribute('href', `#${name}`);
    svg.append(use);

    return svg;
}

/** A status badge in the x-status style (ss-status ss-status-{tone}). */
export function status(label, tone) {
    return h('span', { class: `ss-status ss-status-${tone}` }, label);
}

/** A usage bar as the views draw it (.ss-bar, width as an attribute). */
export function bar(percent, label) {
    const value = Math.max(0, Math.min(100, Number(percent)));
    const svg = document.createElementNS(SVG, 'svg');
    const track = document.createElementNS(SVG, 'rect');
    const fill = document.createElementNS(SVG, 'rect');

    svg.setAttribute('class', `ss-bar${value >= 90 ? ' is-bad' : value >= 75 ? ' is-warn' : ''}`);
    svg.setAttribute('role', 'meter');
    svg.setAttribute('aria-label', label);
    svg.setAttribute('aria-valuemin', '0');
    svg.setAttribute('aria-valuemax', '100');
    svg.setAttribute('aria-valuenow', String(value));

    for (const [name, value_] of Object.entries({ class: 'track', width: '100%', height: '100%' })) track.setAttribute(name, value_);
    for (const [name, value_] of Object.entries({ class: 'fill', width: `${value}%`, height: '100%', rx: '3' })) fill.setAttribute(name, value_);

    svg.append(track, fill);

    return svg;
}

export function clear(element) {
    element.replaceChildren();

    return element;
}
