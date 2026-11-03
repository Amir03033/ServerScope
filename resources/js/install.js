// The app on your phone: registers the service worker (installable app,
// offline page, push) and runs the "push notifications on this device"
// switch on the profile page. Push is an extra channel next to e-mail and
// in-app: the server sends it to the same people at the same moment.

function urlBase64ToUint8Array(base64) {
    const padding = '='.repeat((4 - (base64.length % 4)) % 4);
    const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'));

    return Uint8Array.from([...raw].map((c) => c.charCodeAt(0)));
}

function device() {
    const ua = navigator.userAgent;
    const os = /iPhone|iPad/.test(ua) ? 'iOS' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'macOS' : /Windows/.test(ua) ? 'Windows' : 'Linux';
    const browser = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';

    return `${browser} · ${os}`;
}

async function setupPush(registration) {
    const box = document.querySelector('[data-push]');

    if (!box) return;

    const key = box.dataset.publicKey;
    const texts = JSON.parse(box.dataset.texts || '{}');
    const text = box.querySelector('[data-push-text]');
    const button = box.querySelector('[data-push-toggle]');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

    if (!key) return; // not configured on the server; the page says so

    if (!registration || !('PushManager' in window) || !('Notification' in window)) {
        text.textContent = texts.unsupported;
        box.dataset.pushState = 'unsupported';

        return;
    }

    async function render() {
        const subscription = await registration.pushManager.getSubscription();
        const state = Notification.permission === 'denied' ? 'blocked' : subscription ? 'on' : 'off';
        box.dataset.pushState = state;
        text.textContent = texts[state];
        button.hidden = state === 'blocked';
        button.textContent = state === 'on' ? texts.turn_off : texts.turn_on;
        button.disabled = false;
    }

    async function send(method, body) {
        const response = await fetch(box.dataset.subscribeUrl, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });

        if (!response.ok) throw new Error(String(response.status));
    }

    button.addEventListener('click', async () => {
        button.disabled = true;
        button.textContent = texts.working;

        try {
            const existing = await registration.pushManager.getSubscription();

            if (existing) {
                await send('DELETE', { endpoint: existing.endpoint });
                await existing.unsubscribe();
            } else {
                // Asks for permission (a browser prompt) only now, after a click.
                const subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(key) });
                const json = subscription.toJSON();
                const encodings = PushManager.supportedContentEncodings || ['aes128gcm'];
                await send('POST', { endpoint: json.endpoint, keys: json.keys, contentEncoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm', device: device() });
            }
        } catch {
            text.textContent = texts.failed;
        }

        render();
    });

    render();
}

async function boot() {
    let registration = null;

    if ('serviceWorker' in navigator && window.isSecureContext) {
        try {
            registration = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
            await navigator.serviceWorker.ready;
        } catch {
            registration = null;
        }
    }

    setupPush(registration);
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
} else {
    boot();
}
