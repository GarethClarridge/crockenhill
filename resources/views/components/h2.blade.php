<h2 id="{{ Illuminate\Support\Str::slug($slot) }}" class="mx-auto my-10 max-w-5xl px-6 text-center font-display text-3xl sm:text-4xl">
    <span class="mx-auto mb-4 block h-0.75 w-24 rounded-full bg-[linear-gradient(90deg,var(--color-cbc-teal-light)_0%,var(--color-cbc-teal)_55%,var(--color-cbc-teal-dark)_100%)]"></span>
    <span class="inline-block bg-gradient-teal bg-clip-text text-transparent">
        {{ $slot }}
    </span>
</h2>
