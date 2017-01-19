@extends('page', [
    'heading' => 'Edit page',
    'description' => '<meta name="description" content="Edit this page">'
])

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
    <br><br><br>
      <article class="card">
        <div class="header-container">
          <h1><span>Edit "{{ $page->heading }}"</span></h1>
        </div>
        <div>
          @if (count($errors) > 0)
            <div class="alert alert-danger">
              <strong>Whoops!</strong> There were some problems:<br><br>
              <ul>
                @foreach ($errors->all() as $error)
                  <li>{{ $error }}</li>
                @endforeach
              </ul>
            </div>
          @endif

          <br>
          <form class="" action="/members/pages/{{$page->slug}}" method="post">
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">

            <div class="form-group">
              <label for="heading">Heading</label>
              <input class="form-control" id="heading" name="heading" type="text" value="{{$page->heading}}">
            </div>

            <div class="form-group">
              <label for="description">Description</label>
              <input class="form-control" id="description" name="description" type="text" value="{{$page->description}}">
            </div>

            <div class="form-group">
              <label for="area">Website area</label>
              <select class="form-control" name="area" value="{{$page->area}}">
                @if ($page->area == 'about-us')
                  <option value="about-us" selected>About us</option>
                @else
                  <option value="about-us">About us</option>
                @endif

                @if ($page->area == 'whats-on')
                  <option value="whats-on" selected>What's on</option>
                @else
                  <option value="whats-on">What's on</option>
                @endif

                @if ($page->area == 'find-us')
                  <option value="find-us" selected>Find us</option>
                @else
                  <option value="find-us">Find us</option>
                @endif

                @if ($page->area == 'contact-us')
                  <option value="contact-us" selected>Contact us</option>
                @else
                  <option value="contact-us">Contact us</option>
                @endif

                @if ($page->area == 'sermons')
                  <option value="sermons" selected>Sermons</option>
                @else
                  <option value="sermons">Sermons</option>
                @endif

                @if ($page->area == 'members')
                  <option value="members" selected>Members</option>
                @else
                  <option value="members">Members</option>
                @endif
              </select>

            </div>

            <div class="form-group">
              <label for="body">Content</label>
              <textarea class="form-control" name="body" rows="10" cols="50">{{trim($page->body)}}</textarea>
            </div>

            <div class="form-actions">
              <input class="btn btn-success" type="submit" value="Save">
              <a href="/members/pages/" class="btn btn-large">Cancel</a>
            </div>

          </form>

        </div>
      </article>
      <br><br>
    </div>
  </div>
</div>

@stop
