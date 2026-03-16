<div class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200 text-center">
        <h2 class="text-2xl font-bold mb-6">Verify Your Email Address</h2>
        <p class="mb-4">Before proceeding, please check your email for a verification link. If you did not receive the email, click below to request another.</p>
        @if ($resent)
            <div class="mb-4 px-3 py-3 border rounded bg-green-50 border-green-200 text-green-800">A fresh verification link has been sent to your email address.</div>
        @endif

        @if ($error)
            <div class="mb-4 px-4 py-3 border rounded-md bg-red-50 border-red-200 text-red-800 flex items-start gap-3 text-left" role="alert">
                <x-heroicon-o-exclamation-circle class="h-5 w-5 text-red-500 shrink-0 mt-0.5" />
                <span>{{ $error }}</span>
            </div>
        @endif
        <form wire:submit.prevent="resend" class="form-actions my-3">
            <x-form-button variant="primary" class="w-full text-xl py-3">
                Resend Verification Email
            </x-form-button>
        </form>
        <div class="mt-4">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-red-600 hover:underline bg-transparent border-none cursor-pointer p-0 m-0">Logout</button>
            </form>
        </div>
    </div>
</div> 