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

  <form method="POST" action="/christ/sermons" accept-charset="UTF-8" enctype="multipart/form-data" class="create">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <x-file-upload name="file" label="Upload a sermon audio file" />

    <x-file-text-input name="title" label="Title"/>

    <div class="mb-3">
      <label for="date">Date</label>
      @if (date('D') === 'Sun')
        <input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date" value="{!!date('Y-m-d')!!}">
      @else
        <input type="date" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="date" name="date" value="{!!date('Y-m-d',strtotime('last sunday'))!!}">
      @endif
    </div>

    <div class="mb-3">
      <label for="service">Service</label>
      @if (time() <= strtotime('15:00:00'))
        <select type="service" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="service" name="service">
          <option value="morning" selected>Morning (main service)</option>
          <option value="evening">Evening (prayer service)</option>
        </select>
      @else
        <select type="service" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="service" name="service">
          <option value="morning">Morning (main service)</option>
          <option value="evening" selected>Evening (prayer service)</option>
        </select>
      @endif
    </div>

    <div class="mb-3">
      <label for="series">Series</label>
      <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="series" name="series" type="text">
    </div>

    <div class="mb-3">
      <label for="reference">Reference</label>
      <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" name="reference" type="text" id="reference">
    </div>

    <div class="mb-3">
      <label for="preacher">Preacher</label>
      <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" id="preacher" name="preacher" type="text">
    </div>

    <h2>Sermon points</h2>

    @for ($p = 1; $p < 7; $p++)
      <div class="mb-3">
        <div class="flex flex-wrap ">
          <div class="md:w-1/5 pr-4 pl-4">
            <label for="point-{{$p}}">Point {{$p}}</label>
          </div>
          <div class="md:w-4/5 pr-4 pl-4">
            <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded h2 input-lg" type="text" name="point-{{$p}}" id="point-{{$p}}" data-id="{{$p}}">
          </div>
        </div>

        @for ($i = 1; $i < 6; $i++)
          <div class="flex flex-wrap ">
            <div class="md:w-1/4 pr-4 pl-4">
              <label for="sub-point-{{$p}}-{{$i}}">Sub-point {{$p}}.{{$i}}</label>
            </div>
            <div class="md:w-3/4 pr-4 pl-4">
              <input class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded" type="text" name="sub-point-{{$p}}-{{$i}}" id="sub-point-{{$p}}-{{$i}}" data-id="{{$p}}.{{$i}}">
            </div>
          </div>
        @endfor
      </div>
    @endfor

    <div class="form-actions d-grid gap-2">
      <input class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-green-500 hover:bg-green-600 btn-save py-3 px-4 leading-tight text-xl" type="submit" value="Save">
      <a href="/sermons" class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline py-3 px-4 leading-tight text-xl">Cancel</a>
    </div>

  </form>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js" integrity="sha256-/xUj+3OJU5yExlq6GSYGSHk7tPXikynS7ogEvDej/m4=" crossorigin="anonymous"></script>

  <script type="text/javascript">
    document.querySelector('input[type="file"]').onchange = function(e) {
        id3(this.files[0], function(err, tags) {
            // tags now contains your ID3 tags
            document.getElementById('title').value     = tags.title;
            document.getElementById('preacher').value  = tags.artist;
            document.getElementById('series').value    = tags.album;
            if (tags.comment) {
              document.getElementById('reference').value = tags.comment;
            }
            

        });
        var span = document.getElementById('filename')
        span.innerHTML = span.innerHTML + document.querySelector('input[type="file"]').value;
        span.classList.remove("hidden");
    }
  </script>

@stop
