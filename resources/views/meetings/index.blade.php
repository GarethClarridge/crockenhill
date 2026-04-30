@extends('layouts.main')

@section('content')
<x-admin.shell heading="Meetings">

<x-admin.list-shell
    title="Meetings"
    description="Manage church meetings and events."
>
    <x-slot:actions>
        <x-button link="{{ route('admin.meetings.create') }}" variant="primary" icon="plus" inline>
            Create Meeting
        </x-button>
    </x-slot:actions>

    <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Meeting</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Location</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recurring</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
            @forelse($meetings as $meeting)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <a href="/meetings/{{ $meeting->slug }}" class="font-medium hover:text-cbc-teal">
                            {{ $meeting->slug }}
                        </a>
                    </td>
                    <td class="px-4 py-3 text-sm">{{ $meeting->type->label() }}</td>
                    <td class="px-4 py-3 text-sm">{{ $meeting->meeting_date ? $meeting->meeting_date->format('M j, Y') : 'No date set' }}</td>
                    <td class="px-4 py-3 text-sm">{{ $meeting->location ?? 'No location' }}</td>
                    <td class="px-4 py-3">
                        @if($meeting->is_recurring)
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                                {{ $meeting->frequency?->label() ?? 'Recurring' }}
                            </span>
                        @else
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                                One-time
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex gap-1 justify-end" role="group" aria-label="Actions for {{ $meeting->slug }}">
                            <x-button :link="route('admin.meetings.edit', $meeting)" variant="ghost" size="xs" icon="pencil" inline aria-label="Edit {{ $meeting->slug }}" />
                            <form method="POST" action="{{ route('meetings.destroy', $meeting) }}" class="inline">
                                @csrf
                                @method('DELETE')
                                <x-form-button variant="ghost" size="xs" icon="trash" class="text-red-600"
                                    onclick="return confirm('Are you sure you want to delete this meeting?')"
                                    aria-label="Delete {{ $meeting->slug }}" />
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <x-admin.empty-state
                    colspan="6"
                    title="No meetings found"
                    description="There are no meetings yet. Create one to get started."
                />
            @endforelse
        </tbody>
    </table>
</x-admin.list-shell>

</x-admin.shell>
@endsection
