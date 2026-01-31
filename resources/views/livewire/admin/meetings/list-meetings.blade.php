<div>
    <x-mary-header title="Meetings" subtitle="Manage church meetings and events">
        <x-slot:actions>
            <x-mary-button label="Create Meeting" icon="o-plus" link="{{ route('admin.meetings.create') }}" class="btn-primary" />
        </x-slot:actions>
    </x-mary-header>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-4 mb-6">
        <x-mary-input placeholder="Search meetings..." wire:model.live.debounce="search" icon="o-magnifying-glass" clearable class="w-64" />

        <x-mary-select
            placeholder="All Types"
            wire:model.live="typeFilter"
            :options="collect($types)->map(fn($t) => ['id' => $t->value, 'name' => $t->label()])"
            class="w-48"
        />

        <x-mary-select
            placeholder="Recurring"
            wire:model.live="recurringFilter"
            :options="[['id' => '1', 'name' => 'Recurring'], ['id' => '0', 'name' => 'One-time']]"
            class="w-40"
        />
    </div>

    {{-- Table --}}
    <x-mary-card>
        <x-mary-table :headers="$headers" :rows="$meetings" striped>
            @scope('cell_page', $meeting)
                <div>
                    <p class="font-medium">{{ $meeting->page?->heading ?? $meeting->slug }}</p>
                    <p class="text-sm text-base-content/60">{{ $meeting->who }}</p>
                </div>
            @endscope

            @scope('cell_schedule', $meeting)
                <div>
                    <p class="font-medium">{{ $meeting->day }}</p>
                    @if($meeting->StartTime)
                        <p class="text-sm text-base-content/60">
                            {{ $meeting->StartTime->format('H:i') }}
                            @if($meeting->EndTime)
                                - {{ $meeting->EndTime->format('H:i') }}
                            @endif
                        </p>
                    @endif
                </div>
            @endscope

            @scope('cell_type', $meeting)
                <x-mary-badge :value="$meeting->type->label()" class="badge-secondary" />
            @endscope

            @scope('cell_recurring', $meeting)
                @if($meeting->is_recurring)
                    <div class="flex items-center gap-2">
                        <x-mary-icon name="o-arrow-path" class="w-4 h-4 text-info" />
                        <span class="text-sm">{{ $meeting->frequency?->label() }}</span>
                    </div>
                @else
                    <span class="text-sm text-base-content/30">One-time</span>
                @endif
            @endscope

            @scope('cell_location', $meeting)
                <span class="text-sm">{{ $meeting->location ?? '-' }}</span>
            @endscope

            @scope('actions', $meeting)
                <div class="flex gap-1">
                    <x-mary-button icon="o-eye" link="{{ route('meetings.show', $meeting) }}" external class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-pencil" link="{{ route('admin.meetings.edit', $meeting) }}" class="btn-ghost btn-xs" />
                    <x-mary-button icon="o-trash" wire:click="delete({{ $meeting->id }})"
                        wire:confirm="Delete '{{ $meeting->page?->heading ?? $meeting->slug }}'?" class="btn-ghost btn-xs text-error" />
                </div>
            @endscope
        </x-mary-table>

        <div class="mt-4">
            {{ $meetings->links() }}
        </div>
    </x-mary-card>
</div>
