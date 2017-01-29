@extends('page')

@section('dynamic_content')

  <h2>Sermons preached by {{str_replace("-", " ", title_case(\Request::segment(3)))}}</h2>

  @foreach ($sermons as $sermon)
    @include('includes.sermon-display')
  @endforeach

  {!! $sermons->render() !!}

@stop
