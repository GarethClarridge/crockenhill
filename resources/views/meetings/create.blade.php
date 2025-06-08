@extends('layouts.page')

@section('dynamic_content')

  <x-h1>Create New Meeting</x-h1>

  <form class="" action="{{ route('community.store') }}" method="post">
    @csrf

    {{-- Error Display Block (copied from pages/create.blade.php) --}}
    @if (count($errors) > 0)
    <div class="relative px-3 py-3 mb-4 border rounded bg-red-200 border-red-300 text-red-800" role="alert">
      <strong>Whoops!</strong> There were some problems with your input:<br><br>
      <ul>
        @foreach ($errors->all() as $error)
        <li>{{ $error }}</li>
        @endforeach
      </ul>
    </div>
    @endif

    {{-- Session Message Display --}}
    @if (Session::has('message'))
        <div class="relative px-3 py-3 mb-4 border rounded bg-green-200 border-green-300 text-green-800" role="alert">
            {{ Session::get('message') }}
        </div>
    @endif

    <div class="space-y-6"> {{-- Added a container for spacing --}}
      <div>
        <label class="block text-sm font-medium text-gray-700" for="title">Title</label>
        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="title" name="title" type="text" value="{{ old('title') }}">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="slug">Slug</label>
        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="slug" name="slug" type="text" value="{{ old('slug') }}">
        <p class="mt-1 text-xs text-gray-500">Unique identifier for the URL (e.g., 'baby-talk', 'sunday-morning-service'). Usually auto-generated if left blank, but can be specified.</p>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="type">Type / Category</label>
        <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="type" name="type">
            <option value="">Select a type...</option>
            <option value="SundayAndBibleStudies" {{ old('type') == 'SundayAndBibleStudies' ? 'selected' : '' }}>Sunday & Bible Studies</option>
            <option value="ChildrenAndYoungPeople" {{ old('type') == 'ChildrenAndYoungPeople' ? 'selected' : '' }}>Children & Young People</option>
            <option value="Adults" {{ old('type') == 'Adults' ? 'selected' : '' }}>Adults</option>
            <option value="Occasional" {{ old('type') == 'Occasional' ? 'selected' : '' }}>Occasional</option>
        </select>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="day">Day(s) of the week</label>
        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="day" name="day" type="text" value="{{ old('day') }}" placeholder="e.g., Every Sunday, First Tuesday of the month">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="location">Location</label>
        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="location" name="location" type="text" value="{{ old('location') }}" placeholder="e.g., Church Hall, Online via Zoom">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="who">Who is it for?</label>
        <textarea class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="who" name="who" rows="3">{{ old('who') }}</textarea>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
          <label class="block text-sm font-medium text-gray-700" for="StartTime">Start Time</label>
          <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="StartTime" name="StartTime" type="time" value="{{ old('StartTime') }}">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700" for="EndTime">End Time</label>
          <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="EndTime" name="EndTime" type="time" value="{{ old('EndTime') }}">
        </div>
      </div>

      <div class="flex items-center">
        <input id="pictures" name="pictures" type="checkbox" value="1" {{ old('pictures') ? 'checked' : '' }} class="h-4 w-4 rounded border-gray-300 text-indigo-600 focus:ring-indigo-500">
        <label for="pictures" class="ml-2 block text-sm text-gray-900">Has associated pictures?</label>
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="LeadersPhone">Leader's Phone (Optional)</label>
        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="LeadersPhone" name="LeadersPhone" type="tel" value="{{ old('LeadersPhone') }}">
      </div>

      <div>
        <label class="block text-sm font-medium text-gray-700" for="LeadersEmail">Leader's Email (Optional)</label>
        <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" id="LeadersEmail" name="LeadersEmail" type="email" value="{{ old('LeadersEmail') }}">
      </div>
    </div>

    <div class="mt-8 pt-5">
      <div class="flex justify-end">
        <a href="{{ route('meetings.admin_index') }}" class="bg-white py-2 px-4 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
          Cancel
        </a>
        <button type="submit" class="ml-3 inline-flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500">
          Create Meeting
        </button>
      </div>
    </div>
  </form>

@stop
