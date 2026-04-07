@props([
    'title',
    'description' => null,
])

<x-admin.page :title="$title" :description="$description" {{ $attributes }}
    x-data="{
        save() {
            {{-- Find the first primary save button or submit button and click it --}}
            const saveBtn = $el.querySelector('button[wire\\:click=save]')
                || $el.querySelector('button[type=submit]')
                || $el.querySelector('div[x-slot-actions] button:last-child');

            if (saveBtn) {
                saveBtn.click();
            }
        }
    }"
    @keydown.window.ctrl.s.prevent="save()"
    @keydown.window.cmd.s.prevent="save()"
>
    <x-slot:actions>
        <div x-slot-actions class="contents">
            @isset($actions){{ $actions }}@endisset
        </div>
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3" wire:loading.class.delay.500ms="opacity-50" wire:target="save">
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
