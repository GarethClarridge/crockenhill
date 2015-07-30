@extends('page')

@section('dynamic_content')

@foreach ($sermons as $sermon)
  <h3><a href="/sermons/{{$sermon->slug}}">{{$sermon->title}}</a></h3> 
  <p>
    <span class="glyphicon glyphicon-calendar"></span>
    &nbsp; {{date ('jS \of F', strtotime($sermon->date))}}
  </p>
  <p>
    <span class="glyphicon glyphicon-book"></span> &nbsp
    {{ $sermon->reference }}
  </p>
@endforeach

@stop