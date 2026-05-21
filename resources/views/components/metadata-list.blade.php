@props([
    'items' => [],
    'stacked' => false,
    'columns' => 1,
])

@php
    $cols = (int) $columns;
    $stackItems = $stacked || $cols > 1;

    $wrapperClass = match (true) {
        $cols >= 4 => 'grid grid-cols-1 gap-3 sm:grid-cols-4 text-sm',
        $cols === 3 => 'grid grid-cols-1 gap-3 sm:grid-cols-3 text-sm',
        $cols === 2 => 'grid grid-cols-1 gap-3 sm:grid-cols-2 text-sm',
        default => 'space-y-2 text-sm',
    };
@endphp

<dl {{ $attributes->merge(['class' => $wrapperClass]) }}>
    @foreach($items as $item)
        <div @class([
            'flex items-start justify-between gap-3' => ! $stackItems,
            'space-y-0.5' => $stackItems,
        ])>
            <dt @class([
                'text-gray-500' => ! $stackItems,
                'text-xs font-medium uppercase tracking-wide text-gray-500' => $stackItems,
            ])>{{ $item['label'] }}</dt>
            <dd @class([
                'font-medium text-gray-900 text-right' => ! $stackItems,
                'font-medium text-gray-900' => $stackItems,
            ])>{{ $item['value'] }}</dd>
        </div>
    @endforeach
</dl>
