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
    <h2>Sermons</h2>
    @foreach ($latest_sermons as $date => $sermon)
      <br>
      <h4>{{ $date }}</h4>
      @foreach ($sermon as $sermon)
        @include('includes.sermon-display')
      @endforeach
      <br>
    @endforeach
  </div>
</div>
<br>
<a href="/sermons/all" class="btn btn-primary btn-lg btn-block" role="button">Find older sermons</a>

@stop