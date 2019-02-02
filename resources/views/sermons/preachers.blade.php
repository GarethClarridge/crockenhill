@extends('page')

@section('dynamic_content')
  <br>
  <ul class="list-group list-group-flush">
    @foreach ($preachers as $preacher)
      <li class="list-group-item">
        <a href="preachers/{!! \Illuminate\Support\Str::slug($preacher[1]) !!}">
          {{$preacher[1]}}
          <small>({!! $preacher[0] !!})</small>
        </a>
      </li>
    @endforeach
  </ul>


@stop
