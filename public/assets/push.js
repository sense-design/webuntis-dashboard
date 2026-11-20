// The "notify me of timetable changes" toggle in the dashboard footer. The
// one piece of JavaScript in the app: Web Push has no other way in. Every
// label it shows comes from data-* attributes rendered by Twig, so all text
// stays in translations/*.yaml.
(() => {
    const box = document.querySelector('[data-push]');
    if (!box) {
        return;
    }

    const button = box.querySelector('.push__toggle');
    const status = box.querySelector('.push__status');
    const text = box.dataset;
    box.hidden = false;

    const say = (message) => {
        status.textContent = message || '';
        status.hidden = !message;
    };

    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) {
        button.hidden = true;
        say(text.unsupported);
        return;
    }

    const post = (url, body) => fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    const keyBytes = (base64url) => {
        const base64 = (base64url + '='.repeat((4 - base64url.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        return Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
    };

    let registration = null;
    let subscription = null;

    const render = () => {
        button.textContent = subscription ? text.disable : text.enable;
        button.setAttribute('aria-pressed', subscription ? 'true' : 'false');
    };

    const enable = async () => {
        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            say(text.denied);
            return;
        }

        subscription = await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: keyBytes(text.key),
        });

        const response = await post(text.subscribeUrl, { subscription: subscription.toJSON(), test: true });
        if (!response.ok) {
            await subscription.unsubscribe();
            subscription = null;
            say(text.failed);
            return;
        }
        say(text.enabled);
    };

    const disable = async () => {
        await post(text.unsubscribeUrl, { endpoint: subscription.endpoint });
        await subscription.unsubscribe();
        subscription = null;
        say('');
    };

    button.addEventListener('click', async () => {
        button.disabled = true;
        try {
            await (subscription ? disable() : enable());
        } catch (error) {
            say(text.failed);
        } finally {
            button.disabled = false;
            render();
        }
    });

    (async () => {
        try {
            registration = await navigator.serviceWorker.register(text.worker);
            subscription = await registration.pushManager.getSubscription();

            // A subscription made with an older server key (var/ wiped, say)
            // can never be delivered to again - drop it so the button offers
            // to subscribe afresh.
            const key = subscription?.options?.applicationServerKey;
            if (key && btoa(String.fromCharCode(...new Uint8Array(key))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '') !== text.key) {
                await subscription.unsubscribe();
                subscription = null;
            }

            // Re-send on every load, so the server side heals itself if its
            // list was lost; no test push here, that is only for the click.
            if (subscription && Notification.permission === 'granted') {
                post(text.subscribeUrl, { subscription: subscription.toJSON() });
                say(text.enabled);
            }
        } catch (error) {
            say(text.failed);
        }
        render();
    })();
})();
