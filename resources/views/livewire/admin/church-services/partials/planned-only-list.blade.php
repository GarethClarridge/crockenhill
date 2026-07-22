<x-card :heading="($showHeading ?? true) ? 'Order of service' : null">
    @if($items->isEmpty())
        <div class="space-y-3">
            <p class="text-sm text-gray-500">This service has no planned items.</p>
            <div class="flex flex-wrap items-center gap-2">
                <x-button link="{{ route('admin.services.submit-email') }}" variant="outline" size="sm" inline>
                    Paste the email
                </x-button>
                <span class="text-gray-300" aria-hidden="true">·</span>
                <x-button link="{{ route('admin.services.upload') }}" variant="outline" size="sm" inline>
                    Upload the .osz
                </x-button>
                <span class="text-gray-300" aria-hidden="true">·</span>
                <x-form-button type="button" variant="outline" size="sm" wire:click="startEditingOrderOfService">
                    Add items by hand
                </x-form-button>
            </div>
        </div>
    @else
        <ol class="divide-y divide-gray-200">
            @foreach($items as $item)
                <li class="flex items-start gap-3 py-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-600">
                        {{ $item->position }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($item->type) {
                                'songs' => 'bg-green-100 text-green-800',
                                'bibles' => 'bg-blue-100 text-blue-800',
                                'presentations' => 'bg-slate-100 text-slate-800',
                                default => 'bg-gray-100 text-gray-800',
                            } }}">
                                {{ ucfirst($item->type) }}
                            </span>

                            @if($item->source)
                                {{-- Source is inert provenance metadata — neutral, so colour keeps its meaning --}}
                                <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                                    {{ strtoupper($item->source->value) }}
                                </span>
                            @endif
                        </div>

                        <p class="mt-1 text-sm font-medium text-gray-900">{{ $item->title }}</p>

                        @if($item->song)
                            <a href="{{ route('admin.services.songs.show', $item->song) }}"
                               class="text-xs text-green-700 hover:text-green-800 no-underline"
                               wire:navigate>
                                View linked song
                            </a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</x-card>
