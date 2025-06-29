<form method="POST" action="{{ route('logout') }}">
    @csrf
    <button type="submit" class="text-red-600 hover:underline bg-transparent border-none cursor-pointer p-0 m-0">
        {{ $slot ?? 'Logout' }}
    </button>
</form> 