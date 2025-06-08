@extends('layouts/page')

@section('dynamic_content')

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

<form method="POST" action="/church/members/services" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
  <input type="hidden" name="_token" value="{{ csrf_token() }}">

  <div class="mb-3">
    <label for="date">Date</label>
    <input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date">
  </div>

  <x-file-upload name="file" label="Upload a video recording" />


  <div class="form-actions d-grid gap-2">
    <input class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-green-500 hover:bg-green-600 btn-save py-3 px-4 leading-tight text-xl" type="submit" value="Save">
    <a href="/sermons" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline py-3 px-4 leading-tight text-xl">Cancel</a>
  </div>

</form>

@stop