@extends('layouts/page')

@section('dynamic_content')

    @foreach ($sermons as $sermon)
      <x-sermon-card :$sermon/>
    @endforeach


@stop
