<form wire:submit.prevent="sendResetLink" class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200">
        @if ($status)
            <div class="mb-4 px-4 py-3 border rounded-md bg-green-50 border-green-200 text-green-800 flex items-start gap-3" role="status">
                <x-heroicon-o-check-circle class="h-5 w-5 text-green-500 shrink-0 mt-0.5" />
                <span>{{ $status }}</span>
            </div>
        @endif
        @if ($error)
            <div class="mb-4 px-4 py-3 border rounded-md bg-red-50 border-red-200 text-red-800 flex items-start gap-3" role="alert">
                <x-heroicon-o-exclamation-circle class="h-5 w-5 text-red-500 shrink-0 mt-0.5" />
                <span>{{ $error }}</span>
            </div>
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
            <a href="{{ route('login') }}" class="text-blue-600 hover:text-blue-800 transition-colors">Back to login</a>
        </div>
    </div>
</form>
