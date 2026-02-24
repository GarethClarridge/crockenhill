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
    <select class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="service" name="service">
      @foreach (\App\Enums\SermonService::cases() as $service)
        <option value="{{ $service->value }}" {{ ($sermon->service instanceof \App\Enums\SermonService ? $sermon->service->value : $sermon->service) === $service->value ? 'selected' : '' }}>{{ $service->label() }}</option>
      @endforeach
    </select>
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
    <div class="mt-2">
      <label class="inline-flex items-center">
        <input type="checkbox" name="show_points" value="1" {{ $sermon->show_points ? 'checked' : '' }} class="rounded border-gray-300 text-green-600 shadow-sm focus:border-green-300 focus:ring focus:ring-green-200 focus:ring-opacity-50">
        <span class="ml-2 text-sm text-gray-700">Show sermon outline on public page</span>
      </label>
    </div>
  </div>

  <div class="mb-3">
    <label for="summary">Sermon summary</label>
    <textarea class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" rows="4" name="summary" id="summary">{{ $sermon->summary }}</textarea>
    <small class="text-xs text-gray-600">AI-generated summary of the sermon (optional)</small>
    <div class="mt-2">
      <label class="inline-flex items-center">
        <input type="checkbox" name="show_summary" value="1" {{ $sermon->show_summary ? 'checked' : '' }} class="rounded border-gray-300 text-green-600 shadow-sm focus:border-green-300 focus:ring focus:ring-green-200 focus:ring-opacity-50">
        <span class="ml-2 text-sm text-gray-700">Show summary on public page</span>
      </label>
    </div>
  </div>

  <div class="form-actions">
    <input class="inline-block text-center select-none border font-normal whitespace-nowrap rounded no-underline bg-green-500 hover:bg-green-600 btn-save py-3 px-4 leading-tight text-xl" type="submit" value="Save">
    <a href="/sermons" wire:navigate class="inline-block text-center select-none border font-normal whitespace-nowrap rounded no-underline py-3 px-4 leading-tight text-xl">Cancel</a>
  </div>

</form>

@stop