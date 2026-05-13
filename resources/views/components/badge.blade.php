@props([
    'variant' => 'default',
    'size' => 'sm',
    'pulse' => false,
])

@php
$variants = [
    'default' => 'bg-gray-100 text-gray-700 ring-gray-300/50',
    'success' => 'bg-green-50 text-green-700 ring-green-600/20',
    'warning' => 'bg-yellow-50 text-yellow-700 ring-yellow-600/20',
    'danger'  => 'bg-red-50 text-red-700 ring-red-600/20',
    'info'    => 'bg-blue-50 text-blue-700 ring-blue-600/20',
    'teal'    => 'bg-cbc-teal/10 text-cbc-teal ring-cbc-teal/20',
    'sky'     => 'bg-sky-50 text-sky-700 ring-sky-600/20',
    'amber'   => 'bg-amber-50 text-amber-700 ring-amber-600/20',
];

$dotVariants = [
    'default' => 'bg-gray-400',
    'success' => 'bg-green-500',
    'warning' => 'bg-yellow-500',
    'danger'  => 'bg-red-500',
    'info'    => 'bg-blue-500',
    'teal'    => 'bg-cbc-teal',
    'sky'     => 'bg-sky-500',
    'amber'   => 'bg-amber-500',
];

$sizes = [
    'xs' => 'px-1.5 py-0.5 text-xs',
    'sm' => 'px-2 py-0.5 text-xs',
    'md' => 'px-2.5 py-1 text-sm',
];

$classes = 'inline-flex items-center gap-1.5 rounded-full font-medium ring-1 ring-inset '
    . ($variants[$variant] ?? $variants['default']) . ' '
    . ($sizes[$size] ?? $sizes['sm']);

$dotClass = $dotVariants[$variant] ?? $dotVariants['default'];
@endphp

<span {{ $attributes->merge(['class' => $classes]) }}>
    @if($pulse)
        <span class="relative flex h-1.5 w-1.5 shrink-0">
            <span class="animate-ping absolute inline-flex h-full w-full rounded-full {{ $dotClass }} opacity-75"></span>
            <span class="relative inline-flex rounded-full h-1.5 w-1.5 {{ $dotClass }}"></span>
        </span>
    @endif
    {{ $slot }}
</span>
