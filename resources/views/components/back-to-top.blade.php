<div
    x-data="{ show: false }"
    x-on:scroll.window="show = window.scrollY > 400"
    class="fixed bottom-8 right-8 z-40"
    x-show="show"
    x-transition:enter="transition ease-out duration-300"
    x-transition:enter-start="opacity-0 translate-y-10"
    x-transition:enter-end="opacity-100 translate-y-0"
    x-transition:leave="transition ease-in duration-300"
    x-transition:leave-start="opacity-100 translate-y-0"
    x-transition:leave-end="opacity-0 translate-y-10"
    x-cloak
>
    <button
        type="button"
        @click="window.scrollTo({ top: 0, behavior: 'smooth' })"
        aria-label="Back to top"
        title="Back to top"
        class="flex h-12 w-12 items-center justify-center rounded-full bg-cbc-teal text-white shadow-lg transition-all hover:bg-cbc-teal-dark hover:shadow-xl focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-2 active:scale-95"
    >
        <x-heroicon-o-chevron-up class="h-6 w-6" aria-hidden="true" />
    </button>
</div>
