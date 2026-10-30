/*
 * Texts for scripts, rendered by the layout in the active language
 * (<script type="application/json" id="ss-i18n">). Keys mirror lang/*.
 */
let dictionary = null;

function load() {
    if (dictionary === null) {
        try {
            dictionary = JSON.parse(document.getElementById('ss-i18n')?.textContent || '{}');
        } catch {
            dictionary = {};
        }
    }

    return dictionary;
}

export function t(key, replace = {}) {
    let text = load()[key] ?? key;

    for (const [name, value] of Object.entries(replace)) {
        text = text.replaceAll(`:${name}`, String(value));
    }

    return text;
}

/** Locale for dates and numbers: nl-NL or en-GB (day before month). */
export function numberLocale() {
    return document.documentElement.lang === 'nl' ? 'nl-NL' : 'en-GB';
}
