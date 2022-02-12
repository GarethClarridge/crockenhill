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
      <input type="date" name="date" value="{{ $next_service_upload_date }}" class="form-control">
    </div>

    @foreach ($services as $key => $value)
      <h3 class="mt-5">{{$value}}</h3>

      @for ($i = 1; $i < 10; $i++)
        <div class="mb-3">
          <div class="container">
            <div class="row">
              <div class="col-1">
                <label for="{{$key.$i}}" class="control-label">{{$i}}</label>
              </div>
              <div class="col-11">
                <select name="{{$key.$i}}" class="song-select form-control">
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

      <div class="d-grid gap-2 m-3">
        <input class="btn btn-success btn-lg" type="submit" value="Save">
      </div>
    </div>
  </form>
</section>
@stop
