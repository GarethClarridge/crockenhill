@props([
    'items' => [],
    'stacked' => false,
])

<dl {{ $attributes->merge(['class' => 'space-y-2 text-sm']) }}>
    @foreach($items as $item)
        <div @class([
            'flex items-start justify-between gap-3' => ! $stacked,
            'space-y-0.5' => $stacked,
        ])>
            <dt @class([
                'text-gray-500' => ! $stacked,
                'text-xs font-medium uppercase tracking-wide text-gray-500' => $stacked,
            ])>{{ $item['label'] }}</dt>
            <dd @class([
                'font-medium text-gray-900 text-right' => ! $stacked,
                'font-medium text-gray-900' => $stacked,
            ])>{{ $item['value'] }}</dd>
        </div>
    @endforeach
</dl>
