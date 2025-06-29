<form wire:submit.prevent="resetPassword" class="max-w-md mx-auto mt-10 p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-bold mb-6">Reset Password</h2>
    @if ($status)
        <div class="mb-4 text-green-600">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="mb-4 text-red-600">{{ $error }}</div>
    @endif
    <input type="hidden" wire:model="token">
    <div class="mb-4">
        <label for="email" class="block mb-1">Email</label>
        <input type="email" id="email" wire:model="email" class="w-full border rounded px-3 py-2" required autofocus>
    </div>
    <div class="mb-4">
        <label for="password" class="block mb-1">Password</label>
        <input type="password" id="password" wire:model="password" class="w-full border rounded px-3 py-2" required>
    </div>
    <div class="mb-4">
        <label for="password_confirmation" class="block mb-1">Confirm Password</label>
        <input type="password" id="password_confirmation" wire:model="password_confirmation" class="w-full border rounded px-3 py-2" required>
    </div>
    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Reset Password</button>
    <div class="mt-4 text-center">
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Back to login</a>
    </div>
</form> 