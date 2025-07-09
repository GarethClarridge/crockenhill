@extends('layouts/page')

@section('dynamic_content')

<form class="" action="/church/members/meetings" method="post">
  <input type="hidden" name="_token" value="{{ csrf_token() }}">

  @if (count($errors) > 0)
  <div class="relative px-3 py-3 mb-4 border rounded bg-red-200 border-red-300 text-red-800">
    <strong>Whoops!</strong> There were some problems:<br><br>
    <ul>
      @foreach ($errors->all() as $error)
      <li>{{ $error }}</li>
      @endforeach
    </ul>
  </div>
  @endif

  <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    
    <!-- Left Column -->
    <div>
      <label class="block" for="slug">Meeting Slug</label>
      <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
             id="slug" name="slug" type="text" value="{{ old('slug') }}" required>
      <small class="text-gray-600">Used for URL identification (e.g., 'bible-study')</small>

      <label class="block mt-6" for="type">Meeting Type</label>
      <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
              name="type" id="type" required>
        <option value="">Select a type...</option>
        @foreach(\App\Enums\MeetingType::cases() as $type)
          <option value="{{ $type->value }}" {{ old('type') === $type->value ? 'selected' : '' }}>
            {{ $type->label() }}
          </option>
        @endforeach
      </select>

      <label class="block mt-6" for="day">Day of Week</label>
      <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
             id="day" name="day" type="text" value="{{ old('day') }}" placeholder="e.g., Wednesday">

      <label class="block mt-6" for="location">Location</label>
      <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
             id="location" name="location" type="text" value="{{ old('location') }}" placeholder="e.g., Church Hall">

      <label class="block mt-6" for="who">Who Can Attend</label>
      <textarea class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
                id="who" name="who" rows="3" placeholder="e.g., All adults welcome">{{ old('who') }}</textarea>
    </div>

    <!-- Right Column -->
    <div>
      <label class="block" for="meeting_date">Meeting Date</label>
      <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
             id="meeting_date" name="meeting_date" type="date" value="{{ old('meeting_date') }}">

      <div class="grid grid-cols-2 gap-4 mt-6">
        <div>
          <label class="block" for="StartTime">Start Time</label>
          <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
                 id="StartTime" name="StartTime" type="time" value="{{ old('StartTime') }}">
        </div>
        <div>
          <label class="block" for="EndTime">End Time</label>
          <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
                 id="EndTime" name="EndTime" type="time" value="{{ old('EndTime') }}">
        </div>
      </div>

      <label class="block mt-6" for="LeadersPhone">Leader's Phone</label>
      <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
             id="LeadersPhone" name="LeadersPhone" type="tel" value="{{ old('LeadersPhone') }}" maxlength="10">

      <label class="block mt-6" for="LeadersEmail">Leader's Email</label>
      <input class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
             id="LeadersEmail" name="LeadersEmail" type="email" value="{{ old('LeadersEmail') }}" maxlength="50">

      <p class="mt-6">Has Pictures</p>
      <div class="grid grid-cols-2 gap-3 pt-2">
        <div class="h-full flex items-center pl-4 bg-gray-300 rounded">
          <input id="pictures-yes" type="radio" value="1" name="pictures" 
                 class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-400 focus:ring-blue-500 focus:ring-2"
                 {{ old('pictures') === '1' ? 'checked' : '' }}>
          <label for="pictures-yes" class="w-full py-4 ml-2 text-sm font-medium text-gray-900">
            Yes
          </label>
        </div>
        <div class="h-full flex items-center pl-4 bg-gray-300 rounded">
          <input id="pictures-no" type="radio" value="0" name="pictures" 
                 class="w-4 h-4 text-blue-600 bg-gray-100 border-gray-400 focus:ring-blue-500 focus:ring-2"
                 {{ old('pictures') === '0' ? 'checked' : '' }}>
          <label for="pictures-no" class="w-full py-4 ml-2 text-sm font-medium text-gray-900">
            No
          </label>
        </div>
      </div>

      <div class="mt-6">
        <label class="flex items-center">
          <input type="checkbox" name="is_recurring" value="1" id="is_recurring" 
                 class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50"
                 {{ old('is_recurring') ? 'checked' : '' }}>
          <span class="ml-2 text-sm text-gray-600">This is a recurring meeting</span>
        </label>
      </div>

      <div id="frequency-section" class="mt-6" style="display: none;">
        <label class="block" for="frequency">Frequency</label>
        <select class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50" 
                name="frequency" id="frequency">
          <option value="">Select frequency...</option>
          @foreach(\App\Enums\MeetingFrequency::cases() as $freq)
            <option value="{{ $freq->value }}" {{ old('frequency') === $freq->value ? 'selected' : '' }}>
              {{ $freq->label() }}
            </option>
          @endforeach
        </select>
      </div>
    </div>

  </div>

  <div class="form-actions mt-8">
    <div class="grid gap-2 mb-3">
      <x-form-button variant="primary" size="xl">Save Meeting</x-form-button>
    </div>
    <div class="text-center">
      <x-button link="/church/members/meetings/" variant="outline">Cancel</x-button>
    </div>
  </div>
</form>

<script>
document.getElementById('is_recurring').addEventListener('change', function() {
  const frequencySection = document.getElementById('frequency-section');
  if (this.checked) {
    frequencySection.style.display = 'block';
  } else {
    frequencySection.style.display = 'none';
    document.getElementById('frequency').value = '';
  }
});

// Show frequency section if it was checked on page load (after validation error)
if (document.getElementById('is_recurring').checked) {
  document.getElementById('frequency-section').style.display = 'block';
}

// Convert time format from H:i to H:i:s before form submission
document.querySelector('form').addEventListener('submit', function(e) {
  const startTime = document.getElementById('StartTime');
  const endTime = document.getElementById('EndTime');
  
  if (startTime.value && !startTime.value.includes(':00')) {
    startTime.value = startTime.value + ':00';
  }
  
  if (endTime.value && !endTime.value.includes(':00')) {
    endTime.value = endTime.value + ':00';
  }
});
</script>

@stop