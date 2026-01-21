@extends('layouts/page')

@section('dynamic_content')

<div class="max-w-2xl mx-auto px-6 mt-6 space-y-6">

  {{-- Welcome Header --}}
  <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
    <div class="flex">
      <x-heroicon-s-user-circle class="h-6 w-6 text-blue-400 mr-3 flex-shrink-0" />
      <div>
        <h2 class="text-lg font-medium text-blue-800">
          Welcome back, {{ Auth::user()->name }}
        </h2>
        <p class="mt-1 text-sm text-blue-700">
          What would you like to do today?
        </p>
      </div>
    </div>
  </div>

  {{-- Sermons Section --}}
  @can('edit-sermons')
  <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
    <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
      <h3 class="text-lg font-medium text-gray-900 flex items-center">
        <x-heroicon-o-microphone class="h-5 w-5 mr-2 text-gray-500" />
        Sermons
      </h3>
    </div>
    <div class="p-4 space-y-2">
      <x-button link="{{ route('admin.sermon-upload.create') }}">
        <div class="flex items-center justify-center">
          <x-heroicon-s-arrow-up-tray class="h-5 w-5 mr-2" />
          Upload sermon
        </div>
      </x-button>
    </div>
  </div>
  @endcan

  {{-- Calendar & Meetings Section --}}
  @can('edit-meetings')
  <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
    <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
      <h3 class="text-lg font-medium text-gray-900 flex items-center">
        <x-heroicon-o-calendar-days class="h-5 w-5 mr-2 text-gray-500" />
        Calendar & Meetings
      </h3>
    </div>
    <div class="p-4 space-y-2">
      <x-button link="/church/members/meetings">
        <div class="flex items-center justify-center">
          <x-heroicon-s-pencil-square class="h-5 w-5 mr-2" />
          Edit meetings
        </div>
      </x-button>

      <form method="POST" action="{{ route('admin.calendar.sync') }}">
        @csrf
        <button type="submit"
                class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-md bg-cbc-pattern bg-cover focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all">
          <div class="flex items-center justify-center">
            <x-heroicon-s-arrow-path class="h-5 w-5 mr-2" />
            Sync events from Google Calendar
          </div>
        </button>
      </form>

      <x-button link="{{ route('admin.calendar.patterns') }}">
        <div class="flex items-center justify-center">
          <x-heroicon-s-list-bullet class="h-5 w-5 mr-2" />
          Show event:meeting matching patterns
        </div>
      </x-button>

      <x-button link="/church/members/calendar/uncategorized">
        <div class="flex items-center justify-center">
          <x-heroicon-s-tag class="h-5 w-5 mr-2" />
          Categorise non-matching calendar events
        </div>
      </x-button>
    </div>
  </div>
  @endcan

  {{-- Content Section --}}
  @can('edit-pages')
  <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
    <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
      <h3 class="text-lg font-medium text-gray-900 flex items-center">
        <x-heroicon-o-document-text class="h-5 w-5 mr-2 text-gray-500" />
        Content
      </h3>
    </div>
    <div class="p-4 space-y-2">
      <x-button link="/admin/pages">
        <div class="flex items-center justify-center">
          <x-heroicon-s-pencil-square class="h-5 w-5 mr-2" />
          Edit pages
        </div>
      </x-button>
    </div>
  </div>
  @endcan

  {{-- Account Section --}}
  <div class="rounded-lg shadow bg-white border border-gray-300 overflow-hidden">
    <div class="bg-gray-50 border-b border-gray-200 px-4 py-3">
      <h3 class="text-lg font-medium text-gray-900 flex items-center">
        <x-heroicon-o-user class="h-5 w-5 mr-2 text-gray-500" />
        Account
      </h3>
    </div>
    <div class="p-4 space-y-2">
      <form action="/logout" method="post">
        @csrf
        <button type="submit" name="logout"
                class="w-full no-underline mx-auto block max-w-md p-4 text-center text-white rounded-md bg-gradient-to-r from-rose-600 to-rose-700 focus:ring-2 focus:ring-blue-800 focus:ring-offset-2 transition-all"
                role="button">
          <div class="flex items-center justify-center">
            <x-heroicon-s-arrow-right-start-on-rectangle class="h-5 w-5 mr-2" />
            Log out
          </div>
        </button>
      </form>
    </div>
  </div>

</div>

@stop
