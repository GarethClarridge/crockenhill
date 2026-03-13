/**
 * API.Bible FUMS (Fair Use Management System) v3 tracker.
 *
 * Reports scripture passage views to API.Bible using the stored fums_token.
 * The token is read from data-fums-token on the .scripture-passage element so
 * no inline script is needed in the Blade template.
 *
 * Fires on:
 *   - Initial page load (DOMContentLoaded)
 *   - Livewire wire:navigate page transitions (livewire:navigated)
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

document.addEventListener('DOMContentLoaded', reportScriptureFums);
document.addEventListener('livewire:navigated', reportScriptureFums);
