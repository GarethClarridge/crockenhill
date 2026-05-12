@php
    /** @var \App\Data\PendingStructureMergeMetadata $pendingMerge */
    /** @var string $pendingMergeSource */
@endphp

<x-card>
    <div class="not-prose space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-display text-xl text-gray-900">Pending Structure Merge</h2>
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">
                        Awaiting review
                    </span>
                </div>
                <p class="mt-1 text-sm text-gray-500">
                    Incoming <span class="font-medium text-gray-700 uppercase">{{ $pendingMergeSource }}</span> data
                    conflicts with existing livestream-detected items.
                    {{ count($pendingMerge->conflicts) }} {{ \Illuminate\Support\Str::plural('conflict', count($pendingMerge->conflicts)) }} require your review.
                </p>
            </div>

            <div class="flex flex-wrap gap-2">
                <x-form-button
                    type="button"
                    variant="primary"
                    size="sm"
                    wire:click="acceptIncomingMerge"
                    wire:confirm="Accept the incoming {{ strtoupper($pendingMergeSource) }} items? This will replace the current livestream-detected structure."
                >
                    Accept incoming
                </x-form-button>

                <x-form-button
                    type="button"
                    variant="outline"
                    size="sm"
                    wire:click="keepCurrentStructure"
                    wire:confirm="Keep the current structure and discard the incoming {{ strtoupper($pendingMergeSource) }} data?"
                >
                    Keep current
                </x-form-button>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div class="rounded-lg border border-gray-200 bg-white p-3">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Current confidence</p>
                <p class="mt-1 text-sm font-medium text-gray-900">{{ ucfirst($pendingMerge->confidence['current'] ?? 'unknown') }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-3">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Incoming confidence</p>
                <p class="mt-1 text-sm font-medium text-gray-900">{{ ucfirst($pendingMerge->confidence['incoming'] ?? 'unknown') }}</p>
            </div>
            <div class="rounded-lg border border-gray-200 bg-white p-3">
                <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Staged at</p>
                <p class="mt-1 text-sm font-medium text-gray-900">
                    @if($pendingMerge->createdAt)
                        {{ \Illuminate\Support\Carbon::parse($pendingMerge->createdAt)->format('j M Y H:i') }}
                    @else
                        Unknown
                    @endif
                </p>
            </div>
        </div>

        @if($pendingMerge->conflicts !== [])
            <div class="space-y-3">
                <p class="text-sm font-semibold text-gray-700">Conflicts</p>

                @foreach($pendingMerge->conflicts as $conflict)
                    @php
                        $conflictType = $conflict['type'] ?? 'general_conflict';
                        $currentItem = $conflict['current_item'] ?? null;
                        $incomingItem = $conflict['incoming_item'] ?? null;
                        $conflictLabel = match($conflictType) {
                            'title_conflict' => 'Title differs',
                            'position_conflict' => 'Position differs',
                            'type_conflict' => 'Section type differs',
                            'new_item_conflict' => 'New item (no match)',
                            default => 'General conflict',
                        };
                        $conflictClasses = match($conflictType) {
                            'type_conflict' => 'bg-rose-100 text-rose-800',
                            'new_item_conflict' => 'bg-blue-100 text-blue-800',
                            default => 'bg-amber-100 text-amber-800',
                        };
                    @endphp

                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium {{ $conflictClasses }}">
                                {{ $conflictLabel }}
                            </span>
                            @if(is_array($incomingItem) && isset($incomingItem['position']))
                                <span class="text-xs text-gray-500">Position {{ $incomingItem['position'] }}</span>
                            @endif
                        </div>

                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            @if(is_array($currentItem))
                                <div class="rounded-lg border border-gray-200 bg-white p-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500 mb-2">Current (livestream)</p>
                                    <p class="text-sm font-medium text-gray-900">{{ $currentItem['title'] ?? 'Untitled' }}</p>
                                    <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                        @if(isset($currentItem['type']))
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium {{ match($currentItem['type']) {
                                                'songs' => 'bg-green-100 text-green-800',
                                                'bibles' => 'bg-blue-100 text-blue-800',
                                                default => 'bg-gray-100 text-gray-800',
                                            } }}">{{ ucfirst($currentItem['type']) }}</span>
                                        @endif
                                        @if(isset($currentItem['section_type']))
                                            <span>{{ ucfirst(str_replace('_', ' ', $currentItem['section_type'])) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endif

                            @if(is_array($incomingItem))
                                <div class="rounded-lg border border-cbc-teal/20 bg-cbc-teal-light/5 p-3">
                                    <p class="text-xs font-medium uppercase tracking-wider text-gray-500 mb-2">
                                        Incoming ({{ strtoupper($pendingMergeSource) }})
                                    </p>
                                    <p class="text-sm font-medium text-gray-900">{{ $incomingItem['title'] ?? 'Untitled' }}</p>
                                    <div class="mt-1 flex flex-wrap gap-2 text-xs text-gray-500">
                                        @if(isset($incomingItem['type']))
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 font-medium {{ match($incomingItem['type']) {
                                                'songs' => 'bg-green-100 text-green-800',
                                                'bibles' => 'bg-blue-100 text-blue-800',
                                                default => 'bg-gray-100 text-gray-800',
                                            } }}">{{ ucfirst($incomingItem['type']) }}</span>
                                        @endif
                                        @if(isset($incomingItem['section_type']))
                                            <span>{{ ucfirst(str_replace('_', ' ', $incomingItem['section_type'])) }}</span>
                                        @endif
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($pendingMerge->proposedItems !== [])
            <details class="rounded-lg border border-gray-200 bg-white">
                <summary class="cursor-pointer px-4 py-3 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Full proposed item list ({{ count($pendingMerge->proposedItems) }} {{ \Illuminate\Support\Str::plural('item', count($pendingMerge->proposedItems)) }})
                </summary>
                <div class="border-t border-gray-200 px-4 py-3">
                    <ol class="divide-y divide-gray-100">
                        @foreach($pendingMerge->proposedItems as $proposed)
                            <li class="flex items-start gap-3 py-2">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-xs font-medium text-gray-600">
                                    {{ $proposed['position'] ?? $loop->iteration }}
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        @if(isset($proposed['type']))
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($proposed['type']) {
                                                'songs' => 'bg-green-100 text-green-800',
                                                'bibles' => 'bg-blue-100 text-blue-800',
                                                default => 'bg-gray-100 text-gray-800',
                                            } }}">{{ ucfirst($proposed['type']) }}</span>
                                        @endif
                                    </div>
                                    <p class="mt-0.5 text-sm text-gray-900">{{ $proposed['title'] ?? 'Untitled' }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </details>
        @endif
    </div>
</x-card>
