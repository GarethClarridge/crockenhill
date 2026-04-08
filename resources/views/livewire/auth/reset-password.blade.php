<form wire:submit.prevent="resetPassword" class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200">
        @if ($status)
            <x-alert type="success" class="mb-4">
                {{ $status }}
            </x-alert>
        @endif
        @if ($error)
            <x-alert type="error" class="mb-4">
                {{ $error }}
            </x-alert>
        @endif

        <input type="hidden" wire:model="token">

        <div class="space-y-4">
            <x-input
                label="Email"
                type="email"
                id="email"
                wire:model="email"
                icon="envelope"
                required
                autofocus
            />

            <x-input
                label="Password"
                type="password"
                id="password"
                wire:model="password"
                icon="lock-closed"
                required
            />

            <x-input
                label="Confirm Password"
                type="password"
                id="password_confirmation"
                wire:model="password_confirmation"
                icon="lock-closed"
                required
            />
        </div>

        <div class="mt-6">
            <x-form-button variant="primary" class="w-full text-xl py-3">
                Reset Password
            </x-form-button>
        </div>

        <div class="mt-4 text-center text-sm">
            <a href="{{ route('login') }}" wire:navigate class="text-cbc-teal-dark hover:text-cbc-teal transition-colors">Back to login</a>
        </div>
    </div>
</form>
