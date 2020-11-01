@extends('layouts/page')

@section('dynamic_content')

@foreach ($sermons as $date => $sermons)
  <section class="week">
    <div class="row justify-content-center">
      @if (count($sermons) != 2)
        @foreach ($sermons as $sermon)
          <div class="col-lg-7">
            @if ($sermon->service === "morning")
              @include('includes.sermon-display')
            @endif
          </div>

          <div class="col-lg-7">
            @if ($sermon->service === "evening")
              @include('includes.sermon-display')
            @endif
          </div>
        @endforeach
      @else
        @foreach ($sermons as $sermon)
          <div class="col-lg-7">
            @include('includes.sermon-display')
          </div>
        @endforeach
      @endif
    </div>
  </section>
@endforeach

@stop
