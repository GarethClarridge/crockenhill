<form wire:submit.prevent="sendResetLink" class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200">
        @if ($status)
            <div class="mb-4 px-3 py-3 border rounded bg-green-200 border-green-300 text-green-800">
                {{ $status }}
            </div>
        @endif
        @if ($error)
            <div class="mb-4 px-3 py-3 border rounded bg-red-200 border-red-300 text-red-800">
                {{ $error }}
            </div>
        @endif
        <div class="mb-4">
            <label for="email" class="block mb-1 font-medium">Email</label>
            <input type="email" id="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required autofocus>
        </div>
        <div class="form-actions my-3">
            <button type="submit" class="inline-block text-center select-none border font-normal whitespace-nowrap rounded no-underline bg-green-500 hover:bg-green-600 py-3 px-4 leading-tight text-xl w-full">Send Password Reset Link</button>
        </div>
        <div class="mt-4 text-center">
            <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Back to login</a>
        </div>
    </div>
</form> 