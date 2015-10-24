@extends('page')

@section('dynamic_content')

@if (session('message'))
  <div class="alert alert-success" role="alert">
    {{ session('message') }}
  </div>
@endif

@if ($user != null && $user->email == "admin@crockenhill.org")
    <a href="/sermons/create" class="btn btn-primary btn-lg btn-block" role="button">Upload a new sermon</a>
@endif

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
<a href="/sermons/all" class="btn btn-primary btn-lg btn-block" role="button">Find older sermons</a>

@stop