@props([
    /** list<array{label: string, state: 'done'|'active'|'blocked'|'todo'}> */
    'steps' => [],
])

<ol {{ $attributes->merge(['class' => 'flex flex-wrap items-center gap-y-1']) }} aria-label="Pipeline progress">
    @foreach($steps as $step)
        @php
            $state = $step['state'] ?? 'todo';
            $dotClasses = match ($state) {
                'done' => 'bg-cbc-teal border-cbc-teal',
                'active' => 'bg-sky-400 border-sky-400 motion-safe:animate-pulse',
                'blocked' => 'bg-amber-400 border-amber-400',
                default => 'bg-white border-gray-300',
            };
            $labelClasses = match ($state) {
                'done' => 'text-cbc-teal-dark',
                'active' => 'text-sky-800',
                'blocked' => 'text-amber-800',
                default => 'text-gray-400',
            };
            $stateDescription = match ($state) {
                'done' => 'complete',
                'active' => 'in progress',
                'blocked' => 'needs attention',
                default => 'not started',
            };
        @endphp
        <li class="flex items-center" wire:key="pipeline-step-{{ $loop->index }}-{{ $step['label'] }}">
            @if(!$loop->first)
                <span class="mx-2 h-px w-4 bg-gray-300 sm:w-6" aria-hidden="true"></span>
            @endif
            <span class="flex items-center gap-1.5">
                <span class="h-2.5 w-2.5 rounded-full border {{ $dotClasses }}" aria-hidden="true"></span>
                <span class="text-xs font-medium {{ $labelClasses }}">{{ $step['label'] }}</span>
                <span class="sr-only">({{ $stateDescription }})</span>
            </span>
        </li>
    @endforeach
</ol>
