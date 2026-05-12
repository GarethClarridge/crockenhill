@props([
    'title',
    'description' => null,
    'saveAction' => null,
])

@php
    $hotkeyAttributes = new \Illuminate\View\ComponentAttributeBag;
    $gridAttributes = new \Illuminate\View\ComponentAttributeBag([
        'class' => 'grid grid-cols-1 gap-6 lg:grid-cols-3',
        'wire:loading.class.delay.500ms' => 'opacity-50',
    ]);

    if ($saveAction) {
        $hotkeyAttributes = $hotkeyAttributes->merge([
            'x-data' => sprintf(
                "{ saveAction: %s, save() { this.\$wire.call(this.saveAction); } }",
                \Illuminate\Support\Js::from($saveAction)
            ),
            '@keydown.window.ctrl.s.prevent' => 'save()',
            '@keydown.window.cmd.s.prevent' => 'save()',
        ]);

        $gridAttributes = $gridAttributes->merge(['wire:target' => $saveAction]);
    }
@endphp

<div {{ $hotkeyAttributes }}>
    <x-admin.page
        :title="$title"
        :description="$description"
        {{ $attributes }}
    >
        <x-slot:actions>
            <div x-slot-actions class="contents">
                @isset($actions){{ $actions }}@endisset
            </div>
        </x-slot:actions>

        <div {{ $gridAttributes }}>
            <div class="space-y-6 lg:col-span-2">
                {{ $slot }}
            </div>

            @isset($sidebar)
                <div class="space-y-6">
                    {{ $sidebar }}
                </div>
            @endisset
        </div>
    </x-admin.page>
</div>
