@extends('page')

@section('content')
<div class="container-fluid">
  <div class="row">
    <div class="col-md-8 col-md-offset-2">
      <article class="card mt-3">
        <div class="card-body">
          <h2 class="card-title">Edit '{{$page->heading}}' page</h2>
          <form class="" action="/church/members/pages/{{$page->slug}}" method="post">
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

              @include('includes.edit-page-metadata')

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
                  <a href="/church/members/pages/" class="btn btn-outline btn-large">Cancel</a>
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
