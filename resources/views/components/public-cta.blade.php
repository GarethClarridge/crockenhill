@props([
    'link',
    'label',
    'ariaLabel' => null,
    'maxWidth' => 'max-w-[34rem]',
])

<div {{ $attributes->class(['mx-auto w-full px-6 text-center', $maxWidth]) }}>
    <div class="w-full rounded-xl bg-gradient-teal p-[1.5px] shadow-[0_10px_24px_rgba(20,85,87,0.18)]">
        <x-button
            :link="$link"
            variant="featureOutline"
            size="lg"
            class="w-full rounded-[11px] px-7 py-3 tracking-tight"
            aria-label="{{ $ariaLabel ?? $label }}"
        >
            {{ $label }}
        </x-button>
    </div>
</div>
