<div class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200 text-center">
        <h2 class="text-2xl font-bold mb-6">Verify Your Email Address</h2>
        <p class="mb-4">Before proceeding, please check your email for a verification link. If you did not receive the email, click below to request another.</p>
        @if ($resent)
            <x-alert type="success" class="mb-4 text-left">A fresh verification link has been sent to your email address.</x-alert>
        @endif

        @if ($error)
            <x-alert type="error" class="mb-4 text-left">{{ $error }}</x-alert>
        @endif
        <form wire:submit.prevent="resend" class="form-actions my-3">
            <x-form-button variant="primary" class="w-full text-xl py-3">
                Resend Verification Email
            </x-form-button>
        </form>
        <div class="mt-4">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-cbc-crimson hover:text-cbc-crimson/80 transition-colors font-medium">Log out</button>
            </form>
        </div>
    </div>
</div> 