@extends('layouts/page')

@push('scripts')
  <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/js/select2.min.js"></script>
  <script type="text/javascript">
    $(document).ready(function() {
      $(".song-select").select2({
        placeholder: "Choose a song"
      });
    });
  </script>
@endpush

@push('styles')
  <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.3/css/select2.min.css" rel="stylesheet" />
@endpush

@section('dynamic_content')

<section>
  <form action="/church/members/songs/service-record" method="post" class="form-horizontal">
    <input type="hidden" name="_token" value="{{ csrf_token() }}">

    <div class="mb-3">
      <label for="date" class="control-label">Date</label>
      <input type="date" name="date" value="{{ $next_service_upload_date }}" class="block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded">
    </div>

    @foreach ($services as $key => $value)
      <h3 class="mt-5">{{$value}}</h3>

      @for ($i = 1; $i < 10; $i++)
        <div class="mb-3">
          <div class="container mx-auto sm:px-4">
            <div class="flex flex-wrap ">
              <div class="w-1/6">
                <label for="{{$key.$i}}" class="control-label">{{$i}}</label>
              </div>
              <div class="w-5/6">
                <select name="{{$key.$i}}" class="song-select block appearance-none w-full py-1 px-2 mb-1 text-base leading-normal bg-white text-gray-800 border border-gray-200 rounded">
                  <option value=""></option>
                  @foreach ($songs as $song)
                    @if ($song->praise_number != '')
                      <option value="{{$song->id}}">#{{$song->praise_number}}: {{$song->title}}</option>
                    @else
                      <option value="{{$song->id}}">{{$song->title}}</option>
                    @endif
                  @endforeach
                </select>
              </div>
            </div>
          </div>
        </div>
      @endfor
    @endforeach

      <div class="d-grid gap-2 m-6">
        <input class="inline-block align-middle text-center select-none border font-normal whitespace-no-wrap rounded  no-underline bg-green-500 hover:bg-green-600 py-3 px-4 leading-tight text-xl" type="submit" value="Save">
      </div>
    </div>
  </form>
</section>
@stop
