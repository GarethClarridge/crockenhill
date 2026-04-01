<div {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-4']) }}>
    {{ $slot }}

    @isset($actions)
        <div class="ml-auto">
            {{ $actions }}
        </div>
    @endisset
</div>
