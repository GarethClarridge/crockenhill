@props(['label' => null, 'hint' => null])

<label class="flex items-center gap-3 cursor-pointer">
    <button type="button"
        role="switch"
        x-data="{ checked: $wire.entangle('{{ $attributes->wire('model')->value() }}') }"
        :aria-checked="checked"
        @click="checked = !checked"
        :class="checked ? 'bg-green-600' : 'bg-gray-200'"
        class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
        <span :class="checked ? 'translate-x-5' : 'translate-x-0'"
            class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
    </button>
    @if($label)
        <div>
            <span class="text-sm font-medium text-gray-700">{{ $label }}</span>
            @if($hint)
                <p class="text-sm text-gray-500">{{ $hint }}</p>
            @endif
        </div>
    @endif
</label>
