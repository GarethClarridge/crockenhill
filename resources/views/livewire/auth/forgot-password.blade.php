<form wire:submit.prevent="sendResetLink" class="w-full max-w-md mx-auto mt-8">
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

        <div class="space-y-4">
            <x-input
                label="Email"
                type="email"
                id="email"
                wire:model="email"
                icon="envelope"
                required
                autofocus
                hint="Enter your email address and we will send you a link to reset your password."
            />
        </div>

        <div class="mt-6">
            <x-form-button variant="primary" class="w-full text-xl py-3">
                Send Password Reset Link
            </x-form-button>
        </div>

        <div class="mt-4 text-center text-sm">
            <a href="{{ route('login') }}" wire:navigate class="text-cbc-teal-dark hover:text-cbc-teal transition-colors">Back to login</a>
        </div>
    </div>
</form>
