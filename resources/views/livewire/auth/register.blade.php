<form wire:submit.prevent="register" class="max-w-md mx-auto mt-10 p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-bold mb-6">Register</h2>
    @if ($error)
        <div class="mb-4 text-red-600">{{ $error }}</div>
    @endif
    <div class="mb-4">
        <label for="name" class="block mb-1">Name</label>
        <input type="text" id="name" wire:model="name" class="w-full border rounded px-3 py-2" required autofocus>
    </div>
    <div class="mb-4">
        <label for="email" class="block mb-1">Email</label>
        <input type="email" id="email" wire:model="email" class="w-full border rounded px-3 py-2" required>
    </div>
    <div class="mb-4">
        <label for="password" class="block mb-1">Password</label>
        <input type="password" id="password" wire:model="password" class="w-full border rounded px-3 py-2" required>
    </div>
    <div class="mb-4">
        <label for="password_confirmation" class="block mb-1">Confirm Password</label>
        <input type="password" id="password_confirmation" wire:model="password_confirmation" class="w-full border rounded px-3 py-2" required>
    </div>
    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Register</button>
    <div class="mt-4 text-center">
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Already have an account? Login</a>
    </div>
</form> 