<form wire:submit.prevent="sendResetLink" class="max-w-md mx-auto mt-10 p-6 bg-white rounded shadow">
    <h2 class="text-2xl font-bold mb-6">Forgot Password</h2>
    @if ($status)
        <div class="mb-4 text-green-600">{{ $status }}</div>
    @endif
    @if ($error)
        <div class="mb-4 text-red-600">{{ $error }}</div>
    @endif
    <div class="mb-4">
        <label for="email" class="block mb-1">Email</label>
        <input type="email" id="email" wire:model="email" class="w-full border rounded px-3 py-2" required autofocus>
    </div>
    <button type="submit" class="w-full bg-blue-600 text-white py-2 rounded">Send Password Reset Link</button>
    <div class="mt-4 text-center">
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Back to login</a>
    </div>
</form> 