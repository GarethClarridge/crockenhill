@extends('layouts/page')

@section('dynamic_content')
  <x-button link="meetings/create">
    Create a new meeting
  </x-button>

  <x-h2>
    Existing meetings
  </x-h2>

  <div class="block w-full overflow-auto scrolling-touch">
    <table class="w-full max-w-full mb-4 bg-transparent table-hover">
      <thead>
        <tr>
          <th>Meeting</th>
          <th>Type</th>
          <th>Date</th>
          <th>Location</th>
          <th>Recurring</th>
          <th><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($meetings as $meeting)
          <tr>
            <td>
              <a href="/meetings/{{ $meeting->slug }}">
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
              <form class="flex items-center" action="/church/members/meetings/{{ $meeting->slug }}" method="POST">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <div class="relative inline-flex align-middle">
                  <a href="/church/members/meetings/{{ $meeting->slug }}/edit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-green-500 hover:bg-green-600 text-white">
                    Edit
                  </a>
                  <button type="submit" onclick="return confirm('Are you sure you want to delete this meeting?')" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-red-600 hover:bg-red-700 text-white">
                    Delete
                  </button>
                </div>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

@stop