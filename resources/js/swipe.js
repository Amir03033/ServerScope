// Swipe to acknowledge (phones): swipe an open incident in the list to the
// left and an "Acknowledge" button appears behind it; tap it to acknowledge.
// It posts the same form as the incident page (status = acknowledged), so
// nothing new happens on the server. A swipe never acts on its own.

import { t } from './i18n';

function boot() {
    const rows = document.querySelectorAll('tr[data-swipe-ack]');

    if (!rows.length || !window.matchMedia('(pointer: coarse)').matches) return;

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    const OPEN = 112;
    let openRow = null;

    function close(row) {
        row.style.transform = '';
        row.classList.remove('is-swiped');
        if (openRow === row) openRow = null;
    }

    rows.forEach((row) => {
        row.classList.add('ss-swipe-row');
        const action = document.createElement('button');
        action.type = 'button';
        action.className = 'ss-swipe-action';
        action.textContent = t('swipe.acknowledge');
        action.tabIndex = -1;
        action.addEventListener('click', () => {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = row.dataset.swipeAck;
            for (const [name, value] of [['_token', csrf], ['status', 'acknowledged']]) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.append(input);
            }
            document.body.append(form);
            action.disabled = true;
            form.submit();
        });
        row.append(action);

        let start = null;
        let dx = 0;
        let horizontal = null;

        row.addEventListener('touchstart', (e) => {
            if (openRow && openRow !== row) close(openRow);
            start = { x: e.touches[0].clientX, y: e.touches[0].clientY, base: row.classList.contains('is-swiped') ? -OPEN : 0 };
            dx = 0;
            horizontal = null;
            row.style.transition = 'none';
        }, { passive: true });

        row.addEventListener('touchmove', (e) => {
            if (!start) return;

            const mx = e.touches[0].clientX - start.x;
            const my = e.touches[0].clientY - start.y;
            horizontal ??= Math.abs(mx) > 8 ? Math.abs(mx) > Math.abs(my) : (Math.abs(my) > 8 ? false : null);

            if (!horizontal) return;

            e.preventDefault(); // a sideways swipe does not scroll the page
            dx = Math.min(0, Math.max(-OPEN * 1.3, start.base + mx));
            row.style.transform = `translateX(${dx}px)`;
        }, { passive: false });

        row.addEventListener('touchend', () => {
            row.style.transition = '';

            if (horizontal && dx < -OPEN / 2) {
                row.style.transform = `translateX(${-OPEN}px)`;
                row.classList.add('is-swiped');
                openRow = row;
                action.tabIndex = 0;
            } else if (horizontal || start?.base) {
                close(row);
                action.tabIndex = -1;
            }

            start = null;
        });
    });

    document.addEventListener('touchstart', (e) => {
        if (openRow && !openRow.contains(e.target)) close(openRow);
    }, { passive: true });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
