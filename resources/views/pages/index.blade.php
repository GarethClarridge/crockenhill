@extends('page')

@section('dynamic_content')

  @if (session('message'))
  <div class="alert alert-success" role="alert">
    {{ session('message') }}
  </div>
  @endif

  @if ($user != null && $user->email == "admin@crockenhill.org")
    <a href="/page/create" class="btn btn-primary btn-lg btn-block" role="button">Create a new page</a>
  @endif

  <div>
    <table class="table table-hover">
      <thead>
        <tr>
          <th>Title</th>
          <th>Website Area</th>
          <th>Last Edited</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        @foreach ($pages as $page)
          <tr>
            <td>
              @if ($page->area == $page->slug)
                <a href="/{{$page->slug}}">
              @else
                <a href="/{{$page->area}}/{{$page->slug}}">
              @endif
                  {{ $page->heading }}
                </a>
            </td>
            <td>{{ $page->area }}</td>
            <td>{{ $page->updated_at }}</td>
            <td>
              <a href="/members/pages/{{$page->slug}}/edit" class="btn btn-success">Edit</a>
              <form class="form-inline" action="/members/pages/{{$page->slug}}" method="POST">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <button type="submit" class="btn btn-danger">
                  Delete
                </button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

@stop
