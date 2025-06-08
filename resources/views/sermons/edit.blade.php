@extends('layouts/page')

@section('dynamic_content')

@php
 use Illuminate\Support\Str;
 // $sermon is passed to this view.
 // $year, $month, $slug should be derived from $sermon->date and $sermon->slug for link consistency
 $sermonYear = date('Y', strtotime($sermon->date));
 $sermonMonth = date('m', strtotime($sermon->date));
@endphp
<nav class="mb-6 text-sm" aria-label="Breadcrumb">
    <ol class="inline-flex items-center space-x-1 md:space-x-2 rtl:space-x-reverse">
        <li class="inline-flex items-center">
            <a href="/" class="inline-flex items-center text-gray-700 hover:text-blue-600 dark:text-gray-400 dark:hover:text-white">
                <svg class="w-3 h-3 me-2.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20"><path d="m19.707 9.293-2-2-7-7a1 1 0 0 0-1.414 0l-7 7-2 2a1 1 0 0 0 1.414 1.414L2 10.414V18a2 2 0 0 0 2 2h3a1 1 0 0 0 1-1v-4a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1v4a1 1 0 0 0 1 1h3a2 2 0 0 0 2-2v-7.586l.293.293a1 1 0 0 0 1.414-1.414Z"/></svg>
                Home
            </a>
        </li>
        <li>
            <div class="flex items-center">
                <svg class="rtl:rotate-180 w-3 h-3 text-gray-400 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/></svg>
                <a href="/christ/sermons" class="ms-1 text-gray-700 hover:text-blue-600 md:ms-2 dark:text-gray-400 dark:hover:text-white">Sermons</a>
            </div>
        </li>
        @if ($sermon->series)
        <li>
            <div class="flex items-center">
                <svg class="rtl:rotate-180 w-3 h-3 text-gray-400 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/></svg>
                <a href="/christ/sermons/series/{{ Str::slug($sermon->series) }}" class="ms-1 text-gray-700 hover:text-blue-600 md:ms-2 dark:text-gray-400 dark:hover:text-white">{{ $sermon->series }}</a>
            </div>
        </li>
        @endif
        <li>
            <div class="flex items-center">
                <svg class="rtl:rotate-180 w-3 h-3 text-gray-400 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/></svg>
                <a href="/christ/sermons/{{ $sermonYear }}/{{ $sermonMonth }}/{{ $sermon->slug }}" class="ms-1 text-gray-700 hover:text-blue-600 md:ms-2 dark:text-gray-400 dark:hover:text-white">{{ $sermon->title }}</a>
            </div>
        </li>
        <li aria-current="page">
            <div class="flex items-center">
                <svg class="rtl:rotate-180 w-3 h-3 text-gray-400 mx-1" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 6 10"><path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 9 4-4-4-4"/></svg>
                <span class="ms-1 text-gray-500 md:ms-2 dark:text-gray-400">Edit</span>
            </div>
        </li>
    </ol>
</nav>
{{-- Existing content of @section('dynamic_content') follows --}}

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
    <input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date" value="{{$sermon->date}}">
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