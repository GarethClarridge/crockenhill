@extends('layouts/page')

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
    <br><br><br>
      <article class="card p-0">
        <div class="card-body">
          <h1 class="card-title">Create a new page</h1>
          <form class="mb-3" action="/church/members/pages" method="post">
            <input type="hidden" name="_token" value="{{ csrf_token() }}">

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
              <div class="mb-3">
                <label for="heading">Heading</label>
                <input class="form-control" id="heading" name="heading" type="text">
              </div>

              <div class="mb-3">
                <label for="description">Description <small>(returned on Google searches)</small></label>
                <input class="form-control" id="description" name="description" type="text">
              </div>

              <div class="mb-3">
                <label for="area">Website section</label>
                <select class="form-control" name="area">
                  <option value="christ">Christ</option>
                  <option value="church">Church</option>
                  <option value="community">Community</option>
                </select>
              </div>
            </div>

            <div class="row">
              <div class="col-6">
                <div class="mb-3">
                  <label for="markdown">Markdown content</label>
                  <textarea class="form-control" name="markdown" id="markdown-input" rows="20"></textarea>
                </div>
              </div>

              <div class="col-6">
                <h4>
                  Rendered content
                </h4>
                <div id="rendered-content">

                </div>
              </div>
            </div>

            <div class="form-actions">
              <div class="d-grid gap-2 mb-3">
                <input class="btn btn-success btn-large" type="submit" value="Save">
              </div>
              <div class="text-center">
                <a href="/church/members/pages/" class="btn btn-large text-center">Cancel</a>
              </div>
            </div>
          </form>

          @include('includes.photo-selector')

        </div>
      </article>
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
