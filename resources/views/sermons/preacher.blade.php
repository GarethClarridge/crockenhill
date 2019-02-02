@extends('page')

@section('dynamic_content')

  <div class="row justify-content-center">
    @foreach ($sermons as $sermon)
      <div class="col-lg-7">
        @include('includes.sermon-display')
        <br>
      </div>
    @endforeach
  </div>

@stop
