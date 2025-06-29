<form wire:submit.prevent="login" class="max-w-md mx-auto mt-10 p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-bold mb-6">Login</h2>
    @if ($error)
        <div class="mb-4 text-red-600">{{ $error }}</div>
    @endif
    <div class="mb-4">
        <label for="email" class="block mb-1">Email</label>
        <input type="email" id="email" wire:model="email" class="w-full border rounded px-3 py-2" required autofocus>
    </div>
    <div class="mb-4">
        <label for="password" class="block mb-1">Password</label>
        <input type="password" id="password" wire:model="password" class="w-full border rounded px-3 py-2" required>
    </div>
    <div class="mb-4 flex items-center">
        <input type="checkbox" id="remember" wire:model="remember" class="mr-2">
        <label for="remember">Remember me</label>
    </div>
    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Login</button>
    <div class="mt-4 text-center">
        <a href="{{ route('password.request') }}" class="text-blue-600 hover:underline">Forgot your password?</a>
    </div>
    <div class="mt-2 text-center">
        <a href="{{ route('register') }}" class="text-blue-600 hover:underline">Register</a>
    </div>
</form> 