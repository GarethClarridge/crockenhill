@extends('layouts/page')

@section('dynamic_content')

  @can ('edit-sermons')
  <div class="d-grid gap-2 mb-3">
    <a href="/church/sermons/create" class="btn btn-primary btn-lg" role="button">Upload a new sermon</a>
  </div>
  @endcan

  @foreach ($latest_sermons as $date => $sermons)
    <section class="week">
      <div class="row justify-content-center">
        @if (count($sermons) != 2)
          @foreach ($sermons as $sermon)
            <div class="col-lg-12">
              @if ($sermon->service === "morning")
                @include('includes.sermon-display')
              @endif
            </div>

            <div class="col-lg-12">
              @if ($sermon->service === "evening")
                @include('includes.sermon-display')
              @endif
            </div>
          @endforeach
        @else
          @foreach ($sermons as $sermon)
            <div class="col-lg-12">
              @include('includes.sermon-display')
            </div>
          @endforeach
        @endif
        <br>
      </div>
    </section>
  @endforeach
  <br>

  <div class="d-grid gap-2 mb-3">
    <a href="/church/sermons/all" class="btn btn-primary btn-lg" role="button">Find older sermons</a>
  </div>

@stop
