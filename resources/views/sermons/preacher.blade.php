@extends('page')

@section('dynamic_content')

@foreach ($sermons as $sermon)
  @include('includes.sermon-display')
@endforeach

{!! $sermons->render() !!}

@stop