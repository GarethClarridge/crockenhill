@extends('page')

@section('dynamic_content')

  @can ('edit-sermons')
    <a href="/sermons" class="btn btn-primary btn-lg btn-block">Edit sermons</a>
  @endcan
  <a href="/members/songs" class="btn btn-primary btn-lg btn-block">View song list</a>
  @can ('edit-pages')
    <a href="/members/pages" class="btn btn-primary btn-lg btn-block">Edit pages</a>
  @endcan
  @can ('edit-documents')
    <a href="/members/documents" class="btn btn-primary btn-lg btn-block">View documents</a>
  @endcan

  <form action="logout" method="post">
    <br>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
   <button type="submit" name="logout" class="btn btn-primary btn-lg btn-block" role="button">Log out</button>
  </form>

@stop
