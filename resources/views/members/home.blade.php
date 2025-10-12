@extends('layouts/page')

@section('dynamic_content')

<div class="px-6 grid grid-cols-1 gap-2 max-w-2xl mx-auto mt-6">

  @can ('edit-sermons')
  <x-button link="{{ route('admin.sermon-upload.create') }}">
    Upload sermon
  </x-button>
  @endcan
  @can ('edit-meetings')
  <x-button link="/church/members/meetings">
    Edit meetings
  </x-button>
  <form method="POST" action="{{ route('admin.calendar.sync') }}">
    @csrf
    <x-form-button type="submit">
      Sync events from Google Calendar
    </x-form-button>
  </form>

  <x-button link="{{ route('admin.calendar.patterns') }}">
    Show event:meeting matching patterns
  </x-button>

  <x-button link="/church/members/calendar/uncategorized">
    Categorise non-matching calendar events
  </x-button>
  @endcan
  @can ('edit-pages')
  <x-button link="/church/members/pages">
    Edit pages
  </x-button>
  @endcan

  <form action="/logout" method="post">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <button
      type="submit"
      name="logout"
      class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all"
      role="button">
      Log out
    </button>
  </form>
</div>

@stop