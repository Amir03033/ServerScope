/*
 * ServerScope app shell. Plain JavaScript, no dependencies. Everything is
 * progressive enhancement: without JavaScript every page and form works.
 * Texts come from the page (resources/js/i18n.js).
 */
import { numberLocale, t } from './i18n';

const isMac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent);

/* ------------------------------------------------------------------ */
/* Theme: system (default), light or dark. Stored only in this browser. */
/* ------------------------------------------------------------------ */

const themeButton = document.querySelector('[data-theme-toggle]');

function storedTheme() {
    try {
        return localStorage.getItem('ss-theme') || 'system';
    } catch {
        return 'system';
    }
}

function applyTheme(theme) {
    if (theme === 'light' || theme === 'dark') {
        document.documentElement.setAttribute('data-theme', theme);
    } else {
        document.documentElement.removeAttribute('data-theme');
    }

    if (themeButton) {
        const labels = JSON.parse(themeButton.dataset.labels || '{}');
        themeButton.setAttribute('aria-label', `${themeButton.dataset.labelPrefix}: ${labels[theme] || theme}`);
        themeButton.title = themeButton.getAttribute('aria-label');
        themeButton.querySelectorAll('[data-theme-icon]').forEach((icon) => {
            icon.toggleAttribute('hidden', icon.dataset.themeIcon !== theme);
        });
    }
}

if (themeButton) {
    themeButton.hidden = false;
    applyTheme(storedTheme());

    themeButton.addEventListener('click', () => {
        const order = ['system', 'light', 'dark'];
        const next = order[(order.indexOf(storedTheme()) + 1) % order.length];

        try {
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

/* Menus (<details>): close on Escape and on a click outside. */
document.querySelectorAll('[data-menu]').forEach((menu) => {
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && menu.open) {
            menu.open = false;
            menu.querySelector('summary')?.focus();
        }
    });
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
/* Secrets: show/hide and copy (x-secret-token).                        */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-secret-toggle]').forEach((button) => {
    const input = document.getElementById(button.dataset.secretToggle);

    button.addEventListener('click', () => {
        input.type = input.type === 'password' ? 'text' : 'password';
        button.textContent = input.type === 'password' ? t('show') : t('hide');
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

/* ------------------------------------------------------------------ */
/* Quick search (Ctrl/⌘ K) on the JSON answer of /search.               */
/* ------------------------------------------------------------------ */

const searchDialog = document.querySelector('[data-search-dialog]');

if (searchDialog && typeof searchDialog.showModal === 'function') {
    const input = searchDialog.querySelector('input[name="q"]');
    const list = searchDialog.querySelector('[data-search-results]');
    const status = searchDialog.querySelector('[data-search-status]');
    const types = (() => {
        try {
            return JSON.parse(document.getElementById('ss-i18n')?.textContent || '{}')['search.types'] || {};
        } catch {
            return {};
        }
    })();
    let active = -1;
    let timer = null;
    let controller = null;

    const items = () => [...list.querySelectorAll('[role="option"]')];

    function select(index) {
        const options = items();
        active = options.length ? (index + options.length) % options.length : -1;
        options.forEach((option, i) => option.setAttribute('aria-selected', i === active ? 'true' : 'false'));
        input.setAttribute('aria-activedescendant', active >= 0 ? options[active].id : '');
        options[active]?.scrollIntoView({ block: 'nearest' });
    }

    function showStatus(text) {
        status.textContent = text;
        status.hidden = !text;
    }

    function highlight(text, q) {
        const span = document.createElement('span');
        const index = text.toLowerCase().indexOf(q.toLowerCase());

        if (index < 0 || !q) {
            span.textContent = text;
            return span;
        }

        const mark = document.createElement('mark');
        mark.textContent = text.slice(index, index + q.length);
        span.append(text.slice(0, index), mark, text.slice(index + q.length));

        return span;
    }

    function render(q, results) {
        list.replaceChildren();
        active = -1;

        results.forEach((result, i) => {
            const li = document.createElement('li');
            li.id = `quick-search-${i}`;
            li.setAttribute('role', 'option');
            li.setAttribute('aria-selected', 'false');
            li.className = 'ss-result flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2.5 text-fg';
            li.dataset.url = result.url;

            const type = document.createElement('span');
            type.className = 'ss-badge shrink-0';
            type.textContent = types[result.type] || result.type;

            const text = document.createElement('span');
            text.className = 'min-w-0 flex-1';
            const title = highlight(result.title, q);
            title.className = 'block truncate text-sm font-medium';
            const sub = document.createElement('span');
            sub.className = 'block truncate text-xs text-muted';
            sub.textContent = result.subtitle;
            text.append(title, sub);

            const state = document.createElement('span');
            state.className = 'shrink-0 text-xs text-muted';
            state.textContent = result.status_label;

            li.append(type, text, state);
            li.addEventListener('mousemove', () => select(i));
            li.addEventListener('click', () => {
                window.location.href = result.url;
            });
            list.append(li);
        });

        input.setAttribute('aria-expanded', results.length ? 'true' : 'false');
        showStatus(results.length ? '' : t('search.no_results', { q }));

        if (results.length) select(0);
    }

    async function run() {
        const q = input.value.trim();

        if (q.length < 2) {
            list.replaceChildren();
            input.setAttribute('aria-expanded', 'false');
            showStatus('');
            return;
        }

        controller?.abort();
        controller = new AbortController();
        showStatus(list.children.length ? '' : t('search.loading'));

        try {
            const url = new URL(searchDialog.dataset.searchUrl, window.location.origin);
            url.searchParams.set('q', q);
            const response = await fetch(url, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal,
            });

            if (!response.ok) throw new Error(String(response.status));

            const data = await response.json();
            if (input.value.trim() === q) render(q, data.results || []);
        } catch (error) {
            if (error.name !== 'AbortError') showStatus('');
        }
    }

    function open() {
        if (searchDialog.open) return;
        searchDialog.showModal();
        input.select();
    }

    document.querySelectorAll('[data-search-open]').forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
            event.preventDefault();
            open();
        });
    });

    document.addEventListener('keydown', (event) => {
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            searchDialog.open ? searchDialog.close() : open();
        }
    });

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(run, 150);
    });

    input.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            // A search field would first clear itself; close straight away.
            event.preventDefault();
            searchDialog.close();
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            select(active + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            select(active - 1);
        } else if (event.key === 'Enter' && active >= 0) {
            // Open the chosen result; without a choice the form goes to /search.
            event.preventDefault();
            window.location.href = items()[active].dataset.url;
        }
    });

    // Close when clicking the backdrop.
    searchDialog.addEventListener('click', (event) => {
        if (event.target === searchDialog) searchDialog.close();
    });
}

/* ------------------------------------------------------------------ */
/* In-page section navigation: mark the section in view.               */
/* ------------------------------------------------------------------ */

document.querySelectorAll('[data-section-nav]').forEach((nav) => {
    const links = [...nav.querySelectorAll('a[href^="#"]')];
    const targets = links.map((link) => document.getElementById(link.hash.slice(1))).filter(Boolean);
    let ticking = false;

    if (targets.length === 0) return;

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
    mark();
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
