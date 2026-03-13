/**
 * API.Bible FUMS (Fair Use Management System) v3 tracker.
 *
 * Reports scripture passage views to API.Bible using the stored fums_token.
 * The token is read from data-fums-token on the .scripture-passage element so
 * no inline script is needed in the Blade template.
 *
 * Fires on:
 *   - livewire:navigated — covers both the initial page load and all subsequent
 *     wire:navigate transitions in Livewire 3 (replaces DOMContentLoaded).
 *
 * Note: registering DOMContentLoaded alongside livewire:navigated would
 * double-count the initial load because Livewire 3 fires livewire:navigated
 * on first render too.
 */

function reportScriptureFums() {
    const el = document.querySelector('.scripture-passage[data-fums-token]');
    if (!el) {
        return;
    }

    const token = el.getAttribute('data-fums-token');
    if (!token) {
        return;
    }

    // Load the FUMS script if not already loaded
    if (!window.__fumsLoaded) {
        window.__fumsLoaded = true;

        // Required shim before the FUMS script loads
        window.fumsData = window.fumsData || {};
        window.fums = window.fums || function (...args) {
            (window.fumsData.q = window.fumsData.q || []).push(args);
        };

        const script = document.createElement('script');
        script.src = 'https://pkg.api.bible/fumsV3.min.js';
        script.async = true;
        script.onload = () => window.fums('trackView', token);
        document.head.appendChild(script);
    } else if (typeof window.fums === 'function') {
        window.fums('trackView', token);
    }
}

document.addEventListener('livewire:navigated', reportScriptureFums);
