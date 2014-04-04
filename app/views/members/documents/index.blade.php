@extends('pages.page')

@section('dynamic_content')

<br><br>

@if (Auth::check() && Auth::getUser()->hasRole('admin'))
  <a href="documents/create" class="btn btn-default btn-lg btn-block" role="button">Create a new page</a>
@endif 

@stop