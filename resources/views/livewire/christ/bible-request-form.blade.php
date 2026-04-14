<div>
    @if ($submitted)
        <x-alert type="success" title="Request received!">
            Thank you — we'll be in touch to arrange getting a Bible to you.
        </x-alert>
    @else
        @if ($rateLimitError)
            <x-alert type="error" class="mb-4">{{ $rateLimitError }}</x-alert>
        @endif

        <form wire:submit="submit" class="space-y-4" novalidate>
            {{-- Honeypot: hidden from real users, but bots typically fill it --}}
            <div aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;overflow:hidden" tabindex="-1">
                <label for="website">Website</label>
                <input type="text" id="website" wire:model="website" tabindex="-1" autocomplete="off" />
            </div>

            <x-input
                label="Your name"
                wire:model="name"
                required
                maxlength="100"
                placeholder="e.g. Jane Smith"
            />

            <x-textarea
                label="Your address"
                wire:model="address"
                required
                rows="3"
                maxlength="300"
                hint="We need this so we can post or deliver your Bible."
            />

            <x-input
                label="Email address (optional)"
                wire:model="email"
                type="email"
                maxlength="255"
                hint="Only if you'd like us to confirm by email."
            />

            <x-input
                label="Phone number (optional)"
                wire:model="phone"
                type="tel"
                maxlength="30"
            />

            <x-textarea
                label="Anything else? (optional)"
                wire:model="message"
                rows="3"
                maxlength="1000"
            />

            <x-form-button variant="primary" size="lg" class="w-full">
                Request a free Bible
            </x-form-button>
        </form>
    @endif
</div>
