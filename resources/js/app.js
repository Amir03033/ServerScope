/*
 * ServerScope app shell. Plain JavaScript, no dependencies. Everything is
 * progressive enhancement: without JavaScript every page and form works.
 * Texts come from the page (resources/js/i18n.js).
 */
import { numberLocale, t } from './i18n';
import './live';
import './peek';
import './palette';
import './livelists';
import './install';
import './swipe';
import './widgets';

const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

/* ------------------------------------------------------------------ */
/* Theme: system (default), light or dark; optionally an extra theme     */
/* (midnight, terminal, contrast) on top, only when chosen explicitly    */
/* (Profile → Appearance, or the command palette). Stored in this browser. */
/* ------------------------------------------------------------------ */

const themeButton = document.querySelector('[data-theme-toggle]');

function storedTheme() {
    try {
        return localStorage.getItem('ss-theme') || 'system';
    } catch {
        return 'system';
    }
}

const VARIANTS = { midnight: 'dark', terminal: 'dark', contrast: 'light' };

function storedVariant() {
    try {
        const v = localStorage.getItem('ss-theme-variant');

        return v in VARIANTS ? v : null;
    } catch {
        return null;
    }
}

function applyTheme(theme) {
    const variant = storedVariant();
    const base = variant ? VARIANTS[variant] : theme;

    if (variant) {
        document.documentElement.setAttribute('data-variant', variant);
    } else {
        document.documentElement.removeAttribute('data-variant');
    }

    if (base === 'light' || base === 'dark') {
        document.documentElement.setAttribute('data-theme', base);
    } else {
        document.documentElement.removeAttribute('data-theme');
    }

    document.querySelectorAll('[data-theme-choice]').forEach((input) => {
        input.checked = input.value === (variant ?? theme);
    });

    if (themeButton) {
        const labels = JSON.parse(themeButton.dataset.labels || '{}');
        themeButton.setAttribute('aria-label', `${themeButton.dataset.labelPrefix}: ${labels[theme] || theme}`);
        themeButton.title = themeButton.getAttribute('aria-label');
        themeButton.querySelectorAll('[data-theme-icon]').forEach((icon) => {
            icon.toggleAttribute('hidden', icon.dataset.themeIcon !== (variant ? VARIANTS[variant] : theme));
        });

        if (variant) {
            const names = JSON.parse(themeButton.dataset.variants || '{}');
            themeButton.setAttribute('aria-label', `${themeButton.dataset.labelPrefix}: ${names[variant] || variant}`);
            themeButton.title = themeButton.getAttribute('aria-label');
        }
    }
}

/** Choose a theme: system, light, dark, or an extra theme (midnight, terminal, contrast). */
function chooseTheme(choice) {
    try {
        if (choice in VARIANTS) {
            localStorage.setItem('ss-theme-variant', choice);
        } else {
            localStorage.removeItem('ss-theme-variant');
            choice === 'system' ? localStorage.removeItem('ss-theme') : localStorage.setItem('ss-theme', choice);
        }
    } catch {
        // Storage blocked: the choice still applies to this page.
    }

    applyTheme(choice in VARIANTS ? storedTheme() : choice);
}

// The picker on the profile page and the command palette use this event.
document.addEventListener('ss:theme', (event) => chooseTheme(event.detail));
document.querySelectorAll('[data-theme-choice]').forEach((input) => {
    input.addEventListener('change', () => chooseTheme(input.value));
});
applyTheme(storedTheme());

if (themeButton) {
    themeButton.hidden = false;
    applyTheme(storedTheme());

    themeButton.addEventListener('click', () => {
        // The switch cycles the default themes; it also leaves an extra theme.
        const order = ['system', 'light', 'dark'];
        const next = storedVariant() ? storedTheme() : order[(order.indexOf(storedTheme()) + 1) % order.length];

        try {
            localStorage.removeItem('ss-theme-variant');
            if (next === 'system') {
                localStorage.removeItem('ss-theme');
            } else {
                localStorage.setItem('ss-theme', next);
            }
        } catch {
            // Storage blocked: the switch still works for this page.
        }

        applyTheme(next);
    });
}

/* ------------------------------------------------------------------ */
/* Mobile navigation: the sidebar slides in. Without JS it opens via    */
/* the #sidebar anchor (CSS :target).                                   */
/* ------------------------------------------------------------------ */

const sidebar = document.querySelector('[data-sidebar]');
const sidebarOpeners = document.querySelectorAll('[data-sidebar-open]');
let sidebarReturnFocus = null;

function focusables(root) {
    return [...root.querySelectorAll('a[href], button:not([disabled]), select, input, [tabindex]:not([tabindex="-1"])')]
        .filter((el) => el.offsetParent !== null);
}

function openSidebar() {
    sidebarReturnFocus = document.activeElement;
    sidebar.setAttribute('data-open', '');
    sidebarOpeners.forEach((el) => el.setAttribute('aria-expanded', 'true'));
    document.body.style.overflow = 'hidden';
    // Hidden elements cannot take focus: wait until the menu is visible.
    const target = sidebar.querySelector('[aria-current="page"]') || focusables(sidebar)[0];
    let tries = 0;
    const focusWhenVisible = () => {
        target?.focus();

        if (target && document.activeElement !== target && tries++ < 30 && sidebar.hasAttribute('data-open')) {
            setTimeout(focusWhenVisible, 16);
        }
    };
    focusWhenVisible();
}

function closeSidebar() {
    if (!sidebar?.hasAttribute('data-open')) return;

    sidebar.removeAttribute('data-open');
    sidebarOpeners.forEach((el) => el.setAttribute('aria-expanded', 'false'));
    document.body.style.overflow = '';
    sidebarReturnFocus?.focus();
}

if (sidebar) {
    sidebarOpeners.forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        openSidebar();
    }));

    document.querySelectorAll('[data-sidebar-close]').forEach((el) => el.addEventListener('click', (event) => {
        event.preventDefault();
        closeSidebar();
    }));

    document.addEventListener('keydown', (event) => {
        if (!sidebar.hasAttribute('data-open')) return;

        if (event.key === 'Escape') {
            closeSidebar();
        } else if (event.key === 'Tab') {
            // Keep focus inside the open menu.
            const items = focusables(sidebar);
            const first = items[0];
            const last = items[items.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    window.matchMedia('(min-width: 1024px)').addEventListener('change', (event) => {
        if (event.matches) closeSidebar();
    });
}

/* Menus (<details>): close on Escape and on a click outside. Delegated, so
   menus in rows that live lists replace keep working. */
document.addEventListener('keydown', (event) => {
    const menu = event.target.closest?.('[data-menu]');

    if (event.key === 'Escape' && menu?.open) {
        menu.open = false;
        menu.querySelector('summary')?.focus();
    }
});

document.addEventListener('click', (event) => {
    document.querySelectorAll('[data-menu][open]').forEach((menu) => {
        if (!menu.contains(event.target)) menu.open = false;
    });
});

/* The right shortcut for this platform. */
document.querySelectorAll('[data-shortcut-label]').forEach((label) => {
    label.textContent = isMac ? '⌘ K' : 'Ctrl K';
});

/* ------------------------------------------------------------------ */
/* Team switch: submit on change (replaces the inline onchange).        */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-team-switch]').forEach((form) => {
    form.querySelector('select')?.addEventListener('change', (event) => {
        form.action = form.action.replace(/\/teams\/\d+\//, `/teams/${event.target.value}/`);
        form.submit();
    });
});

/* Filters that apply on change (data-auto-submit); a <noscript> button covers no-JS. */
document.querySelectorAll('form[data-auto-submit]').forEach((form) => {
    form.querySelectorAll('select').forEach((select) => select.addEventListener('change', () => form.requestSubmit?.() ?? form.submit()));
});

/* ------------------------------------------------------------------ */
/* Confirmation for destructive actions (data-confirm, same text as     */
/* before) and a loading state on submit so forms cannot be sent twice. */
/* ------------------------------------------------------------------ */

const confirmDialog = document.querySelector('[data-confirm-dialog]');

function askConfirmation(form, submitter) {
    if (!confirmDialog || typeof confirmDialog.showModal !== 'function') {
        return Promise.resolve(window.confirm(form.dataset.confirm));
    }

    const button = confirmDialog.querySelector('[data-confirm-button]');
    const label = (submitter?.textContent || '').trim();
    const destructive = (form.querySelector('input[name="_method"]')?.value || '').toUpperCase() === 'DELETE'
        || form.dataset.confirmDanger === 'yes';

    button.dataset.defaultLabel ??= button.textContent;
    confirmDialog.querySelector('[data-confirm-text]').textContent = form.dataset.confirm;
    button.textContent = label || button.dataset.defaultLabel;
    button.classList.toggle('ss-btn-danger', destructive);
    button.classList.toggle('ss-btn-primary', !destructive);

    return new Promise((resolve) => {
        confirmDialog.returnValue = '';
        confirmDialog.addEventListener('close', () => resolve(confirmDialog.returnValue === 'confirm'), { once: true });
        confirmDialog.showModal();
    });
}

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (event.defaultPrevented || form.method === 'dialog') {
        return;
    }

    if (form.dataset.confirm && form.dataset.confirmed !== 'yes') {
        event.preventDefault();
        const submitter = event.submitter;

        askConfirmation(form, submitter).then((confirmed) => {
            if (!confirmed) return;

            form.dataset.confirmed = 'yes';
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(submitter && submitter.form === form ? submitter : undefined);
            } else {
                form.submit();
            }
        });

        return;
    }

    delete form.dataset.confirmed;

    if (form.method.toLowerCase() === 'get' || form.hasAttribute('data-no-loading')) {
        return;
    }

    setTimeout(() => {
        form.querySelectorAll('button[type="submit"], button:not([type])').forEach((button) => {
            button.disabled = true;
            button.classList.add('is-loading');
        });
    }, 0);
});

/* Pages restored from the back/forward cache: re-enable buttons. */
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;

    document.querySelectorAll('button.is-loading').forEach((button) => {
        button.disabled = false;
        button.classList.remove('is-loading');
    });
});

/* ------------------------------------------------------------------ */
/* Bulk selection (websites.pages): select all, a live count, a sticky  */
/* bar while rows are selected, and a confirmation before deleting.     */
/* ------------------------------------------------------------------ */

document.querySelectorAll('form[data-bulk-form]').forEach((form) => {
    const all = form.querySelector('[data-select-all]');
    const rows = () => [...form.querySelectorAll('[data-row-select]')];
    const counter = form.querySelector('[data-selected-count]');
    const bar = form.querySelector('[data-bulk-bar]');
    const action = form.querySelector('select[name="action"]');

    const update = () => {
        const selected = rows().filter((row) => row.checked).length;
        if (counter) counter.textContent = (form.dataset.selectedText || ':count').replace(':count', String(selected));
        bar?.classList.toggle('is-active', selected > 0);
        if (all) {
            all.checked = selected > 0 && selected === rows().length;
            all.indeterminate = selected > 0 && selected < rows().length;
        }
    };

    const syncConfirm = () => {
        if (action?.value === 'delete') {
            form.dataset.confirm = form.dataset.deleteConfirm || '';
            form.dataset.confirmDanger = 'yes';
        } else {
            delete form.dataset.confirm;
            delete form.dataset.confirmDanger;
        }
    };

    all?.addEventListener('change', () => {
        rows().forEach((row) => { row.checked = all.checked; });
        update();
    });
    form.addEventListener('change', update);
    action?.addEventListener('change', syncConfirm);
    syncConfirm();
    update();
});

/* ------------------------------------------------------------------ */
/* Automatic refresh (x-auto-refresh): pausable, and off by default     */
/* with prefers-reduced-motion. The choice is kept for this tab.        */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-auto-refresh]').forEach((box) => {
    const seconds = Math.max(5, Number(box.dataset.autoRefresh) || 30);
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const key = `ss-auto-refresh:${window.location.pathname}`;
    const text = box.querySelector('[data-auto-refresh-text]');
    const toggle = box.querySelector('[data-auto-refresh-toggle]');
    let timer = null;
    let stored = null;

    try { stored = window.sessionStorage.getItem(key); } catch { /* storage unavailable */ }

    let paused = stored === null ? reduced : stored === 'paused';

    const render = () => {
        box.classList.toggle('is-paused', paused);
        text.textContent = paused ? (reduced && stored === null ? box.dataset.textReduced : box.dataset.textPaused) : box.dataset.textActive;
        toggle.textContent = paused ? box.dataset.labelResume : box.dataset.labelPause;
        toggle.setAttribute('aria-pressed', paused ? 'true' : 'false');
    };

    const schedule = () => {
        window.clearTimeout(timer);
        if (paused) return;
        timer = window.setTimeout(() => {
            // A hidden tab waits until it is visible again.
            if (document.hidden) {
                document.addEventListener('visibilitychange', () => window.location.reload(), { once: true });
            } else {
                window.location.reload();
            }
        }, seconds * 1000);
    };

    toggle.addEventListener('click', () => {
        paused = !paused;
        stored = paused ? 'paused' : 'running';
        try { window.sessionStorage.setItem(key, stored); } catch { /* storage unavailable */ }
        render();
        schedule();
    });

    box.hidden = false;
    render();
    schedule();
});

/* ------------------------------------------------------------------ */
/* Alert rule form: a live sentence that reads the rule back.          */
/* ------------------------------------------------------------------ */

document.querySelectorAll('form[data-rule-preview]').forEach((form) => {
    const out = form.querySelector('[data-preview-text]');
    let units = {};
    let operators = {};
    try {
        units = JSON.parse(form.dataset.units || '{}');
        operators = JSON.parse(form.dataset.operators || '{}');
    } catch { /* keep the server-rendered sentence */ }

    const value = (name) => form.querySelector(`[name="${name}"]`);
    const number = (raw) => {
        const parsed = Number(raw);
        if (raw === '' || Number.isNaN(parsed)) return '…';
        return new Intl.NumberFormat(numberLocale(), { maximumFractionDigits: 2 }).format(parsed);
    };

    const render = () => {
        const metric = value('metric');
        const threshold = number(value('threshold').value);
        const text = metric.value === form.dataset.offlineMetric
            ? form.dataset.templateOffline.replace(':threshold', threshold)
            : form.dataset.template
                .replace(':metric', metric.selectedOptions[0]?.textContent.trim() || metric.value)
                .replace(':operator', operators[value('operator').value] || value('operator').value)
                .replace(':threshold', threshold + (units[metric.value] || ''))
                .replace(':minutes', value('duration_minutes').value || '…');
        out.textContent = text;
    };

    ['metric', 'threshold', 'operator', 'duration_minutes'].forEach((name) => {
        value(name)?.addEventListener('input', render);
        value(name)?.addEventListener('change', render);
    });
});

/* ------------------------------------------------------------------ */
/* Public status page: the customer's accent colour, checked for        */
/* contrast on the server (data-accent-*); CSSOM, so no inline style.   */
/* ------------------------------------------------------------------ */

if (document.body.dataset.accent) {
    const { accent, onAccent, accentLight, accentDark } = document.body.dataset;
    const style = document.body.style;
    style.setProperty('--sp-brand', accent);
    if (onAccent) style.setProperty('--sp-on-brand', onAccent);
    if (accentLight) style.setProperty('--sp-accent-light', accentLight);
    if (accentDark) style.setProperty('--sp-accent-dark', accentDark);
}

/* ------------------------------------------------------------------ */
/* Status page form: add and remove component rows.                     */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-component-rows]').forEach((rows) => {
    const form = rows.closest('form');

    rows.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-component-remove]');
        if (remove) remove.closest('[data-component-row]')?.remove();
    });

    // "Empty rows are ignored": leave rows without monitor and name out of the request.
    form?.addEventListener('submit', () => {
        rows.querySelectorAll('[data-component-row]').forEach((row) => {
            const filled = [...row.querySelectorAll('select, input')].some((el) => el.value.trim() !== '');
            row.querySelectorAll('[name]').forEach((el) => { el.disabled = !filled; });
        });
    }, true);

    form?.querySelector('[data-component-add]')?.addEventListener('click', () => {
        const source = rows.querySelector('[data-component-row]');
        if (!source) return;

        const row = source.cloneNode(true);
        const n = Date.now();
        row.querySelectorAll('[name]').forEach((el) => {
            el.name = el.name.replace(/components\[\d+\]/, `components[${n}]`);
            el.value = '';
            el.removeAttribute('aria-invalid');
            el.removeAttribute('aria-describedby');
        });
        row.querySelectorAll('[id]').forEach((el) => { el.id = el.id.replace(/component-\d+-/, `component-${n}-`); });
        row.querySelectorAll('label[for]').forEach((el) => { el.htmlFor = el.htmlFor.replace(/component-\d+-/, `component-${n}-`); });
        row.querySelectorAll('.ss-error').forEach((el) => el.remove());
        rows.appendChild(row);
        row.querySelector('select, input')?.focus();
    });
});

/* Show the custom date range only for the "custom" period (reports). */
document.querySelectorAll('select[data-custom-toggle]').forEach((select) => {
    const target = document.getElementById(select.dataset.customToggle);
    select.addEventListener('change', () => { if (target) target.hidden = select.value !== 'custom'; });
});

/* Print button (reports.print). */
document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

/* Search results (/search): mark the search term in names and subtitles. */
document.querySelectorAll('[data-highlight]').forEach((root) => {
    const needle = root.dataset.highlight.trim().toLocaleLowerCase();
    if (needle.length < 2) return;

    root.querySelectorAll('.ov-main strong, .ov-main .ov-sub').forEach((el) => {
        [...el.childNodes].forEach((node) => {
            if (node.nodeType !== Node.TEXT_NODE) return;
            const text = node.textContent;
            const lower = text.toLocaleLowerCase();
            let index = lower.indexOf(needle);
            if (index === -1) return;

            const fragment = document.createDocumentFragment();
            let start = 0;
            while (index !== -1) {
                fragment.append(text.slice(start, index));
                const mark = document.createElement('mark');
                mark.className = 'ss-mark';
                mark.textContent = text.slice(index, index + needle.length);
                fragment.append(mark);
                start = index + needle.length;
                index = lower.indexOf(needle, start);
            }
            fragment.append(text.slice(start));
            node.replaceWith(fragment);
        });
    });
});

/* Tables that scroll sideways can be scrolled with the keyboard too. */
function markScrollableTables() {
    document.querySelectorAll('.ss-table-wrap').forEach((wrap) => {
        const scrolls = wrap.scrollWidth > wrap.clientWidth + 1;
        if (scrolls && !wrap.hasAttribute('tabindex')) {
            wrap.setAttribute('tabindex', '0');
            wrap.setAttribute('role', 'region');
            const heading = wrap.closest('section')?.querySelector('h2, h3');
            wrap.setAttribute('aria-label', heading?.textContent.trim() || document.title);
            wrap.dataset.scrollMarked = '';
        } else if (!scrolls && wrap.hasAttribute('data-scroll-marked')) {
            ['tabindex', 'role', 'aria-label', 'data-scroll-marked'].forEach((name) => wrap.removeAttribute(name));
        }
    });
}

markScrollableTables();
window.addEventListener('resize', markScrollableTables, { passive: true });

/* ------------------------------------------------------------------ */
/* Secrets: show/hide and copy (x-secret-token).                        */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-secret-toggle]').forEach((button) => {
    const input = document.getElementById(button.dataset.secretToggle);

    button.addEventListener('click', () => {
        input.type = input.type === 'password' ? 'text' : 'password';
        button.textContent = input.type === 'password' ? t('show') : t('hide');
    });
});

/* Folded menu parts (NEXUS): the toggle remembers open/closed in this browser. */
document.querySelectorAll('[data-nav-toggle]').forEach((button) => {
    const key = button.dataset.navToggle;
    const children = document.getElementById(button.getAttribute('aria-controls'));
    const sync = () => button.setAttribute('aria-expanded', String(getComputedStyle(children).display !== 'none'));

    sync();
    button.addEventListener('click', () => {
        const open = getComputedStyle(children).display === 'none';
        document.documentElement.setAttribute(`data-nav-${key}`, open ? 'open' : 'closed');
        try { localStorage.setItem(`ss-nav-${key}`, open ? 'open' : 'closed'); } catch { /* not remembered */ }
        sync();
    });
});

document.querySelectorAll('[data-copy-from]').forEach((button) => {
    const input = document.getElementById(button.dataset.copyFrom);

    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(input.value ?? input.textContent);
            button.textContent = t('copied');
            button.classList.add('is-done');
            setTimeout(() => {
                button.textContent = t('copy');
                button.classList.remove('is-done');
            }, 1800);
        } catch {
            // No clipboard access: show and select the value instead.
            if ('type' in input) input.type = 'text';
            input.select?.();
        }
    });
});

/* Quick search (Ctrl/⌘ K): see palette.js (commands, search, preview). */

/* ------------------------------------------------------------------ */
/* In-page section navigation: mark the section in view.               */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-section-nav]').forEach((nav) => {
    const links = [...nav.querySelectorAll('a[href^="#"]')];
    const targets = links.map((link) => document.getElementById(link.hash.slice(1))).filter(Boolean);
    let ticking = false;

    if (targets.length === 0) return;

    // A pill behind the active link that glides from section to section.
    const pill = document.createElement('span');
    pill.className = 'ss-section-pill';
    pill.setAttribute('aria-hidden', 'true');
    nav.prepend(pill);
    nav.classList.add('has-pill');

    const place = (link) => {
        pill.classList.toggle('is-visible', Boolean(link));
        if (!link) return;
        pill.style.width = `${link.offsetWidth}px`;
        pill.style.height = `${link.offsetHeight}px`;
        pill.style.transform = `translate(${link.offsetLeft}px, ${link.offsetTop}px)`;
    };

    // The current section is the last one whose top has passed the bar;
    // at the bottom of the page it is the last section.
    const mark = () => {
        ticking = false;
        const line = nav.getBoundingClientRect().bottom + 24;
        const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 2;
        let current = atBottom ? targets[targets.length - 1] : null;

        if (!current) {
            targets.forEach((target) => {
                if (target.getBoundingClientRect().top <= line) current = target;
            });
        }

        place(links.find((link) => current !== null && link.hash === `#${current.id}`));

        links.forEach((link) => {
            const active = current !== null && link.hash === `#${current.id}`;

            if (active) {
                link.setAttribute('aria-current', 'true');
                // Keep the active link in view inside the bar only (never scroll the page).
                nav.scrollTo({ left: Math.max(0, link.offsetLeft - (nav.clientWidth - link.offsetWidth) / 2) });
            } else {
                link.removeAttribute('aria-current');
            }
        });
    };

    window.addEventListener('scroll', () => {
        if (!ticking) {
            ticking = true;
            requestAnimationFrame(mark);
        }
    }, { passive: true });
    window.addEventListener('hashchange', () => setTimeout(mark, 50));
    window.addEventListener('resize', () => requestAnimationFrame(mark), { passive: true });
    mark();
    requestAnimationFrame(() => nav.classList.add('is-ready'));
});

/* ------------------------------------------------------------------ */
/* Live status of an enrolment token ("Add server").                   */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-enroll-status]').forEach((box) => {
    const text = box.querySelector('[data-text]');
    const link = box.querySelector('[data-link]');
    const dot = box.querySelector('[data-dot]');
    const labels = JSON.parse(box.dataset.labels || '{}');

    const poll = async () => {
        try {
            const response = await fetch(box.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) throw new Error(String(response.status));

            const data = await response.json();
            text.textContent = labels[data.state] || data.state;
            dot.className = `ss-dot ${data.state === 'connected' ? 'ss-dot-ok' : (['expired', 'revoked'].includes(data.state) ? 'ss-dot-bad' : 'ss-dot-warn ss-live-dot')}`;

            if (data.server_url) {
                link.href = data.server_url;
                link.textContent = (labels.go_named || ':name').replace(':name', data.server_name);
                link.hidden = false;
            }

            if (['connected', 'expired', 'revoked'].includes(data.state)) return;
        } catch {
            text.textContent = labels.error || '';
        }

        setTimeout(poll, 3000);
    };

    poll();
});

/* ------------------------------------------------------------------ */
/* Sidebar groups: collapse and expand, remembered in this browser.    */
/* The group of the current page always stays open.                    */
/* ------------------------------------------------------------------ */

(() => {
    const KEY = 'ss-nav-closed';
    let closed = [];

    try {
        closed = JSON.parse(localStorage.getItem(KEY) || '[]') || [];
    } catch (e) {
        closed = [];
    }

    document.querySelectorAll('details[data-nav-group]').forEach((group) => {
        if (closed.includes(group.dataset.navGroup) && !group.querySelector('[aria-current="page"]')) {
            group.removeAttribute('open');
        }

        group.addEventListener('toggle', () => {
            const list = [...document.querySelectorAll('details[data-nav-group]:not([open])')].map((d) => d.dataset.navGroup);

            try {
                localStorage.setItem(KEY, JSON.stringify(list));
            } catch (e) {
                /* Storage unavailable: the state is simply not remembered. */
            }
        });
    });
})();

/* Favourites: toggle in place (the form still works without JavaScript).
   Delegated in the capture phase (before the confirm/loading handler), so
   rows that live lists replace keep working. */
document.addEventListener('submit', async (event) => {
    const form = event.target.closest?.('[data-favorite-form]');

    if (!form) return;

    event.preventDefault();
    const button = form.querySelector('button');
    if (button.disabled) return;
    button.disabled = true;

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            body: new FormData(form),
        });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        button.setAttribute('aria-pressed', data.favorite ? 'true' : 'false');
        button.setAttribute('aria-label', data.label);
        button.title = data.label;
        button.querySelector('svg')?.setAttribute('fill', data.favorite ? 'currentColor' : 'none');
    } catch {
        form.submit();
    } finally {
        button.disabled = false;
    }
}, true);


/* ------------------------------------------------------------------ */
/* Sheets (popover panels): reopen after a validation error, and the  */
/* maintenance end time only when "custom" is chosen.                  */
/* ------------------------------------------------------------------ */

document.querySelectorAll('.ss-sheet[popover][data-open]').forEach((sheet) => sheet.showPopover?.());

document.querySelectorAll('[data-maintenance-form]').forEach((form) => {
    const duration = form.querySelector('[data-maintenance-duration]');
    const custom = form.querySelector('[data-maintenance-custom]');
    const sync = () => { custom.hidden = duration.value !== 'custom'; };

    sync();
    duration.addEventListener('change', sync);
});

/* ------------------------------------------------------------------ */
/* Filters on phones: search stays visible, the rest behind a button.  */
/* ------------------------------------------------------------------ */

document.querySelectorAll('form.ss-filters').forEach((form, index) => {
    const fields = [...form.children].filter((child) => !(child instanceof HTMLInputElement && child.type === 'hidden'));
    const main = fields[0];

    if (!main || fields.length < 2) return;

    // Active: a select away from its first option, or a ticked box (search is visible anyway).
    const active = [...form.querySelectorAll('select, input[type="checkbox"]')]
        .filter((field) => !main.contains(field))
        .filter((field) => (field.type === 'checkbox' ? field.checked : field.selectedIndex > 0)).length;

    const id = form.id || `filters-${index + 1}`;
    form.id = id;
    main.dataset.filterMain = '';

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'ss-btn ss-btn-secondary ss-filters-toggle';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', id);
    toggle.innerHTML = '<svg class="ico"><use href="#i-sliders"/></svg>';
    toggle.append(` ${form.dataset.filtersLabel || 'Filters'}`);

    if (active) {
        const count = document.createElement('span');
        count.className = 'ss-filters-count';
        count.textContent = String(active);
        toggle.append(count);
    }

    toggle.addEventListener('click', () => {
        const open = form.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', String(open));
    });

    main.after(toggle);
    form.classList.add('is-enhanced');
});

/* ------------------------------------------------------------------ */
/* Panels further down the page ease in as they scroll into view.      */
/* Only what starts below the fold; without JavaScript or with reduced */
/* motion everything is simply there.                                  */
/* ------------------------------------------------------------------ */

if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    const reveal = new IntersectionObserver((entries) => {
        entries.filter((entry) => entry.isIntersecting).forEach((entry, index) => {
            const element = entry.target;
            reveal.unobserve(element);
            element.style.setProperty('--ss-reveal-delay', `${Math.min(index, 4) * 60}ms`);
            element.classList.add('is-in');
            // Hand the element back to its own transitions (hover lifts and so on).
            setTimeout(() => {
                element.removeAttribute('data-reveal');
                element.classList.remove('is-in');
                element.style.removeProperty('--ss-reveal-delay');
            }, 900);
        });
    }, { rootMargin: '0px 0px -8% 0px' });

    const fold = window.innerHeight;

    document.querySelectorAll('.ss-content :is(.ss-panel, .ss-stat, .ss-table-wrap, .ss-section > *)').forEach((element) => {
        if (element.closest('[data-reveal]') || element.getBoundingClientRect().top < fold) return;
        element.setAttribute('data-reveal', '');
        reveal.observe(element);
    });
}

/* ------------------------------------------------------------------ */
/* Copy a fixed text (data-copy-text), e.g. a webhook URL in a menu.   */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-copy-text]').forEach((button) => {
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(button.dataset.copyText);
            const label = button.lastChild;
            const before = label.textContent;
            label.textContent = t('copied');
            setTimeout(() => { label.textContent = before; }, 1600);
        } catch {
            window.prompt('', button.dataset.copyText);
        }
    });
});

/* ------------------------------------------------------------------ */
/* Connect GitHub: the application name follows the repository name    */
/* until edited; the branch only matters for "every push".             */
/* ------------------------------------------------------------------ */

document.querySelectorAll('#github-connect form').forEach((form) => {
    const repository = form.querySelector('#repository');
    const application = form.querySelector('[data-github-application]');
    const branch = form.querySelector('[data-github-branch]');
    const modes = [...form.querySelectorAll('[data-github-mode]')];
    let touched = application.value !== '';

    application.addEventListener('input', () => { touched = application.value !== ''; });
    repository.addEventListener('input', () => {
        if (!touched) application.value = (repository.value.split('/')[1] || '').replace(/[^A-Za-z0-9._-]/g, '');
    });

    const sync = () => { branch.hidden = !modes.some((mode) => mode.checked && mode.value === 'push'); };
    modes.forEach((mode) => mode.addEventListener('change', sync));
    sync();
});

/* ------------------------------------------------------------------ */
/* Postmortem: growing text fields, action item rows, print.           */
/* ------------------------------------------------------------------ */

document.querySelectorAll('textarea[data-autosize]').forEach((field) => {
    const fit = () => {
        // A field in a closed sheet has no height yet; it is fitted when the sheet opens.
        if (!field.offsetParent) return;
        field.style.height = 'auto';
        field.style.height = `${field.scrollHeight + 2}px`;
    };
    field.addEventListener('input', fit);
    field.closest('[popover]')?.addEventListener('toggle', fit);
    fit();
});

/* Filter selects that apply as soon as they change (the button stays for keyboards without JS). */
document.querySelectorAll('select[data-autosubmit]').forEach((select) => {
    select.addEventListener('change', () => select.form?.requestSubmit());
});

document.querySelectorAll('[data-action-items]').forEach((editor) => {
    const template = editor.querySelector('[data-action-template]');
    const add = editor.querySelector('[data-action-add]');
    let next = editor.querySelectorAll('.ss-doc-action-row:not(.ss-doc-action-headrow)').length;

    add.addEventListener('click', () => {
        const row = template.content.firstElementChild.cloneNode(true);
        row.querySelectorAll('[name]').forEach((input) => { input.name = input.name.replace('__INDEX__', String(next)); });
        next += 1;
        add.before(row);
        row.querySelector('input[type="text"], input:not([type])')?.focus();
    });

    editor.addEventListener('click', (event) => {
        const remove = event.target.closest('[data-action-remove]');
        if (remove) remove.closest('.ss-doc-action-row').remove();
    });
});

document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

/* ------------------------------------------------------------------ */
/* Demo: read only. Everything stays visible; changing is explained.   */
/* The server refuses every change anyway (App\Http\Middleware\DemoMode). */
/* ------------------------------------------------------------------ */

if (document.documentElement.hasAttribute('data-demo')) {
    const message = document.querySelector('[data-demo-message]')?.dataset.demoMessage || 'Not available in the demo.';
    const allowedPaths = ['/logout', '/locale'];
    const allowed = (url) => allowedPaths.includes(new URL(url, location.href).pathname);
    const writes = (form) => (form.getAttribute('method') || 'get').toLowerCase() !== 'get' && !allowed(form.action);

    const toast = () => {
        document.querySelector('.ss-toast.is-demo')?.remove();
        const note = document.createElement('div');
        note.className = 'ss-toast is-demo';
        note.setAttribute('role', 'status');
        note.textContent = message;
        document.body.append(note);
        setTimeout(() => note.classList.add('is-leaving'), 2600);
        setTimeout(() => note.remove(), 3000);
    };

    const mark = (root = document) => {
        [...(root.matches?.('form') ? [root] : []), ...root.querySelectorAll('form')].forEach((form) => {
            if (!writes(form)) return;
            form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]').forEach((button) => {
                button.setAttribute('aria-disabled', 'true');
                button.classList.add('is-demo-disabled');
                button.title = message;
            });
        });
    };

    // Before anything else (confirm dialogs, loading states): no change leaves the page.
    document.addEventListener('submit', (event) => {
        if (!writes(event.target)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        toast();
    }, true);

    // Script requests that would change something answer like the server would.
    const fetchOriginal = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        const method = (init.method || (input instanceof Request ? input.method : 'GET')).toUpperCase();
        const url = input instanceof Request ? input.url : String(input);

        if (['GET', 'HEAD', 'OPTIONS'].includes(method) || allowed(url)) return fetchOriginal(input, init);

        toast();

        return Promise.resolve(new Response(JSON.stringify({ message }), { status: 403, headers: { 'Content-Type': 'application/json' } }));
    };

    // The fixed bar's real height (it wraps on phones) moves the page down by exactly that much.
    const bar = document.querySelector('.ss-demo-bar');
    if (bar && 'ResizeObserver' in window) {
        new ResizeObserver(() => document.documentElement.style.setProperty('--ss-demo-h', `${bar.offsetHeight}px`)).observe(bar);
    }

    mark();
    // Forms that appear later (live lists, sheets) are marked too.
    new MutationObserver((changes) => changes.forEach((c) => c.addedNodes.forEach((n) => n.nodeType === 1 && mark(n)))).observe(document.body, { childList: true, subtree: true });
}

/* ------------------------------------------------------------------ */
/* Sign-in and registration: show password, strength, sending state.  */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    const input = document.getElementById(button.getAttribute('aria-controls'));
    button.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-pressed', String(show));
        button.setAttribute('aria-label', show ? button.dataset.labelHide : button.dataset.labelShow);
        button.querySelector('[data-eye="show"]').hidden = show;
        button.querySelector('[data-eye="hide"]').hidden = !show;
        input.focus();
    });
});

document.querySelectorAll('input[data-strength]').forEach((input) => {
    const meter = input.closest('.ss-auth-field')?.querySelector('[data-strength-meter]');
    if (!meter) return;
    const labels = JSON.parse(meter.dataset.labels || '{}');
    const text = meter.querySelector('.ss-strength-text');

    // A rough guide only (the server checks the real rules): length and variety.
    const score = (v) => {
        if (!v) return 0;
        let s = v.length >= 12 ? 2 : v.length >= 8 ? 1 : 0;
        s += [/[a-z]/, /[A-Z]/, /\d/, /[^A-Za-z0-9]/].filter((r) => r.test(v)).length >= 3 ? 1 : 0;
        s += v.length >= 16 ? 1 : 0;

        return Math.max(1, Math.min(4, s));
    };

    input.addEventListener('input', () => {
        const s = score(input.value);
        meter.dataset.score = String(s);
        text.textContent = s ? labels[['', 'weak', 'fair', 'good', 'strong'][s]] : '';
    });
});

document.querySelectorAll('form[data-auth-form]').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('.ss-auth-submit');
        if (button) {
            button.classList.add('is-loading');
            button.setAttribute('aria-busy', 'true');
        }
    });
});

/* Heartbeats: a template fills in the add form; a name the user typed is kept. */
document.querySelectorAll('[data-hb-template]').forEach((button) => {
    button.addEventListener('click', () => {
        const form = button.closest('form');
        const t = JSON.parse(button.dataset.hbTemplate);
        const set = (name, value) => {
            const field = form.elements[name];
            if (field && value !== undefined) field.value = String(value);
        };
        const name = form.elements.name;
        if (!name.value.trim() || name.dataset.fromTemplate === name.value) {
            name.value = t.name;
            name.dataset.fromTemplate = t.name;
        }
        form.querySelector(`input[name="schedule_type"][value="${t.schedule_type}"]`).checked = true;
        set('period_minutes', t.period_minutes);
        set('cron_expression', t.cron_expression);
        set('grace_minutes', t.grace_minutes);
        set('severity', t.severity);
        form.querySelectorAll('[data-hb-template]').forEach((b) => b.setAttribute('aria-pressed', String(b === button)));
    });
});
