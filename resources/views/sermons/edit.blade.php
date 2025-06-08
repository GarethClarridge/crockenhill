@extends('layouts/page')

@section('dynamic_content')

{{-- Breadcrumb section and its preceding PHP block removed --}}

@if (session('message'))
<div class="relative px-3 py-3 mb-4 border rounded bg-green-200 border-green-300 text-green-800" role="alert">
  {{ session('message') }}
</div>
@endif

<form method="POST" action="/christ/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}/edit" accept-charset="UTF-8">
  <input type="hidden" name="_token" value="{{ csrf_token() }}">

  <div class="mb-3">
    <label for="title">Title</label>
    <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded h1" id="title" name="title" type="text" value="{{$sermon->title}}">
  </div>

  <div class="mb-3">
    <label for="date">Date</label>
    <input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date" value="{{ $sermon->date ? $sermon->date->format('Y-m-d') : '' }}">
  </div>

  <div class="mb-3">
    <label for="service">Service</label>
    @if ($sermon->service == 'morning')
    <select type="service" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="service" name="service">
      <option value="morning" selected>Morning</option>
      <option value="evening">Evening (or afternoon)</option>
    </select>
    @else
    <select type="service" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="service" name="service">
      <option value="morning">Morning</option>
      <option value="evening" selected>Evening (or afternoon)</option>
    </select>
    @endif
  </div>

  <div class="mb-3">
    <label for="series">Series</label>
    <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="series" name="series" type="text" value="{{$sermon->series}}">
  </div>

  <div class="mb-3">
    <label for="reference">Reference</label>
    <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="reference" type="text" id="reference" value="{{$sermon->reference}}">
  </div>

  <div class="mb-3">
    <label for="preacher">Preacher</label>
    <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="preacher" name="preacher" type="text" value="{{$sermon->preacher}}">
  </div>

  <div class="mb-3">
    <label for="points">Sermon headings</label>
    <textarea class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded font-mono" rows="8" name="points">
@if(is_array($sermon->points))
{{ json_encode($sermon->points, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}
@else
{{ $sermon->points }}
@endif
</textarea>
    <small class="text-xs text-gray-600">Sermon outline should be entered as a valid JSON array. E.g., `[{"point":"Main Point 1", "sub_points":["Sub 1.1"]}]`</small>
  </div>

  <div class="form-actions">
    <input class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-green-500 hover:bg-green-600 btn-save py-3 px-4 leading-tight text-xl" type="submit" value="Save">
    <a href="/sermons" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline py-3 px-4 leading-tight text-xl">Cancel</a>
  </div>

</form>

@stop