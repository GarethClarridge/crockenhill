/**
 * Google Analytics 4 integration.
 *
 * The measurement ID is injected as `window.__gaId` by layouts/main.blade.php and
 * only when `GOOGLE_ANALYTICS_ID` is configured, so this module is a clean no-op
 * locally and on any environment without GA configured. The data-* annotations it
 * reads still render in that case — they are simply inert.
 *
 * Responsibilities:
 *   - Consent (GA1): Google Consent Mode v2. `analytics_storage` defaults to
 *     'denied' until the visitor opts in through <x-cookie-consent>. The banner
 *     calls the `window.grantConsent()` / `window.denyConsent()` helpers exposed
 *     here.
 *   - Pageviews (GA2): the site browses via wire:navigate, so the document only
 *     loads once. We disable the config-time pageview (`send_page_view: false`)
 *     and instead fire one `page_view` per navigation on `livewire:navigated`,
 *     which also fires on the initial render — mirroring scripture-fums.js, and
 *     giving exactly one pageview per navigation without double-counting.
 *   - Events (GA3): a single delegated listener turns `data-analytics`
 *     annotations on links and media elements into gtag events, surviving
 *     wire:navigate DOM swaps without re-binding.
 *   - Dimensions (GA4): `data-ga-*` attributes on the page's [data-ga-context]
 *     marker are merged into `page_view` and `sermon_play` so GA4 reporting can
 *     group by preacher / series / service / content type. These become
 *     reportable once registered as event-scoped custom dimensions in the GA4
 *     admin (see docs/operations/SEO_SETUP_GUIDE.md).
 *
 * Consent note: with Consent Mode v2, events still fire while consent is denied —
 * Google sends cookieless, modelled pings. Granting consent switches transport to
 * cookie-based measurement. We therefore do not gate event dispatch on consent;
 * Consent Mode governs storage for us.
 */

// Shared with the <x-cookie-consent> Alpine banner. Keep both in sync.
const CONSENT_STORAGE_KEY = 'cbc_analytics_consent';

// Maps a `data-analytics` token (used in markup) to its GA4 event name.
const CLICK_EVENTS = {
    'sermon-download': 'sermon_download',
    transcript: 'transcript_download',
    'podcast-subscribe': 'podcast_subscribe',
};

function gaIsConfigured() {
    return typeof window.__gaId === 'string' && window.__gaId !== '';
}

function consentChoice() {
    try {
        return window.localStorage.getItem(CONSENT_STORAGE_KEY);
    } catch (e) {
        return null;
    }
}

function persistConsent(value) {
    try {
        window.localStorage.setItem(CONSENT_STORAGE_KEY, value);
    } catch (e) {
        // localStorage unavailable (private mode / disabled): the choice simply
        // isn't remembered and the banner reappears next visit. Acceptable.
    }
}

/**
 * Reads the page-level [data-ga-context] marker (rendered by
 * <x-analytics-context>) so page_view and sermon_play carry the same custom
 * dimensions. Returns {} when no marker is present (e.g. non-sermon pages).
 */
function contentDimensions() {
    const el = document.querySelector('[data-ga-context]');
    if (!el) {
        return {};
    }

    const params = {};
    const { gaPreacher, gaSeries, gaService, gaContentType, gaContentGroup } = el.dataset;

    if (gaPreacher) {
        params.preacher = gaPreacher;
    }
    if (gaSeries) {
        params.series = gaSeries;
    }
    if (gaService) {
        params.service = gaService;
    }
    if (gaContentType) {
        params.content_type = gaContentType;
    }
    if (gaContentGroup) {
        params.content_group = gaContentGroup;
    }

    return params;
}

/**
 * Extracts `data-ga-*` attributes from an annotated element into event params,
 * e.g. data-ga-sermon-slug="x" -> { sermon_slug: 'x' }.
 */
function paramsFromDataset(el) {
    const params = {};

    Object.entries(el.dataset).forEach(([key, value]) => {
        if (!value || !key.startsWith('ga') || key === 'gaContext') {
            return;
        }

        const name = key
            .slice(2)
            .replace(/([A-Z])/g, '_$1')
            .replace(/^_/, '')
            .toLowerCase();

        params[name] = value;
    });

    return params;
}

function sendPageView() {
    if (!window.gtag) {
        return;
    }

    window.gtag('event', 'page_view', {
        page_path: window.location.pathname + window.location.search,
        page_title: document.title,
        ...contentDimensions(),
    });
}

function dispatchEvent(eventName, el, extra = {}) {
    if (!window.gtag) {
        return;
    }

    window.gtag('event', eventName, {
        ...contentDimensions(),
        ...paramsFromDataset(el),
        ...extra,
    });
}

function loadGtagScript() {
    if (window.__gaScriptRequested) {
        return;
    }
    window.__gaScriptRequested = true;

    // Defer the heavy library download to protect LCP. The gtag() shim already
    // queues our consent/config commands on dataLayer, so they replay in order
    // once the library loads.
    const inject = () => {
        setTimeout(() => {
            const script = document.createElement('script');
            script.src =
                'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(window.__gaId);
            script.async = true;
            document.head.appendChild(script);
        }, 100);
    };

    if (document.readyState === 'complete') {
        inject();
    } else {
        window.addEventListener('load', inject, { once: true });
    }
}

function registerListeners() {
    if (window.__gaListenersBound) {
        return;
    }
    window.__gaListenersBound = true;

    // One page_view per navigation. Fires on the initial render too (Livewire
    // emits livewire:navigated on first load), so config's send_page_view:false
    // avoids a double count.
    document.addEventListener('livewire:navigated', sendPageView);

    // Delegated click events for annotated links.
    document.addEventListener('click', (event) => {
        const target = event.target.closest('[data-analytics]');
        if (!target) {
            return;
        }

        const eventName = CLICK_EVENTS[target.dataset.analytics];
        if (eventName) {
            dispatchEvent(eventName, target);
        }
    });

    // sermon_play on native <audio>/<video>. The `play` event does not bubble,
    // so we listen in the capture phase; a per-element flag keeps seeking/replay
    // from re-firing.
    document.addEventListener(
        'play',
        (event) => {
            const el = event.target;
            if (!el.matches || !el.matches('[data-analytics="sermon-media"]')) {
                return;
            }
            if (el.dataset.analyticsPlayed === 'true') {
                return;
            }
            el.dataset.analyticsPlayed = 'true';

            dispatchEvent('sermon_play', el, {
                media_type: el.tagName.toLowerCase() === 'video' ? 'video' : 'audio',
            });
        },
        true
    );
}

function grantConsent() {
    persistConsent('granted');
    if (window.gtag) {
        window.gtag('consent', 'update', { analytics_storage: 'granted' });
        // Capture the current page now that storage is permitted.
        sendPageView();
    }
}

function denyConsent() {
    persistConsent('denied');
    if (window.gtag) {
        window.gtag('consent', 'update', { analytics_storage: 'denied' });
    }
}

// Exposed for the consent banner and for the clipboard "share" hook. These are
// safe no-ops when GA isn't configured (window.gtag is undefined).
window.grantConsent = grantConsent;
window.denyConsent = denyConsent;
window.gaTrackEvent = (name, params = {}) => {
    if (window.gtag) {
        window.gtag('event', name, { ...contentDimensions(), ...params });
    }
};

function bootstrap() {
    if (!gaIsConfigured()) {
        return;
    }

    window.dataLayer = window.dataLayer || [];
    window.gtag =
        window.gtag ||
        function gtag() {
            window.dataLayer.push(arguments);
        };

    // Consent default must be registered BEFORE config (ordering matters).
    window.gtag('consent', 'default', {
        analytics_storage: 'denied',
        wait_for_update: 500,
    });

    // Honour a stored opt-in from a previous visit.
    if (consentChoice() === 'granted') {
        window.gtag('consent', 'update', { analytics_storage: 'granted' });
    }

    window.gtag('js', new Date());
    window.gtag('config', window.__gaId, { send_page_view: false });

    loadGtagScript();
    registerListeners();
}

bootstrap();
