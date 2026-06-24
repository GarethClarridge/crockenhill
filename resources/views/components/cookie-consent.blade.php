{{--
    Cookie consent banner (GA1).

    Renders only when Google Analytics is configured — there is nothing to consent
    to otherwise. Google Consent Mode v2 defaults analytics_storage to 'denied' in
    resources/js/analytics.js; this banner persists the visitor's choice and calls
    the grantConsent()/denyConsent() helpers that module exposes.

    The localStorage key must match CONSENT_STORAGE_KEY in resources/js/analytics.js.
--}}
@if(config('services.google_analytics.measurement_id'))
<div
    x-data="{
        show: false,
        init() {
            try {
                this.show = ! window.localStorage.getItem('cbc_analytics_consent');
            } catch (e) {
                this.show = true;
            }
        },
        accept() {
            if (window.grantConsent) { window.grantConsent(); }
            this.show = false;
        },
        decline() {
            if (window.denyConsent) { window.denyConsent(); }
            this.show = false;
        },
    }"
    x-show="show"
    x-cloak
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-4"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-200"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-4"
    role="dialog"
    aria-modal="false"
    aria-labelledby="cookie-consent-heading"
    aria-describedby="cookie-consent-body"
    class="fixed inset-x-0 bottom-0 z-[120] px-4 pb-4 sm:px-6 sm:pb-6"
>
    <div class="mx-auto max-w-3xl rounded-xl border border-gray-200 bg-white p-5 shadow-lg sm:p-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:gap-6">
            <div class="flex items-start gap-3">
                <x-heroicon-o-shield-check class="mt-0.5 h-6 w-6 shrink-0 text-cbc-teal" aria-hidden="true" />
                <div class="space-y-1">
                    <h2 id="cookie-consent-heading" class="font-display text-lg text-gray-900">
                        Cookies &amp; analytics
                    </h2>
                    <p id="cookie-consent-body" class="text-sm text-gray-600">
                        We'd like to use analytics cookies to understand how people use the site so we
                        can improve it. These are optional and off until you accept. See our
                        <a href="/church/privacy-notice" wire:navigate class="text-cbc-teal-dark underline decoration-cbc-teal/40 underline-offset-2 hover:text-cbc-teal">privacy notice</a>.
                    </p>
                </div>
            </div>

            <div class="flex shrink-0 gap-3 sm:ml-auto">
                <button
                    type="button"
                    @click="decline()"
                    class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                >
                    Decline
                </button>
                <button
                    type="button"
                    @click="accept()"
                    class="inline-flex items-center justify-center rounded-md bg-[linear-gradient(120deg,var(--color-cbc-teal)_0%,var(--color-cbc-teal-dark)_55%,#0e3a3c_100%)] px-5 py-2 text-sm font-medium text-white transition-all hover:brightness-110 active:scale-95 focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2"
                >
                    Accept
                </button>
            </div>
        </div>
    </div>
</div>
@endif
