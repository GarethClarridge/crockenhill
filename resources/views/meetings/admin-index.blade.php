@extends('layouts.page') {{-- Assuming this is the correct layout based on pages/index.blade.php --}}

@section('dynamic_content') {{-- Assuming this is the correct section name --}}

  {{-- Display any session messages --}}
  @if (Session::has('message'))
      <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
          <span class="block sm:inline">{{ Session::get('message') }}</span>
      </div>
  @endif

  {{-- Use x-button component if available and it generates an <a> tag, or use <a> directly --}}
  {{-- The link should go to the route for creating a new meeting --}}
  <div class="mb-4">
    <a href="{{ route('community.create') }}" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-2 px-4 leading-tight text-xs bg-blue-500 text-white hover:bg-blue-600"> {{-- Example styling, adjust if x-button has its own styles --}}
      Create New Meeting
    </a>
  </div>


  <x-h2>
    Existing Meetings
  </x-h2>

  <div class="block w-full overflow-auto scrolling-touch">
    <table class="w-full max-w-full mb-4 bg-transparent table-hover">
      <thead>
        <tr>
          <th class="px-4 py-2 border">Title</th>
          <th class="px-4 py-2 border">Slug</th>
          <th class="px-4 py-2 border">Type</th>
          <th class="px-4 py-2 border">Day</th>
          <th class="px-4 py-2 border">Time</th>
          <th class="px-4 py-2 border"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
        @if ($meetings->isEmpty())
            <tr>
                <td colspan="6" class="px-4 py-2 border text-center">No meetings found.</td>
            </tr>
        @else
            @foreach ($meetings as $meeting)
              <tr>
                <td class="px-4 py-2 border">
                  <a href="{{ route('community.show', $meeting->slug) }}" class="text-blue-600 hover:underline">
                    {{ $meeting->title ?: Str::title(str_replace('-', ' ', $meeting->slug)) }}
                  </a>
                </td>
                <td class="px-4 py-2 border">{{ $meeting->slug }}</td>
                <td class="px-4 py-2 border">{{ $meeting->type }}</td>
                <td class="px-4 py-2 border">{{ $meeting->day }}</td>
                <td class="px-4 py-2 border">
                  {{ $meeting->StartTime ? \Carbon\Carbon::parse($meeting->StartTime)->format('H:i') : '' }}
                  {{ $meeting->StartTime && $meeting->EndTime ? '-' : '' }}
                  {{ $meeting->EndTime ? \Carbon\Carbon::parse($meeting->EndTime)->format('H:i') : '' }}
                </td>
                <td class="px-4 py-2 border">
                  <div class="relative inline-flex align-middle">
                    {{-- View link can be part of the title link, or separate if needed --}}
                    {{-- <a href="{{ route('community.show', $meeting->slug) }}" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-blue-500 text-white hover:bg-blue-600 mr-1">View</a> --}}
                    <a href="{{ route('community.edit', $meeting->slug) }}" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-green-500 text-white hover:bg-green-600 mr-1">
                      Edit
                    </a>
                    {{-- Delete Form - ensure community.destroy route exists and is handled --}}
                    {{-- <form action="{{ route('community.destroy', $meeting->slug) }}" method="POST" class="inline-block" onsubmit="return confirm('Are you sure you want to delete this meeting?');">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded py-1 px-3 leading-normal no-underline bg-red-600 text-white hover:bg-red-700">
                          Delete
                        </button>
                    </form> --}}
                  </div>
                </td>
              </tr>
            @endforeach
        @endif
      </tbody>
    </table>
  </div>

  {{-- If using pagination: --}}
  {{-- @if ($meetings instanceof \Illuminate\Pagination\LengthAwarePaginator)
    <div class="mt-4">
        {{ $meetings->links() }}
    </div>
  @endif --}}

@stop
