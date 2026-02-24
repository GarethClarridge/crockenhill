<div class="w-full max-w-md mx-auto mt-8">
    <div class="bg-white p-8 rounded-md shadow border border-gray-200 text-center">
        <h2 class="text-2xl font-bold mb-6">Verify Your Email Address</h2>
        <p class="mb-4">Before proceeding, please check your email for a verification link. If you did not receive the email, click below to request another.</p>
        @if ($resent)
            <div class="mb-4 px-3 py-3 border rounded bg-green-200 border-green-300 text-green-800">A fresh verification link has been sent to your email address.</div>
        @endif
        <form wire:submit.prevent="resend" class="form-actions my-3">
            <button type="submit" class="inline-block text-center select-none border font-normal whitespace-nowrap rounded no-underline bg-green-500 hover:bg-green-600 py-3 px-4 leading-tight text-xl">Resend Verification Email</button>
        </form>
        <div class="mt-4">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-red-600 hover:underline bg-transparent border-none cursor-pointer p-0 m-0">Logout</button>
            </form>
        </div>
    </div>
</div> 