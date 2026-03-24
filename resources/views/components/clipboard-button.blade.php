@props(['url'])

<button
    type="button"
    x-data="{ copied: false }"
    x-show="navigator.clipboard"
    @click="
        if (!navigator.clipboard) return;
        navigator.clipboard.writeText('{{ $url }}').then(() => {
            copied = true;
            setTimeout(() => copied = false, 2000);
        });
    "
    {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-cbc-teal-dark hover:text-cbc-teal bg-white border border-gray-200 hover:border-cbc-teal-light/30 rounded-md shadow-sm transition-all focus:outline-none focus-visible:ring-2 focus-visible:ring-cbc-teal focus-visible:ring-offset-1']) }}
    aria-label="Copy page link"
    title="Copy link to clipboard"
    x-cloak
>
    <x-heroicon-o-link x-show="!copied" class="w-4 h-4" aria-hidden="true" />
    <x-heroicon-o-check x-show="copied" class="w-4 h-4 text-cbc-teal" aria-hidden="true" x-cloak />
    <span x-text="copied ? 'Copied!' : 'Copy link'" aria-live="polite"></span>
</button>
