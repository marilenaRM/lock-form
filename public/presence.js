/*
 * Presence indicator of lock-form/form-concurrency: shows "someone else is editing this record" above every form
 * rendered with the indicator on. No dependency; load it once per page, with defer.
 *
 * A form is followed from the first time it shows up, with the token it had then. Later renders of the same form
 * (a LiveComponent re-renders it on every change, from a request that is not the page's) do not change it.
 */
(() => {
    'use strict';

    if (window.formConcurrencyPresence) {
        return;
    }

    const randomId = () => (crypto.randomUUID ? crypto.randomUUID() : String(Math.random()).slice(2) + Date.now()).replace(/[^A-Za-z0-9_-]/g, '');
    const tab = randomId();

    /** token → {url, interval, timer, message, forms: Set<HTMLFormElement>} */
    const records = new Map();
    /** form → token it was first seen with */
    const followed = new WeakMap();
    const banners = new WeakMap();

    const body = (token, leave) => {
        const data = new URLSearchParams({token, tab});
        if (leave) {
            data.set('leave', '1');
        }

        return data;
    };

    const render = (record) => {
        for (const form of record.forms) {
            if (!form.isConnected) {
                record.forms.delete(form);
                continue;
            }

            let banner = banners.get(form);
            if (!record.message) {
                banner?.remove();
                continue;
            }

            if (!banner) {
                banner = document.createElement('p');
                banner.className = 'concurrency-presence';
                banner.setAttribute('role', 'status');
                banners.set(form, banner);
            }
            banner.textContent = record.message;
            if (banner.nextElementSibling !== form) {
                form.before(banner);
            }
        }
    };

    const beat = async (token) => {
        const record = records.get(token);
        if (!record) {
            return;
        }

        try {
            const response = await fetch(record.url, {method: 'POST', body: body(token, false), credentials: 'same-origin', headers: {Accept: 'application/json'}});
            if (!response.ok) {
                return;
            }
            const data = await response.json();
            record.message = typeof data.message === 'string' ? data.message : null;
        } catch {
            // The indicator is a hint: a failed heartbeat leaves the last state shown and tries again later.
            return;
        }
        render(record);
    };

    const scan = () => {
        for (const form of document.querySelectorAll('form[data-concurrency-presence]')) {
            if (followed.has(form)) {
                continue;
            }

            const token = form.dataset.concurrencyPresence;
            followed.set(form, token);

            let record = records.get(token);
            if (!record) {
                const interval = Math.max(2, Number(form.dataset.concurrencyPresenceInterval) || 15) * 1000;
                record = {url: form.dataset.concurrencyPresenceUrl, interval, timer: null, message: null, forms: new Set()};
                records.set(token, record);
                record.timer = setInterval(() => beat(token), interval);
                beat(token);
            }
            record.forms.add(form);
            render(record);
        }

        // A re-render may have dropped a banner (LiveComponent morphing) or replaced a form: put them back.
        for (const record of records.values()) {
            render(record);
        }
    };

    let scheduled = false;
    new MutationObserver(() => {
        if (!scheduled) {
            scheduled = true;
            requestAnimationFrame(() => {
                scheduled = false;
                scan();
            });
        }
    }).observe(document.documentElement, {childList: true, subtree: true});

    // Leaving: tell the server now rather than waiting for the TTL. Coming back (bfcache, tab shown again): beat at once.
    window.addEventListener('pagehide', () => {
        for (const [token, record] of records) {
            navigator.sendBeacon(record.url, body(token, true));
        }
    });
    window.addEventListener('pageshow', (event) => event.persisted && records.forEach((record, token) => beat(token)));
    document.addEventListener('visibilitychange', () => !document.hidden && records.forEach((record, token) => beat(token)));

    window.formConcurrencyPresence = {scan};
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }
})();
