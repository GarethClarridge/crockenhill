@extends('page')

@section('dynamic_content')

<div class="row">
  <div class="col-md-6">
    <h2>Morning</h2>
    @foreach ($latest_morning_sermons as $sermon)
      @include('includes.sermon-display')
    @endforeach
  </div>
  <div class="col-md-6">
    <h2>Evening</h2>
    @foreach ($latest_evening_sermons as $sermon)
      @include('includes.sermon-display')
    @endforeach
  </div>
</div>
<br>
{!! $latest_morning_sermons->render() !!}

@stop