<div>
    @if ($submitted)
        <x-alert type="success" title="Request received!">
            Thank you — we'll be in touch to arrange getting a Bible to you.
        </x-alert>
    @else
        <form wire:submit="submit" class="space-y-4" novalidate>
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

            <x-form-button variant="primary" size="lg" class="w-full" wire:click="submit">
                Request a free Bible
            </x-form-button>
        </form>
    @endif
</div>
