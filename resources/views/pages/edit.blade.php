@extends('page')

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
      <article class="card mt-3">
        <div class="card-body">
          <h2 class="card-title">Edit '{{$page->heading}}' page</h2>
          <form class="" action="/members/pages/{{$page->slug}}" method="post">
            <input type="hidden" name="_method" value="PUT">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">

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

              <div class="edit-metadata mt-3">
                <div class="form-group">
                  <label for="heading">Heading</label>
                  <input class="form-control" id="heading" name="heading" type="text" value="{{$page->heading}}">
                </div>

                <div class="form-group">
                  <label for="description">Description <small>(returned on Google searches)</small></label>
                  <input class="form-control" id="description" name="description" type="text" value="{{$page->description}}">
                </div>

                <div class="form-group">
                  <label for="area">Website section</label>
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
              </div>

              <div class="row">
                <div class="col-6">
                  <div class="form-group">
                    <label for="markdown" class="h4">Markdown content</label>
                    @if ($page->markdown)
                      <textarea class="form-control" name="markdown" id="markdown-input" rows="20">{{trim($page->markdown)}}</textarea>
                    @else
                      <textarea class="form-control" name="markdown" id="markdown-input" rows="20">{{trim($page->body)}}</textarea>
                    @endif
                  </div>
                </div>

                <div class="col-6">
                  <h4>
                    Rendered content
                  </h4>
                  <div id="rendered-content">
                    {!!$page->body!!}
                  </div>
                </div>
              </div>

              <div class="form-actions my-3">
                <input class="btn btn-success btn-lg btn-block" type="submit" value="Save">
                <div class="text-center">
                  <a href="/members/pages/" class="btn btn-outline btn-large">Cancel</a>
                </div>
              </div>

              @include('includes.photo-selector')

            </form>
          </div>
        </div>
      </article>
      <br><br>
    </div>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/showdown/1.6.0/showdown.min.js
" charset="utf-8"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.1.1/jquery.min.js" charset="utf-8"></script>
<script type="text/javascript">

  function markdownInit() {
    var markdown      = document.getElementById("markdown-input");
    var render        = document.getElementById("rendered-content");
    markdown.onfocus = function blankRender() {
      render.innerHTML = '';
    }
    markdown.onblur = function markdown() {
      var converter     = new showdown.Converter();
      var markdown      = document.getElementById("markdown-input");
      render.innerHTML  = converter.makeHtml(markdown.value);
    }
  }

  window.onload = markdownInit();
</script>

@stop
