@extends('layouts/page')

@section('dynamic_content')
  <div class="px-6 max-w-2xl mx-auto mt-6">
    <x-button link="meetings/create">
      Create a new meeting
    </x-button>
  </div>

  <x-h2>
    Existing meetings
  </x-h2>

  <x-admin-table :headers="['Meeting', 'Type', 'Date', 'Location', 'Recurring', '']">
    @foreach ($meetings as $meeting)
      <tr>
        <td>
          <a href="/meetings/{{ $meeting->slug }}" wire:navigate>
            {{ $meeting->slug }}
          </a>
        </td>
        <td>{{ $meeting->type->label() }}</td>
        <td>{{ $meeting->meeting_date ? $meeting->meeting_date->format('M j, Y') : 'No date set' }}</td>
        <td>{{ $meeting->location ?? 'No location' }}</td>
        <td>
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
        <td>
          <x-admin-actions 
            :editRoute="'/church/members/meetings/' . $meeting->slug . '/edit'"
            :deleteRoute="'/church/members/meetings/' . $meeting->slug"
            deleteConfirmMessage="Are you sure you want to delete this meeting?"
            layout="inline" />
        </td>
      </tr>
    @endforeach
  </x-admin-table>

@stop