@extends('page')

@section('dynamic_content')

@foreach ($sermons as $sermon)
  <h3><a href="/sermons/{{date('Y', strtotime($sermon->date))}}/{{date('m', strtotime($sermon->date))}}/{{$sermon->slug}}">{{$sermon->title}}</a></h3> 
  <p>
    <span class="glyphicon glyphicon-calendar"></span>
    &nbsp; {{date ('jS \of F', strtotime($sermon->date))}}
  </p>
  <p>
    <span class="glyphicon glyphicon-user"></span> &nbsp
    {{ $sermon->preacher }}
  </p>
  <p>
    <span class="glyphicon glyphicon-book"></span> &nbsp
    {{ $sermon->reference }}
  </p>
@endforeach

{!! $sermons->render() !!}

@stop