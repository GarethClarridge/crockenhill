@extends('layouts/page')

@section('dynamic_content')

  @can ('edit-sermons')
    <div class="d-grid gap-2 mb-3">
      <a href="/sermons" class="btn btn-primary btn-lg">Edit sermons</a>
    </div>
  @endcan
    <div class="d-grid gap-2 mb-3">
      <a href="/church/members/songs" class="btn btn-primary btn-lg">View song list</a>
    </div>
  @can ('edit-pages')
  <div class="d-grid gap-2 mb-3">
    <a href="/church/members/songs" class="btn btn-primary btn-lg">View song list</a>
  </div>
  @endcan
  @can ('edit-documents')
  <div class="d-grid gap-2 mb-3">
    <a href="/church/members/documents" class="btn btn-primary btn-lg">View documents</a>
  </div>
  @endcan

  <form action="logout" method="post">
    <br>
    <input type="hidden" name="_token" value="{{ csrf_token() }}">
    <div class="d-grid gap-2 mb-3">
      <button type="submit" name="logout" class="btn btn-primary btn-lg" role="button">Log out</button>
    </div>
  </form>

@stop
