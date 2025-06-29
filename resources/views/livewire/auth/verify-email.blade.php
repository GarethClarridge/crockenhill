<div class="max-w-md mx-auto mt-10 p-6 bg-white rounded shadow text-center">
    <h2 class="text-2xl font-bold mb-6">Verify Your Email Address</h2>
    <p class="mb-4">Before proceeding, please check your email for a verification link.</p>
    @if ($resent)
        <div class="mb-4 text-green-600">A fresh verification link has been sent to your email address.</div>
    @endif
    <form wire:submit.prevent="resend">
        <button type="submit" class="bg-blue-600 text-white py-2 px-4 rounded">Resend Verification Email</button>
    </form>
    <div class="mt-4">
        <a href="{{ route('login') }}" class="text-blue-600 hover:underline">Back to login</a>
    </div>
</div> 