@extends('pages.page')

@section('dynamic_content')

<div class="row">
  <div class="col-md-6">
    <h2>Morning</h2>
    @foreach ($latest_morning_sermons as $sermon)
      <h3><a href="sermons/{{$sermon->slug}}">{{$sermon->title}}</a></h3> 
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
  </div>
  <div class="col-md-6">
    <h2>Evening</h2>
        @foreach ($latest_evening_sermons as $sermon)
      <h3><a href="sermons/{{$sermon->slug}}">{{$sermon->title}}</a></h3> 
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
  </div>
</div>

@stop