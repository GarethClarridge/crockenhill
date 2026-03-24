<form wire:submit.prevent="login" class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200">
        @if ($error)
            <x-alert type="error" class="mb-4">{{ $error }}</x-alert>
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
                <input type="checkbox" id="remember" wire:model="remember" class="h-4 w-4 rounded border-gray-300 text-cbc-teal focus:ring-cbc-teal">
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
                <a href="{{ route('password.request') }}" wire:navigate class="text-blue-600 hover:text-blue-800 transition-colors">Forgot your password?</a>
            </div>
            <div>
                <a href="{{ route('register') }}" wire:navigate class="text-blue-600 hover:text-blue-800 transition-colors">Don't have an account? Register</a>
            </div>
        </div>
    </div>
</form>
