<x-card heading="Order of service">
    @if($items->isEmpty())
        <p class="text-sm text-gray-500">This service has no planned items.</p>
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
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($item->source->value) {
                                    'email' => 'bg-blue-100 text-blue-700',
                                    'openlp' => 'bg-green-100 text-green-700',
                                    'manual' => 'bg-purple-100 text-purple-700',
                                    default => 'bg-gray-100 text-gray-600',
                                } }}">
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
