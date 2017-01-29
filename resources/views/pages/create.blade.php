@extends('page')

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
    <br><br><br>
      <article class="card">
        <form class="" action="/members/pages" method="post">
          <input type="hidden" name="_token" value="{{ csrf_token() }}">
          <div class="header-container">
            <h1>
              <span>
                <input class="edit-heading" id="heading" name="heading" type="text" placeholder="Enter a heading">
              </span>
            </h1>
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

            <div class="edit-metadata">
              <div class="row">
                <div class="form-group">
                  <div class="col-sm-2">
                    <label for="description">Description</label>
                  </div>
                  <div class="col-sm-10">
                    <input class="form-control" id="description" name="description" type="text" placeholder="Google uses what you type here">
                  </div>
                </div>
              </div>
              <div class="row">
                <div class="form-group">
                  <div class="col-sm-2">
                    <label for="area">Section</label>
                  </div>
                  <div class="col-sm-10">
                    <select class="form-control" name="area">
                        <option value="about-us">About us</option>
                        <option value="whats-on">What's on</option>
                        <option value="find-us">Find us</option>
                        <option value="contact-us">Contact us</option>
                        <option value="sermons">Sermons</option>
                        <option value="members">Members</option>
                    </select>
                  </div>
                </div>
              </div>
            </div>

            @include('includes.photoselector')

            <div class="row">
              <div class="col-sm-6">
                <div class="form-group">
                  <label for="markdown">Markdown content</label>
                  <textarea class="form-control" name="markdown" id="markdown-input" rows="20"></textarea>
                </div>
              </div>

              <div class="col-sm-6">
                <h4>
                  Rendered content
                </h4>
                <div id="rendered-content">

                </div>
              </div>
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
