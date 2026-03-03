<form wire:submit.prevent="login" class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200">
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
            />

            <x-input
                label="Password"
                type="password"
                id="password"
                wire:model="password"
                icon="lock-closed"
                required
            />

            <div class="flex items-center">
                <input type="checkbox" id="remember" wire:model="remember" class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500">
                <label for="remember" class="ml-2 block text-sm text-gray-700">Remember me</label>
            </div>
        </div>

        <div class="mt-6">
            <x-form-button variant="primary" class="w-full text-xl py-3">
                Login
            </x-form-button>
        </div>

        <div class="mt-6 space-y-2 text-center text-sm">
            <div>
                <a href="{{ route('password.request') }}" class="text-blue-600 hover:text-blue-800 transition-colors">Forgot your password?</a>
            </div>
            <div>
                <a href="{{ route('register') }}" class="text-blue-600 hover:text-blue-800 transition-colors">Don't have an account? Register</a>
            </div>
        </div>
    </div>
</form>
