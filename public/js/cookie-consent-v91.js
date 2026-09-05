(() => {
    'use strict';

    const root = document.querySelector('[data-cookie-consent-root]');
    if (!root) return;

    let config = {};
    try {
        config = JSON.parse(root.dataset.config || '{}');
    } catch (error) {
        return;
    }

    const banner = root.querySelector('[data-cookie-banner]');
    const backdrop = root.querySelector('[data-cookie-dialog-backdrop]');
    const dialog = root.querySelector('[data-cookie-dialog]');
    const analytics = root.querySelector('[data-cookie-analytics]');
    const marketing = root.querySelector('[data-cookie-marketing]');
    let previousFocus = null;

    const encodeConsent = (data) =>
        btoa(JSON.stringify(data))
            .replace(/\+/g, '-')
            .replace(/\//g, '_')
            .replace(/=+$/g, '');

    const writeConsent = (analyticsAllowed, marketingAllowed) => {
        const data = {
            v: Number(config.version || 1),
            a: analyticsAllowed ? 1 : 0,
            m: marketingAllowed ? 1 : 0,
            t: Date.now(),
        };

        const maxAge = Math.max(30, Number(config.days || 180)) * 86400;

        const parts = [
            String(config.cookieName || 'portal_cookie_consent') + '=' + encodeConsent(data),
            'Path=' + String(config.path || '/'),
            'Max-Age=' + String(maxAge),
            'SameSite=Lax',
        ];

        if (config.secure) {
            parts.push('Secure');
        }

        document.cookie = parts.join('; ');
        window.location.reload();
    };

    const openDialog = () => {
        if (!backdrop || !dialog) return;

        previousFocus = document.activeElement;

        if (analytics) analytics.checked = Boolean(config.analytics);
        if (marketing) marketing.checked = Boolean(config.marketing);

        backdrop.hidden = false;
        document.documentElement.classList.add('portal-cookie-dialog-open');

        window.setTimeout(() => dialog.focus(), 0);
    };

    const closeDialog = () => {
        if (!backdrop) return;

        backdrop.hidden = true;
        document.documentElement.classList.remove('portal-cookie-dialog-open');

        if (previousFocus && typeof previousFocus.focus === 'function') {
            previousFocus.focus();
        }
    };

    document.querySelectorAll('[data-cookie-preferences-open]').forEach((button) => {
        button.addEventListener('click', openDialog);
    });

    root.querySelectorAll('[data-cookie-customize]').forEach((button) => {
        button.addEventListener('click', openDialog);
    });

    root.querySelectorAll('[data-cookie-close]').forEach((button) => {
        button.addEventListener('click', closeDialog);
    });

    root.querySelectorAll('[data-cookie-accept]').forEach((button) => {
        button.addEventListener('click', () => writeConsent(true, true));
    });

    root.querySelectorAll('[data-cookie-reject], [data-cookie-dialog-reject]').forEach((button) => {
        button.addEventListener('click', () => writeConsent(false, false));
    });

    root.querySelectorAll('[data-cookie-save]').forEach((button) => {
        button.addEventListener('click', () => {
            writeConsent(Boolean(analytics?.checked), Boolean(marketing?.checked));
        });
    });

    backdrop?.addEventListener('click', (event) => {
        if (event.target === backdrop) closeDialog();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && backdrop && !backdrop.hidden) {
            closeDialog();
        }
    });

    if (banner && !config.hasConsent) {
        banner.hidden = false;
    }
})();
