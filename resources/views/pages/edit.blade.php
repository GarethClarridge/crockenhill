@extends('layouts/page')

@section('dynamic_content')

  <form class="" action="/church/members/pages/{{$page->slug}}" method="post" enctype="multipart/form-data">
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

      <div class="edit-metadata mt-3 row">
        <div class="col-8">
          <div class="mb-3">
            <label for="heading">Heading</label>
            <input class="form-control" id="heading" name="heading" type="text" value="{{$page->heading}}">
          </div>

          <div class="mb-3">
            <label for="description">Description <small>(returned on Google searches)</small></label>
            <input class="form-control" id="description" name="description" type="text" value="{{$page->description}}">
          </div>

          <div class="mb-3">
            <label for="area">Website section</label>
            <select class="form-control" name="area" value="{{$page->area}}">
              @if ($page->area == 'christ')
                <option value="christ" selected>Christ</option>
              @else
                <option value="christ">Christ</option>
              @endif

              @if ($page->area == 'church')
                <option value="church" selected>Church</option>
              @else
                <option value="church">Church</option>
              @endif

              @if ($page->area == 'community')
                <option value="community" selected>Community</option>
              @else
                <option value="community">Community</option>
              @endif
            </select>
          </div>
        </div>
        <div class="mb-3 col-4">
          @if (isset ($headingpicture) && file_exists($_SERVER['DOCUMENT_ROOT'] . $headingpicture))
            <div>
              Heading image
              <img src="{{$headingpicture}}" alt="{{$headingpicture}}" class="img-fluid" id="headingpicture">
            </div>
          @endif

          <div>
            <label class="form-label" for="heading-image">Upload a new heading image</label>
            <input name="heading-image" type="file" class="form-control form-control-lg" id="heading-image" onchange=file_changed() aria-describedby="heading-image">
          </div>
        </div>
      </div>

      <div class="row">


        <div class="col-6">
          <div class="mb-3">
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
        <div class="d-grid gap-2 mb-3">
          <input class="btn btn-success btn-lg" type="submit" value="Save">
        </div>
        <div class="text-center">
          <a href="/church/members/pages/" class="btn btn-outline btn-lg">Cancel</a>
        </div>
      </div>

      <!-- @include('includes.photo-selector') -->
    </div>
  </form>


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

  function file_changed(){
    var selectedFile = document.getElementById('heading-image').files[0];
    var img = document.getElementById('headingpicture')

    var reader = new FileReader();
    reader.onload = function(){
      img.src = this.result
    }
    reader.readAsDataURL(selectedFile);
  }
</script>

@stop
