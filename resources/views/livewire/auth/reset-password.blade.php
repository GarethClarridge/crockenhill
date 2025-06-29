<form wire:submit.prevent="resetPassword" class="w-full max-w-md mx-auto mt-8">
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
        <input type="hidden" wire:model="token">
        <div class="mb-4">
            <label for="email" class="block mb-1 font-medium">Email</label>
            <input type="email" id="email" wire:model="email" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required autofocus>
        </div>
        <div class="mb-4">
            <label for="password" class="block mb-1 font-medium">Password</label>
            <input type="password" id="password" wire:model="password" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
        </div>
        <div class="mb-4">
            <label for="password_confirmation" class="block mb-1 font-medium">Confirm Password</label>
            <input type="password" id="password_confirmation" wire:model="password_confirmation" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" required>
        </div>
        <div class="form-actions my-3">
            <button type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded no-underline bg-green-500 hover:bg-green-600 py-3 px-4 leading-tight text-xl w-full">Reset Password</button>
        </div>
        <div class="mt-4 text-center">
            <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Back to login</a>
        </div>
    </div>
</form> 