@extends('page')

@section('dynamic_content')

  <a href="/page/create" class="btn btn-primary btn-block" role="button">Create a new page</a>
  <br>
  <h2>Existing pages:</h2>

  <div class="table-responsive-sm">
    <table class="table table-hover">
      <thead>
        <tr>
          <th>Title</th>
          <th>Section</th>
          <th>Last edited</th>
          <th><span class="sr-only">Actions</span></th>
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
              <form class="form-inline" action="/church/members/pages/{{$page->slug}}" method="POST">
                <input type="hidden" name="_method" value="DELETE">
                <input type="hidden" name="_token" value="{{ csrf_token() }}">
                <div class="btn-group">
                  <a href="/church/members/pages/{{$page->slug}}/edit" class="btn btn-success">
                    Edit
                  </a>
                  <button type="submit" class="btn btn-danger">
                    Delete
                  </button>
                </div>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

@stop
