@extends('layouts/page')

@section('dynamic_content')

  @can ('edit-sermons')
      <a href="/church/sermons/create" class="btn btn-primary btn-lg btn-block" role="button">Upload a new sermon</a>
  @endcan

  @foreach ($latest_sermons as $date => $sermons)
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
        <br>
      </div>
    </section>
  @endforeach
  <br>
  <a href="/church/sermons/all" class="btn btn-primary btn-lg btn-block" role="button">Find older sermons</a>

@stop
