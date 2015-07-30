@extends('page')

@section('dynamic_content')
  <br>
  @foreach ($preachers as $preacher)
    <h4>
      <a href="preachers/{!! \Illuminate\Support\Str::slug($preacher[1]) !!}">
        {{$preacher[1]}} 
        <small>({!! $preacher[0] !!})</small>
      </a>
    </h4>
  @endforeach

@stop