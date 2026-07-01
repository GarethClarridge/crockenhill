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
            '@keydown.window.ctrl.s.prevent' => 'save()',
            '@keydown.window.cmd.s.prevent' => 'save()',
        ]);

        $gridAttributes = $gridAttributes->merge(['wire:target' => $saveAction]);
    }

    $xData = $saveAction
        ? sprintf('{ topVisible: true, saveAction: %s, save() { this.$wire.call(this.saveAction); } }', \Illuminate\Support\Js::from($saveAction))
        : '{ topVisible: true }';

    $hotkeyAttributes = $hotkeyAttributes->merge(['x-data' => $xData]);
@endphp

<div {{ $hotkeyAttributes }}>
    <x-admin.page
        :title="$title"
        :description="$description"
        {{ $attributes }}
    >
        <x-slot:actions>
            <div x-slot-actions
                x-intersect:enter="topVisible = true"
                x-intersect:leave="topVisible = false"
                class="flex items-center gap-2"
            >
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

        @isset($actions)
            <div
                x-show="!topVisible"
                x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-8"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-y-0"
                x-transition:leave-end="opacity-0 translate-y-8"
                class="fixed bottom-0 left-0 right-0 z-20 w-full bg-white/90 backdrop-blur-sm border-t border-gray-200 p-4 shadow-[0_-4px_10px_rgba(0,0,0,0.05)] sm:left-auto"
                x-cloak
            >
                <x-content-wrapper class="mx-auto max-w-7xl px-6 md:px-8">
                    <div class="flex items-center justify-between gap-4">
                        <div class="hidden sm:block">
                            <p class="text-sm font-medium text-gray-500">Unsaved changes on <span class="text-gray-900">{{ $title }}</span></p>
                        </div>
                        <div class="flex flex-1 sm:flex-none items-center justify-end gap-2">
                            {{ $actions }}
                        </div>
                    </div>
                </x-content-wrapper>
            </div>
        @endisset
    </x-admin.page>
</div>
